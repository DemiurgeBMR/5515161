<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

// ★★★ Изменённый запрос: добавлены поля operator_tag, owner_tag, status, cancelled_by ★★★
$stmt = $pdo->prepare("
    SELECT a.*, l.title as location_title, l.city, u.full_name as owner_name,
           a.operator_tag, a.owner_tag, a.status, a.cancelled_by
    FROM applications a
    JOIN locations l ON a.location_id = l.id
    JOIN users u ON a.owner_id = u.id
    WHERE a.operator_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll();

// Набор (location_id:operator_id), где закрепление всё ещё реально активно —
// 'approved' на самой заявке этого не гарантирует (см. ниже), а плашка
// "Закрепление подтверждено" навсегда после открепления вводит в заблуждение.
$stmt = $pdo->prepare("SELECT location_id, operator_id FROM location_operators WHERE operator_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$activeAssignmentPairs = [];
foreach ($stmt->fetchAll() as $row) {
    $activeAssignmentPairs[$row['location_id'] . ':' . $row['operator_id']] = true;
}

// Заявки на разовые услуги ("Сделка под ключ" и т.п.) — раньше их чат был
// виден только со страницы тарифов, из-за чего было легко забыть, что там
// вообще идёт переписка. Показываем здесь же, рядом с остальными чатами.
$stmt = $pdo->prepare("SELECT id, service, price, status, created_at FROM service_orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$serviceOrders = $stmt->fetchAll();
$serviceLabels = ['turnkey_deal' => 'Сделка под ключ'];
$orderStatusLabels = rr_service_order_status_labels();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$statusLabels = [
    'pending'    => rr_icon('clock') . ' Ожидает',
    'negotiating' => rr_icon('check') . ' В переговорах',
    'agreed'     => rr_icon('check') . ' Договорённость',
    'placed'     => rr_icon('map-pin') . ' Размещено',
    'cancelled'  => rr_icon('x') . ' Отменена',
    'approved'   => rr_icon('check') . ' Закрепление подтверждено',
    'rejected'   => rr_icon('x') . ' Закрепление отклонено',
    'assignment_ended' => rr_icon('x') . ' Закрепление снято',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои заявки — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-container-1000">
        <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('list'); ?> Мои заявки и чаты</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($serviceOrders): ?>
            <h3 class="subscription-section-title"><?php echo rr_icon('file-text'); ?> Заявки на услуги</h3>
            <div class="admin-table admin-table-spaced">
                <table>
                    <thead>
                        <tr>
                            <th>Услуга</th>
                            <th>Цена</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($serviceOrders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($serviceLabels[$order['service']] ?? $order['service']); ?></td>
                                <td><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</td>
                                <td>
                                    <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                                        <?php echo htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <a href="/pages/service_order_chat.php?order_id=<?php echo $order['id']; ?>" class="btn-view"><?php echo rr_icon('message-circle'); ?> Чат</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3 class="subscription-section-title"><?php echo rr_icon('list'); ?> Заявки на аренду</h3>
        <?php if (count($applications) > 0): ?>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Локация</th>
                            <th>Город</th>
                            <th>Собственник</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            // ★★★ Определяем, какой статус показывать этому оператору ★★★
                            // status хранит и финальные статусы запроса на закрепление
                            // (approved/rejected из api/operator_assign.php), которые
                            // нужно показывать напрямую — иначе такие заявки выглядели
                            // бы вечно "ожидающими".
                            if ($app['status'] === 'approved' && !isset($activeAssignmentPairs[$app['location_id'] . ':' . $app['operator_id']])) {
                                // Закрепление когда-то подтвердили, но с тех пор владелец
                                // открепил оператора (action=unassign) — заявка остаётся
                                // approved навсегда, но по факту это уже закрытая история.
                                $displayStatus = 'assignment_ended';
                            } elseif (in_array($app['status'], ['cancelled', 'approved', 'rejected'], true)) {
                                $displayStatus = $app['status'];
                            } else {
                                // Используем личный тег оператора, если есть, иначе 'pending'
                                $displayStatus = $app['operator_tag'] ? $app['operator_tag'] : 'pending';
                            }
                        ?>
                            <tr>
                                <td>#<?php echo $app['id']; ?></td>
                                <td>
                                    <a href="/pages/location.php?id=<?php echo $app['location_id']; ?>" class="accent-link no-underline">
                                        <?php echo htmlspecialchars($app['location_title']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($app['city']); ?></td>
                                <td><?php echo htmlspecialchars($app['owner_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $displayStatus; ?>">
                                        <?php echo $statusLabels[$displayStatus] ?? $displayStatus; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($app['created_at'])); ?></td>
                                <td>
                                    <a href="/pages/application_chat.php?application_id=<?php echo $app['id']; ?>" class="btn-view"><?php echo rr_icon('message-circle'); ?> Чат</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="page-intro">Вы ещё не отправляли заявки. <a href="/pages/catalog.php" class="accent-link">Найдите локации</a></p>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>