<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

// Платит только оператор — собственнику эта страница не нужна (он не
// покупает доступ к карте/адресам чужих локаций через свой профиль), а
// админу подписка не нужна вовсе — у него и так полный доступ везде (см.
// config.php::currentUserHasSubscription()).
if ($_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/profile.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$plans = rr_subscription_plans();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /pages/subscription.php');
        exit;
    }

    $planKey = $_POST['plan'];
    $newEndDate = rr_purchase_subscription($pdo, $user_id, $planKey);
    if ($newEndDate === false) {
        $_SESSION['flash'] = 'Неизвестный тариф.';
    } else {
        $_SESSION['flash'] = 'Тариф «' . $plans[$planKey]['label'] . '» оформлен. Доступ действует до '
            . formatDateRu($newEndDate) . '.';
    }
    header('Location: /pages/subscription.php');
    exit;
}

$activeSubscription = rr_active_subscription($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подписка — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('card'); ?> Подписка</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="subscription-status-banner <?php echo $activeSubscription ? 'active' : 'inactive'; ?>">
            <?php if ($activeSubscription): ?>
                <?php echo rr_icon('check'); ?>
                Тариф «<?php echo htmlspecialchars($plans[$activeSubscription['plan']]['label'] ?? $activeSubscription['plan']); ?>» активен до
                <strong><?php echo formatDateRu($activeSubscription['end_date']); ?></strong>
            <?php else: ?>
                <?php echo rr_icon('x'); ?> Подписка не оформлена
            <?php endif; ?>
        </div>

        <p class="subscription-description">
            Пока без реальной оплаты — оформление тарифа сразу открывает доступ на выбранный срок, для
            проверки того, как меняется доступ к карте и карточкам локаций с подпиской.
        </p>

        <ul class="subscription-benefits">
            <li><?php echo rr_icon('map-pin'); ?> Интерактивная карта с точками локаций (без подписки — только список «город → сколько точек»)</li>
            <li><?php echo rr_icon('map-pin'); ?> Точный адрес локации на её карточке (без подписки — только город)</li>
            <li><?php echo rr_icon('mail'); ?> Отправка заявки собственнику</li>
        </ul>

        <div class="plan-cards">
            <?php foreach ($plans as $planKey => $plan): ?>
                <?php
                    $pricePerMonth = round($plan['price'] / $plan['months']);
                    $isBestValue = $planKey === 'yearly';
                ?>
                <div class="plan-card<?php echo $isBestValue ? ' best-value' : ''; ?>">
                    <?php if ($isBestValue): ?>
                        <div class="plan-card-badge">Выгоднее всего</div>
                    <?php endif; ?>
                    <div class="plan-card-label"><?php echo htmlspecialchars($plan['label']); ?></div>
                    <div class="plan-card-price"><?php echo number_format($plan['price'], 0, ',', ' '); ?> ₽</div>
                    <div class="plan-card-per-month">≈ <?php echo number_format($pricePerMonth, 0, ',', ' '); ?> ₽/мес</div>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="plan" value="<?php echo htmlspecialchars($planKey); ?>">
                        <button type="submit" class="btn-submit">
                            <?php echo $activeSubscription ? 'Продлить' : 'Оформить'; ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="subscription-note">
            Если на момент покупки у вас уже есть активный тариф, новый срок добавляется к оставшемуся —
            купленное время не сгорает.
        </p>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
