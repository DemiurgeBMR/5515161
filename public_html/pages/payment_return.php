<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$paymentId = (int) ($_GET['payment'] ?? 0);

// Чужой платёж не показываем и не трогаем.
$stmt = $pdo->prepare("SELECT id FROM payments WHERE id = ? AND user_id = ?");
$stmt->execute([$paymentId, $_SESSION['user_id']]);
$payment = $stmt->fetch() ? rr_sync_payment($pdo, $paymentId) : null;

if (!$payment) {
    header('Location: /pages/subscription.php');
    exit;
}

$item = rr_payment_item($payment['kind'], $payment['item_key']);
$itemLabel = $item ? $item['label'] : 'Покупка';
$isDone = $payment['status'] === 'succeeded' && $payment['fulfilled_at'];
$isCanceled = $payment['status'] === 'canceled';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Оплата — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (!$isDone && !$isCanceled): ?>
        <meta http-equiv="refresh" content="5">
    <?php endif; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <h2><?php echo rr_icon('card'); ?> Оплата</h2>

        <?php if ($isDone): ?>
            <div class="flash-message">
                <?php echo rr_icon('check'); ?> <?php echo htmlspecialchars($itemLabel); ?> оплачен — покупка уже на вашем балансе.
            </div>
        <?php elseif ($isCanceled): ?>
            <div class="error" role="alert">
                Оплата не прошла или была отменена, деньги не списаны. Можно попробовать ещё раз.
            </div>
        <?php else: ?>
            <div class="flash-message">
                Ждём подтверждение оплаты от банка. Страница обновится сама — обычно это занимает несколько секунд.
            </div>
        <?php endif; ?>

        <p><a href="/pages/subscription.php" class="btn-submit">К тарифам и балансу</a></p>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
