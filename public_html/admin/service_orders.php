<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

$statusLabels = rr_service_order_status_labels();

// Общение по заявке теперь идёт в отдельном чате (pages/service_order_chat.php)
// — здесь только быстрая смена статуса из списка, без открытия каждого
// заказа по отдельности.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /admin/service_orders.php');
        exit;
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        $_SESSION['flash'] = 'Некорректный запрос.';
        header('Location: /admin/service_orders.php');
        exit;
    }

    rr_update_service_order_status($pdo, $orderId, $_POST['status'] ?? '', $user_id);

    $_SESSION['flash'] = 'Заказ №' . $orderId . ' обновлён.';
    header('Location: /admin/service_orders.php?filter=' . urlencode($_GET['filter'] ?? 'all'));
    exit;
}

$filter = $_GET['filter'] ?? 'all';
if (!isset($statusLabels[$filter])) {
    $filter = 'all';
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT so.*, u.full_name, u.email, u.phone
    FROM service_orders so
    JOIN users u ON u.id = so.user_id
";
$countSql = "SELECT COUNT(*) FROM service_orders so JOIN users u ON u.id = so.user_id";

$where = [];
$params = [];
if ($filter !== 'all') {
    $where[] = 'so.status = ?';
    $params[] = $filter;
}
if ($where) {
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $sql .= $whereSql;
    $countSql .= $whereSql;
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

$sql .= " ORDER BY so.created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Заказы услуг — админка</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('file-text'); ?> Заказы услуг</h1>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

        <div class="filters adm-chips">
            <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Все</a>
            <?php foreach ($statusLabels as $key => $label): ?>
                <a href="?filter=<?php echo $key; ?>" class="<?php echo $filter === $key ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="admin-table">
            <table class="adm-cards adm-cards-order">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Клиент</th>
                        <th>Услуга</th>
                        <th>Цена</th>
                        <th>Статус / комментарий</th>
                        <th>Создан</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="7" class="c-empty"><span class="m-only"><?php echo rr_icon('file-text'); ?></span><b class="m-only">Заказов пока нет</b><span class="m-hide">Заказов пока нет.</span></td></tr>
                    <?php endif; ?>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td class="c-id" data-label="Заказ">#<?php echo $order['id']; ?></td>
                            <td class="c-client" data-label="Клиент">
                                <?php echo htmlspecialchars($order['full_name']); ?><br>
                                <a href="mailto:<?php echo htmlspecialchars($order['email']); ?>"><?php echo htmlspecialchars($order['email']); ?></a>
                                <?php if (!empty($order['phone'])): ?>
                                    <br><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $order['phone'])); ?>" class="m-only adm-tel"><?php echo htmlspecialchars($order['phone']); ?></a><span class="m-hide"><?php echo htmlspecialchars($order['phone']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="c-service" data-label="Услуга"><?php echo $order['service'] === 'turnkey_deal' ? 'Сделка под ключ' : htmlspecialchars($order['service']); ?></td>
                            <td class="c-price" data-label="Цена"><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</td>
                            <td class="c-badge" data-label="Статус">
                                <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                                    <?php echo htmlspecialchars($statusLabels[$order['status']] ?? $order['status']); ?>
                                </span>
                            </td>
                            <td class="c-date" data-label="Создан"><?php echo formatDate($order['created_at']); ?></td>
                            <td class="c-act" data-label="Действия">
                                <a href="/pages/service_order_chat.php?order_id=<?php echo $order['id']; ?>" class="btn-view act-main"><?php echo rr_icon('message-circle'); ?> Чат</a>
                                <form method="POST" class="admin-inline-order-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <span class="m-only adm-lbl">Статус заказа</span>
                                    <select name="status" onchange="this.form.submit()" aria-label="Статус заказа №<?php echo (int) $order['id']; ?>">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $order['status'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination adm-pager">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>">←</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>">→</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
