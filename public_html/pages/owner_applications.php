<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

// ★★★ Изменённый запрос: добавлены поля operator_tag, owner_tag, status, cancelled_by ★★★
$stmt = $pdo->prepare("
    SELECT a.*, l.title as location_title, l.city, u.full_name as operator_name,
           a.operator_tag, a.owner_tag, a.status, a.cancelled_by,
           u.avatar_color as operator_color,
           (SELECT m.message FROM messages m WHERE m.application_id = a.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) as last_message,
           (SELECT m.sender_id FROM messages m WHERE m.application_id = a.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) as last_sender_id,
           (SELECT MAX(m.created_at) FROM messages m WHERE m.application_id = a.id) as last_message_at,
           (SELECT COUNT(*) FROM messages m WHERE m.application_id = a.id AND m.receiver_id = a.owner_id AND m.is_read = 0) as unread_count
    FROM applications a
    JOIN locations l ON a.location_id = l.id
    JOIN users u ON a.operator_id = u.id
    WHERE a.owner_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll();

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

// Телефонная версия: в пустом состоянии — «Добавить локацию», если мест ещё нет, иначе — «Мои места»
$has_locations = false;
if (!$applications) {
    $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM locations WHERE owner_id = ?)");
    $stmt->execute([$user_id]);
    $has_locations = (bool) $stmt->fetchColumn();
}

// Время последней активности в карточке заявки (телефон): сегодня — «15:04», в этом году — «30 сен», иначе — дата
$rrShortTime = function ($dt) {
    $ts = strtotime((string) $dt);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'вчера';
    $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    if (date('Y', $ts) === date('Y')) return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];
    return date('d.m.Y', $ts);
};

