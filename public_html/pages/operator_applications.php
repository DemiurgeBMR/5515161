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
    'unassigned' => rr_icon('x') . ' Закрепление снято',
];

// ===== Телефон (≤768px): список-«мессенджер» вместо таблицы =====
// Фото локации, последнее сообщение, непрочитанные — отдельным запросом только для
// чтения, чтобы не трогать основную выборку таблицы (десктоп).
$mobileMeta = [];
$stmt = $pdo->prepare("
    SELECT a.id,
           (SELECT p.photo_path FROM location_photos p
             WHERE p.location_id = a.location_id AND p.is_main = 1 AND p.is_pending = 0 LIMIT 1) AS main_photo,
           (SELECT m.message FROM messages m WHERE m.application_id = a.id
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
           (SELECT m.sender_id FROM messages m WHERE m.application_id = a.id
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_sender,
           (SELECT MAX(m.created_at) FROM messages m WHERE m.application_id = a.id) AS last_at,
           (SELECT COUNT(*) FROM messages m
             WHERE m.application_id = a.id AND m.receiver_id = ? AND m.is_read = 0) AS unread
    FROM applications a
    WHERE a.operator_id = ?
");
$stmt->execute([$user_id, $user_id]);
foreach ($stmt->fetchAll() as $row) {
    $mobileMeta[$row['id']] = $row;
}

// Статус для карточки: подпись без иконки + тон цветной плашки (.m-pill)
$mStatus = [
    'pending'     => ['Ожидает', 'is-warning'],
    'negotiating' => ['В переговорах', 'is-info'],
    'agreed'      => ['Договорённость', ''],
    'placed'      => ['Размещено', ''],
    'cancelled'   => ['Отменена', 'is-muted'],
    'approved'    => ['Закрепление подтверждено', ''],
    'rejected'    => ['Закрепление отклонено', 'is-danger'],
    'unassigned'  => ['Закрепление снято', 'is-muted'],
];
$mOrderTone = ['new' => 'is-info', 'in_progress' => 'is-warning', 'done' => '', 'cancelled' => 'is-muted'];

if (!function_exists('rr_m_display_status')) {
    /** Тот же выбор статуса, что и в таблице ниже (финальные статусы — напрямую, иначе личный тег оператора). */
    function rr_m_display_status($app) {
        if (in_array($app['status'], ['cancelled', 'approved', 'rejected', 'unassigned'], true)) {
            return $app['status'];
        }
        return $app['operator_tag'] ? $app['operator_tag'] : 'pending';
    }
}
if (!function_exists('rr_m_short_time')) {
    /** «14:05» — сегодня, «вчера», «5 окт» — в этом году, иначе «05.10.2025». */
    function rr_m_short_time($dt) {
        $ts = strtotime($dt);
        if (!$ts) return '';
        if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'вчера';
        $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        if (date('Y', $ts) === date('Y')) return (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];
        return date('d.m.Y', $ts);
    }
}

// Карточки — по последней активности (как список чатов), фильтры-чипы — по статусам
$mApps = $applications;
usort($mApps, function ($a, $b) use ($mobileMeta) {
    $ta = strtotime($mobileMeta[$a['id']]['last_at'] ?? '') ?: strtotime($a['created_at']);
    $tb = strtotime($mobileMeta[$b['id']]['last_at'] ?? '') ?: strtotime($b['created_at']);
    return $tb <=> $ta;
});
$mStatusCounts = [];
$mUnreadApps = 0;
foreach ($mApps as $app) {
    $ds = rr_m_display_status($app);
    $mStatusCounts[$ds] = ($mStatusCounts[$ds] ?? 0) + 1;
    if ((int) ($mobileMeta[$app['id']]['unread'] ?? 0) > 0) $mUnreadApps++;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Мои заявки — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-pg-op-apps">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-container-1000 oa-page">
        <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('list'); ?> Мои заявки и чаты</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($serviceOrders): ?>
            <h3 class="subscription-section-title"><?php echo rr_icon('file-text'); ?> Заявки на услуги</h3>
            <ul class="m-only oa-list oa-orders">
                <?php foreach ($serviceOrders as $order): ?>
                    <li>
                        <a class="oa-card" href="/pages/service_order_chat.php?order_id=<?php echo $order['id']; ?>">
                            <span class="oa-thumb oa-thumb-ic"><?php echo rr_icon('file-text'); ?></span>
                            <span class="oa-main">
                                <span class="oa-top">
                                    <b class="oa-title"><?php echo htmlspecialchars($serviceLabels[$order['service']] ?? $order['service']); ?></b>
                                    <time class="oa-time" datetime="<?php echo htmlspecialchars($order['created_at']); ?>"><?php echo rr_m_short_time($order['created_at']); ?></time>
                                </span>
                                <span class="oa-sub"><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</span>
                                <span class="oa-status"><span class="m-pill <?php echo $mOrderTone[$order['status']] ?? 'is-muted'; ?>"><?php echo htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status']); ?></span></span>
                                <span class="oa-last"><span class="oa-last-text"><?php echo rr_icon('message-circle'); ?> Открыть чат по заявке</span></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="admin-table admin-table-spaced m-hide">
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
            <?php if (count($mStatusCounts) > 1 || $mUnreadApps > 0): ?>
                <div class="m-only m-chips oa-chips" role="toolbar" aria-label="Фильтр по статусу">
                    <button type="button" class="m-chip is-on" data-oa-filter="" aria-pressed="true">Все <span class="oa-chip-n"><?php echo count($mApps); ?></span></button>
                    <?php if ($mUnreadApps > 0): ?>
                        <button type="button" class="m-chip" data-oa-filter="unread" aria-pressed="false">Непрочитанные <span class="oa-chip-n"><?php echo $mUnreadApps; ?></span></button>
                    <?php endif; ?>
                    <?php foreach ($mStatus as $key => $st): if (empty($mStatusCounts[$key])) continue; ?>
                        <button type="button" class="m-chip" data-oa-filter="<?php echo $key; ?>" aria-pressed="false"><?php echo htmlspecialchars($st[0]); ?> <span class="oa-chip-n"><?php echo $mStatusCounts[$key]; ?></span></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <ul class="m-only oa-list" id="oaList">
                <?php foreach ($mApps as $app):
                    $ds = rr_m_display_status($app);
                    $meta = $mobileMeta[$app['id']] ?? [];
                    $unread = (int) ($meta['unread'] ?? 0);
                    $lastText = trim((string) ($meta['last_message'] ?? ''));
                    if (mb_strlen($lastText) > 140) $lastText = mb_substr($lastText, 0, 140) . '…';
                    $when = !empty($meta['last_at']) ? $meta['last_at'] : $app['created_at'];
                ?>
                    <li data-status="<?php echo htmlspecialchars($ds); ?>"<?php echo $unread > 0 ? ' data-unread="1"' : ''; ?>>
                        <a class="oa-card<?php echo $unread > 0 ? ' is-unread' : ''; ?>" href="/pages/application_chat.php?application_id=<?php echo $app['id']; ?>">
                            <?php if (!empty($meta['main_photo'])): ?>
                                <img class="oa-thumb" src="/<?php echo htmlspecialchars($meta['main_photo']); ?>" alt="" loading="lazy" width="64" height="64">
                            <?php else: ?>
                                <span class="oa-thumb oa-thumb-ic"><?php echo rr_icon('map-pin'); ?></span>
                            <?php endif; ?>
                            <span class="oa-main">
                                <span class="oa-top">
                                    <b class="oa-title"><?php echo htmlspecialchars($app['location_title']); ?></b>
                                    <time class="oa-time" datetime="<?php echo htmlspecialchars($when); ?>"><?php echo rr_m_short_time($when); ?></time>
                                </span>
                                <span class="oa-sub"><?php echo htmlspecialchars($app['city']); ?> · <?php echo htmlspecialchars($app['owner_name']); ?></span>
                                <span class="oa-status"><span class="m-pill <?php echo $mStatus[$ds][1] ?? 'is-muted'; ?>"><?php echo htmlspecialchars($mStatus[$ds][0] ?? $ds); ?></span></span>
                                <span class="oa-last">
                                    <span class="oa-last-text"><?php if ($lastText === ''): ?><span class="oa-muted">Сообщений пока нет — напишите собственнику</span><?php else: ?><?php echo (int) ($meta['last_sender'] ?? 0) === (int) $user_id ? '<span class="oa-you">Вы:</span> ' : ''; ?><?php echo htmlspecialchars($lastText); ?><?php endif; ?></span>
                                    <?php if ($unread > 0): ?><span class="oa-unread" aria-label="Непрочитанных: <?php echo $unread; ?>"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
                                </span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="m-only m-empty oa-filter-empty" id="oaFilterEmpty" hidden>
                <b>Здесь пусто</b>
                Заявок с таким статусом нет.
            </div>
            <div class="admin-table m-hide">
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
                            // (approved/rejected/unassigned из api/operator_assign.php),
                            // которые нужно показывать напрямую — иначе такие заявки
                            // выглядели бы вечно "ожидающими".
                            if (in_array($app['status'], ['cancelled', 'approved', 'rejected', 'unassigned'], true)) {
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
            <p class="page-intro m-hide">Вы ещё не отправляли заявки. <a href="/pages/catalog.php" class="accent-link">Найдите локации</a></p>
            <div class="m-only m-empty oa-empty">
                <?php echo rr_icon('message-circle'); ?>
                <b>Заявок пока нет</b>
                <p>Выберите локацию в каталоге и напишите собственнику — переписка по каждой заявке появится здесь.</p>
                <a href="/pages/catalog.php" class="m-btn"><?php echo rr_icon('search'); ?> Найти локации</a>
            </div>
        <?php endif; ?>
    </div>
    <script src="/assets/js/m/operator-applications.js"></script>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>