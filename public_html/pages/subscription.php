<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Публичная страница тарифов — раньше требовала входа и роли "оператор",
// но подписка должна быть видна как обычная вкладка сайта (в том числе
// гостю, ещё не выбравшему роль при регистрации), а не спрятана внутри
// личного кабинета. Покупать тариф по-прежнему может только оператор —
// это проверяется отдельно, ниже, перед обработкой POST.
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? null;
$isOperator = $role === 'operator';

$pdo = getDbConnection();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$plans = rr_subscription_plans();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan'])) {
    if (!$isOperator) {
        // Кнопки оформления не показываются не-операторам — если сюда всё
        // же пришёл POST (прямым запросом в обход интерфейса), тихо
        // игнорируем вместо того, чтобы продавать подписку не той роли.
        header('Location: /pages/subscription.php');
        exit;
    }
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

$activeSubscription = $isOperator ? rr_active_subscription($pdo, $user_id) : null;
$backLink = $user_id ? rr_login_redirect_url($role) : '/';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подписка — RR</title>
    <meta name="description" content="Тарифы подписки RR для операторов вендинга: карта локаций с точками, точные адреса, отправка заявок собственникам.">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <a href="<?php echo htmlspecialchars($backLink); ?>" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('card'); ?> Подписка</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($isOperator): ?>
            <div class="subscription-status-banner <?php echo $activeSubscription ? 'active' : 'inactive'; ?>">
                <?php if ($activeSubscription): ?>
                    <?php echo rr_icon('check'); ?>
                    Тариф «<?php echo htmlspecialchars($plans[$activeSubscription['plan']]['label'] ?? $activeSubscription['plan']); ?>» активен до
                    <strong><?php echo formatDateRu($activeSubscription['end_date']); ?></strong>
                <?php else: ?>
                    <?php echo rr_icon('x'); ?> Подписка не оформлена
                <?php endif; ?>
            </div>
        <?php elseif ($role === 'owner'): ?>
            <div class="subscription-status-banner active">
                <?php echo rr_icon('check'); ?> Как собственнику, подписка вам не нужна — карта и точный адрес по вашим
                собственным локациям уже доступны без неё.
            </div>
        <?php elseif ($role === 'admin'): ?>
            <div class="subscription-status-banner active">
                <?php echo rr_icon('check'); ?> У администратора уже полный доступ ко всем функциям сайта.
            </div>
        <?php endif; ?>

        <p class="subscription-description">
            Подписка нужна оператору вендинга, который ищет площадку для размещения оборудования. Пока без
            реальной оплаты — оформление тарифа сразу открывает доступ на выбранный срок.
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

                    <?php if ($isOperator): ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="plan" value="<?php echo htmlspecialchars($planKey); ?>">
                            <button type="submit" class="btn-submit">
                                <?php echo $activeSubscription ? 'Продлить' : 'Оформить'; ?>
                            </button>
                        </form>
                    <?php elseif (!$user_id): ?>
                        <a href="/pages/login.php" class="btn-submit">Войти, чтобы оформить</a>
                    <?php else: ?>
                        <div class="plan-card-unavailable">Доступно только оператору</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($isOperator): ?>
            <p class="subscription-note">
                Если на момент покупки у вас уже есть активный тариф, новый срок добавляется к оставшемуся —
                купленное время не сгорает.
            </p>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