$statusLabels = [
    'pending'    => rr_icon('clock') . ' Ожидает',
    'negotiating' => rr_icon('check') . ' В переговорах',
    'agreed'     => rr_icon('check') . ' Договорённость',
    'placed'     => rr_icon('map-pin') . ' Размещено',
    'cancelled'  => rr_icon('x') . ' Отменена',
    'approved'   => rr_icon('check') . ' Закрепление подтверждено',
    'rejected'   => rr_icon('x') . ' Закрепление отклонено',
    'unassigned' => rr_icon('x') . ' Закрепление снято',
];
// Телефон: короткие подписи длинных статусов (плашка и кнопка «Чат» — в одну строку)
$statusShortLabels = [
    'approved'   => rr_icon('check') . ' Закреплён',
    'rejected'   => rr_icon('x') . ' Отклонено',
    'unassigned' => rr_icon('x') . ' Откреплён',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Заявки на мои локации — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-owner-cab m-owner-apps">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-container-1000">
        <a href="/pages/profile.php" class="back-link m-hide">← Назад</a>
        <h2 class="oa-page-title"><?php echo rr_icon('mail'); ?> Заявки и чаты</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($serviceOrders): ?>
            <h3 class="subscription-section-title"><?php echo rr_icon('file-text'); ?> Заявки на услуги</h3>
            <div class="admin-table admin-table-spaced oa-orders">
                <table class="m-table-cards">
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
                                <td class="m-cell-title"><?php echo htmlspecialchars($serviceLabels[$order['service']] ?? $order['service']); ?></td>
                                <td data-label="Цена"><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</td>
                                <td data-label="Статус">
                                    <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                                        <?php echo htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status']); ?>
                                    </span>
                                </td>
                                <td data-label="Дата"><?php echo date('d.m.Y', strtotime($order['created_at'])); ?></td>
                                <td data-label="" class="oa-order-act">
                                    <a href="/pages/service_order_chat.php?order_id=<?php echo $order['id']; ?>" class="btn-view"><?php echo rr_icon('message-circle'); ?> Чат</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3 class="subscription-section-title oa-section-title"><?php echo rr_icon('mail'); ?> Заявки на мои локации</h3>
        <?php if (count($applications) > 0): ?>
            <?php // Телефон: строка таблицы — карточка-«диалог» (оператор, локация, последнее сообщение, статус, «Чат»); на десктопе — прежняя таблица. ?>
            <div class="admin-table oa-apps">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Локация</th>
                            <th>Город</th>
                            <th>Оператор</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            // ★★★ Определяем, какой статус показывать этому собственнику ★★★
                            // status хранит и финальные статусы запроса на закрепление
                            // (approved/rejected/unassigned из api/operator_assign.php),
                            // которые нужно показывать напрямую — иначе такие заявки
                            // выглядели бы вечно "ожидающими".
                            if (in_array($app['status'], ['cancelled', 'approved', 'rejected', 'unassigned'], true)) {
                                $displayStatus = $app['status'];
                            } else {
                                // Используем личный тег собственника, если есть, иначе 'pending'
                                $displayStatus = $app['owner_tag'] ? $app['owner_tag'] : 'pending';
                            }
                            $unread = (int) $app['unread_count'];
                            $opName = (string) $app['operator_name'];
                            $opInitial = mb_strtoupper(mb_substr($opName !== '' ? $opName : 'О', 0, 1, 'UTF-8'), 'UTF-8');
                            $opColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $app['operator_color']) ? $app['operator_color'] : '';
                            $lastText = trim(preg_replace('/\s+/u', ' ', (string) $app['last_message']));
                            if ($lastText === '') $lastText = trim(preg_replace('/\s+/u', ' ', (string) $app['initial_message']));
                            $lastMine = $app['last_message'] !== null && (int) $app['last_sender_id'] === (int) $user_id;
                        ?>
                            <tr class="oa-row<?php echo $unread > 0 ? ' is-unread' : ''; ?> oa-st-<?php echo htmlspecialchars($displayStatus); ?>">
                                <td class="m-only oa-av" aria-hidden="true"><span<?php echo $opColor ? ' style="background:' . htmlspecialchars($opColor, ENT_QUOTES) . '"' : ''; ?>><?php echo htmlspecialchars($opInitial); ?></span></td>
                                <td class="oa-id">#<?php echo $app['id']; ?></td>
                                <td class="oa-loc">
                                    <a href="/pages/location.php?id=<?php echo $app['location_id']; ?>" class="accent-link no-underline">
                                        <?php echo htmlspecialchars($app['location_title']); ?>
                                    </a>
                                </td>
                                <td class="oa-city"><?php echo htmlspecialchars($app['city']); ?></td>
                                <td class="oa-op"><?php echo htmlspecialchars($app['operator_name']); ?></td>
                                <td class="m-only oa-time"><?php echo htmlspecialchars($rrShortTime($app['last_message_at'] ?: $app['created_at'])); ?></td>
                                <td class="m-only oa-msg">
                                    <span class="oa-msg-text"><?php if ($lastMine): ?><b>Вы:</b> <?php endif; ?><?php echo htmlspecialchars($lastText !== '' ? $lastText : 'Сообщений пока нет'); ?></span>
                                    <?php if ($unread > 0): ?><span class="oa-unread" title="Непрочитанные сообщения"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
                                </td>
                                <td class="oa-st">
                                    <span class="status-badge status-<?php echo $displayStatus; ?>">
                                        <?php if (isset($statusShortLabels[$displayStatus])): ?><span class="m-hide"><?php echo $statusLabels[$displayStatus]; ?></span><span class="m-only"><?php echo $statusShortLabels[$displayStatus]; ?></span><?php else: ?><?php echo $statusLabels[$displayStatus] ?? $displayStatus; ?><?php endif; ?>
                                    </span>
                                </td>
                                <td class="oa-date"><?php echo date('d.m.Y', strtotime($app['created_at'])); ?></td>
                                <td class="oa-act">
                                    <a href="/pages/application_chat.php?application_id=<?php echo $app['id']; ?>" class="btn-view"><?php echo rr_icon('message-circle'); ?> Чат</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="m-only oa-empty-ic" aria-hidden="true"><?php echo rr_icon('message-circle'); ?></div>
            <p class="page-intro">Пока нет заявок на ваши локации.</p>
            <?php if ($has_locations): ?>
                <p class="m-only oa-empty-hint">Когда оператор заинтересуется вашей локацией и напишет вам, здесь появится заявка и чат с ним.</p>
                <a href="/pages/profile.php" class="m-only m-btn oa-empty-btn"><?php echo rr_icon('building'); ?> Мои места</a>
            <?php else: ?>
                <p class="m-only oa-empty-hint">Добавьте первое место — операторы найдут его в каталоге и напишут вам. Заявки и чаты появятся здесь.</p>
                <a href="/pages/add_location.php" class="m-only m-btn oa-empty-btn"><?php echo rr_icon('plus-circle'); ?> Добавить локацию</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>