<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

// Админу подписка не нужна — у него и так полный доступ везде (см.
// config.php::currentUserHasSubscription()).
if ($_SESSION['user_role'] === 'admin') {
    header('Location: /admin/index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_subscription'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /pages/subscription.php');
        exit;
    }

    $newValue = !empty($_SESSION['has_subscription']) ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE users SET has_subscription = ? WHERE id = ?");
    $stmt->execute([$newValue, $user_id]);
    $_SESSION['has_subscription'] = $newValue;
    $_SESSION['flash'] = $newValue
        ? 'Подписка включена. Теперь доступны карта с точками и точные адреса локаций.'
        : 'Подписка выключена.';
    header('Location: /pages/subscription.php');
    exit;
}

$isSubscribed = !empty($_SESSION['has_subscription']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подписка — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h2>💳 Подписка</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="subscription-card">
            <div class="subscription-status <?php echo $isSubscribed ? 'active' : 'inactive'; ?>">
                <?php echo $isSubscribed ? '✅ Подписка активна' : '⛔ Подписка не оформлена'; ?>
            </div>

            <p class="subscription-description">
                Демо-переключатель без реальной оплаты — для проверки, как меняется доступ
                к карте и карточкам локаций с подпиской и без неё.
            </p>

            <ul class="subscription-benefits">
                <li>🗺️ Интерактивная карта с точками локаций (без подписки — только список «город → сколько точек»)</li>
                <li>📍 Точный адрес локации на её карточке (без подписки — только город)</li>
            </ul>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="toggle_subscription" class="btn-submit<?php echo $isSubscribed ? ' btn-danger-outline' : ''; ?>">
                    <?php echo $isSubscribed ? 'Выключить подписку' : 'Включить подписку'; ?>
                </button>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
