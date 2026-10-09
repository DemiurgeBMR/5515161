<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();

function rr_dash_scalar(PDO $pdo, $sql) {
    return (float) $pdo->query($sql)->fetchColumn();
}

$usersTotal    = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM users");
$owners        = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'owner'");
$operators     = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'operator'");
$newUsers7     = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY");
$unverified    = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM users WHERE is_verified = 0");
$locActive     = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM locations WHERE is_active = 1 AND is_moderated = 1");
$locPending    = (int) rr_dash_scalar($pdo, "SELECT COUNT(DISTINCT location_id) FROM location_revisions WHERE status = 'pending'");
$ordersNew     = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM service_orders WHERE status = 'new'");
$activeSubs    = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM subscriptions WHERE is_active = 1 AND end_date > NOW()");
$revenueAll    = rr_dash_scalar($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'succeeded'");
$revenue30     = rr_dash_scalar($pdo, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'succeeded' AND paid_at >= NOW() - INTERVAL 30 DAY");
$paymentsStuck = (int) rr_dash_scalar($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'succeeded' AND fulfilled_at IS NULL");

$money = function ($v) { return number_format($v, 0, ',', ' ') . ' ₽'; };
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Сводка — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('home'); ?> Сводка</h1>
        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

        <?php if (!rr_payments_enabled()): ?>
            <div class="flash-message"><?php echo rr_icon('warning'); ?> Оплата на сайте выключена: не заданы ключи ЮKassa (YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY в .env на сервере). Покупатели видят «оплата недоступна».</div>
        <?php endif; ?>
        <?php if ($paymentsStuck > 0): ?>
            <div class="flash-message"><?php echo rr_icon('warning'); ?> Есть оплаченные платежи (<?php echo $paymentsStuck; ?>), по которым покупка не начислена, — посмотрите раздел «Платежи».</div>
        <?php endif; ?>

        <h2>Деньги</h2>
        <div class="admin-stats">
            <div class="stat-box"><div class="number"><?php echo $money($revenue30); ?></div><div class="label">Оплаты за 30 дней</div></div>
            <div class="stat-box"><div class="number"><?php echo $money($revenueAll); ?></div><div class="label">Оплаты за всё время</div></div>
            <div class="stat-box"><div class="number"><?php echo $activeSubs; ?></div><div class="label">Активных тарифов</div></div>
            <div class="stat-box <?php echo $ordersNew ? 'pending' : ''; ?>"><div class="number"><?php echo $ordersNew; ?></div><div class="label">Новых заявок на услуги</div></div>
        </div>

        <h2>Пользователи</h2>
        <div class="admin-stats">
            <div class="stat-box"><div class="number"><?php echo $usersTotal; ?></div><div class="label">Всего</div></div>
            <div class="stat-box"><div class="number"><?php echo $owners; ?> / <?php echo $operators; ?></div><div class="label">Собственники / операторы</div></div>
            <div class="stat-box"><div class="number"><?php echo $newUsers7; ?></div><div class="label">Новых за 7 дней</div></div>
            <div class="stat-box"><div class="number"><?php echo $unverified; ?></div><div class="label">Не подтвердили email</div></div>
        </div>

        <h2>Локации</h2>
        <div class="admin-stats">
            <div class="stat-box"><div class="number"><?php echo $locActive; ?></div><div class="label">Активных</div></div>
            <div class="stat-box <?php echo $locPending ? 'pending' : ''; ?>"><div class="number"><?php echo $locPending; ?></div><div class="label">Ждут модерации</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
