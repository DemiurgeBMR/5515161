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

$statusLabels = [
    'new'         => 'Новая',
    'in_progress' => 'В обработке',
    'done'        => 'Выполнена',
    'cancelled'   => 'Отменена',
];

// Готовые сообщения клиенту по умолчанию — админ может переопределить своим
// текстом в поле "Комментарий", но заявка не должна оставаться немой, даже
// если админ просто сменил статус, не написав ничего.
$statusDefaultMessages = [
    'in_progress' => 'Ваша заявка «Сделка под ключ» взята в работу.',
    'done'        => 'Ваша заявка «Сделка под ключ» выполнена.',
    'cancelled'   => 'Заявка «Сделка под ключ» отменена.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /admin/service_orders.php');
        exit;
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($orderId <= 0 || !isset($statusLabels[$newStatus])) {
        $_SESSION['flash'] = 'Некорректный запрос.';
        header('Location: /admin/service_orders.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT user_id, status FROM service_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $_SESSION['flash'] = 'Заказ не найден.';
        header('Location: /admin/service_orders.php');
        exit;
    }

    $pdo->prepare("UPDATE service_orders SET status = ?, note = ? WHERE id = ?")
        ->execute([$newStatus, $note !== '' ? $note : null, $orderId]);

    // Заказчик до этого не видел вообще никакого движения по заявке — теперь
    // при любой смене статуса он получает уведомление (плюс комментарий
    // админа, если тот его оставил), это и есть обратная связь с командой,
    // раз полноценный чат для разовой ручной услуги избыточен.
    if ($newStatus !== $order['status']) {
        $message = $statusDefaultMessages[$newStatus] ?? ('Статус заявки «Сделка под ключ» изменён: ' . $statusLabels[$newStatus]);
        if ($note !== '') {
            $message .= ' Комментарий команды: ' . $note;
        }
        notify($pdo, $order['user_id'], 'service_order_update', $message, '/pages/subscription.php');
    } elseif ($note !== '') {
        notify($pdo, $order['user_id'], 'service_order_update', 'Комментарий команды по заявке «Сделка под ключ»: ' . $note, '/pages/subscription.php');
    }

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        <div class="nav-admin">
            <a href="/admin/index.php"><?php echo rr_icon('list'); ?> На модерацию</a>
            <a href="/admin/locations.php"><?php echo rr_icon('map-pin'); ?> Все локации</a>
            <a href="/admin/users.php"><?php echo rr_icon('users'); ?> Пользователи</a>
            <a href="/admin/geocode_backfill.php"><?php echo rr_icon('globe'); ?> Геокодирование</a>
            <a href="/admin/fix_main_photos.php"><?php echo rr_icon('camera'); ?> Починка фото</a>
            <a href="/admin/service_orders.php"><?php echo rr_icon('file-text'); ?> Заказы услуг</a>
        </div>

        <div class="filters">
            <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Все</a>
            <?php foreach ($statusLabels as $key => $label): ?>
                <a href="?filter=<?php echo $key; ?>" class="<?php echo $filter === $key ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="admin-table">
            <table>
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
                        <tr><td colspan="7">Заказов пока нет.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>#<?php echo $order['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($order['full_name']); ?><br>
                                <a href="mailto:<?php echo htmlspecialchars($order['email']); ?>"><?php echo htmlspecialchars($order['email']); ?></a>
                                <?php if (!empty($order['phone'])): ?>
                                    <br><?php echo htmlspecialchars($order['phone']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $order['service'] === 'turnkey_deal' ? 'Сделка под ключ' : htmlspecialchars($order['service']); ?></td>
                            <td><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</td>
                            <td>
                                <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                                    <?php echo htmlspecialchars($statusLabels[$order['status']] ?? $order['status']); ?>
                                </span>
                                <?php if (!empty($order['note'])): ?>
                                    <div class="order-note-preview"><?php echo nl2br(htmlspecialchars($order['note'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDate($order['created_at']); ?></td>
                            <td>
                                <form method="POST" class="admin-inline-order-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <select name="status">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $order['status'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="note" placeholder="Комментарий клиенту (необязательно)" value="<?php echo htmlspecialchars($order['note'] ?? ''); ?>">
                                    <button type="submit" class="btn-approve">Сохранить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
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
