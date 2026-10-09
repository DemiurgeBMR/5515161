<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();

$statusLabels = ['pending' => 'Ждёт оплаты', 'succeeded' => 'Оплачен', 'canceled' => 'Отменён'];
$filter = $_GET['status'] ?? '';
if (!isset($statusLabels[$filter])) {
    $filter = '';
}

// Ручная досинхронизация зависшего платежа: если вебхук потерялся, а покупатель
// не вернулся на страницу после оплаты, статус можно перепроверить здесь.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    } else {
        $synced = rr_sync_payment($pdo, (int) ($_POST['payment_id'] ?? 0));
        $_SESSION['flash'] = $synced
            ? 'Платёж №' . $synced['id'] . ' перепроверен: ' . $statusLabels[$synced['status']] . '.'
            : 'Платёж не найден.';
    }
    header('Location: /admin/payments.php' . ($filter ? '?status=' . $filter : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$sql = "SELECT p.*, u.full_name, u.email FROM payments p LEFT JOIN users u ON u.id = p.user_id";
$params = [];
if ($filter) {
    $sql .= " WHERE p.status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY p.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Платежи — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('card'); ?> Платежи</h1>
        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="nav-admin">
            <a href="/admin/payments.php">Все</a>
            <?php foreach ($statusLabels as $key => $label): ?>
                <a href="/admin/payments.php?status=<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($rows): ?>
            <div class="admin-table">
                <table class="adm-cards">
                    <thead>
                        <tr><th>№</th><th>Дата</th><th>Покупатель</th><th>Что куплено</th><th>Сумма</th><th>Статус</th><th>Начислено</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $p): ?>
                            <?php $item = rr_payment_item($p['kind'], $p['item_key']); ?>
                            <tr>
                                <td class="c-id" data-label="№"><?php echo (int) $p['id']; ?></td>
                                <td data-label="Дата"><?php echo htmlspecialchars(formatDateRu($p['created_at'])); ?></td>
                                <td class="c-title" data-label="Покупатель"><?php echo htmlspecialchars($p['full_name'] ?? ('#' . $p['user_id'])); ?><br><small><?php echo htmlspecialchars($p['email'] ?? ''); ?></small></td>
                                <td data-label="Что куплено"><?php echo htmlspecialchars($item ? $item['label'] : $p['item_key']); ?></td>
                                <td class="c-price" data-label="Сумма"><?php echo number_format($p['amount'], 0, ',', ' '); ?> ₽</td>
                                <td data-label="Статус"><?php echo htmlspecialchars($statusLabels[$p['status']]); ?></td>
                                <td data-label="Начислено"><?php echo $p['fulfilled_at'] ? 'да' : ($p['status'] === 'succeeded' ? 'НЕТ' : '—'); ?></td>
                                <td class="actions">
                                    <?php if ($p['status'] === 'pending' || ($p['status'] === 'succeeded' && !$p['fulfilled_at'])): ?>
                                        <form method="POST">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo (int) $p['id']; ?>">
                                            <button type="submit" class="btn-view">Перепроверить</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-pending">
                <h3>Платежей пока нет</h3>
                <p>Здесь появятся покупки пакетов и тарифов, когда будет подключена оплата.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
