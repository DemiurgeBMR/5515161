<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для операторов
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$user_name = $_SESSION['user_name'];
$user_id = $_SESSION['user_id'];


$pdo = getDbConnection();

// Количество активных заявок (не завершённых и не отменённых)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE operator_id = ? AND status NOT IN ('cancelled', 'placed')");
$stmt->execute([$user_id]);
$bookings_count = $stmt->fetchColumn();

// Количество закреплённых точек (для бейджа в меню)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM location_operators WHERE operator_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$locations_count = $stmt->fetchColumn();

// Количество размещённых вендингов (реально заведённых карточек машин, не статус чата)
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    WHERE lo.operator_id = ? AND lo.status = 'active' AND m.status != 'removed'
");
$stmt->execute([$user_id]);
$vending_count = $stmt->fetchColumn();

// Количество точек, требующих обслуживания
// (последнее обслуживание либо установка были раньше порога SERVICE_DUE_DAYS)
$cutoff = serviceDueCutoffDate();
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    WHERE lo.operator_id = ?
      AND lo.status = 'active'
      AND m.status != 'removed'
      AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
      AND COALESCE(m.last_service_at, m.installed_at) < ?
");
$stmt->execute([$user_id, $cutoff]);
$maintenance_due_count = $stmt->fetchColumn();

// Топ-3 самых просроченных по обслуживанию точек — для списка "Требует внимания"
$stmt = $pdo->prepare("
    SELECT l.title, l.city, COALESCE(m.last_service_at, m.installed_at) as last_touch
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ?
      AND lo.status = 'active'
      AND m.status != 'removed'
      AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
      AND COALESCE(m.last_service_at, m.installed_at) < ?
    ORDER BY last_touch ASC
    LIMIT 3
");
$stmt->execute([$user_id, $cutoff]);
$maintenance_due_list = $stmt->fetchAll();

// Общая аренда в месяц по всем активным точкам
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(l.price_month), 0)
    FROM location_operators lo
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ? AND lo.status = 'active'
");
$stmt->execute([$user_id]);
$total_rent = (float) $stmt->fetchColumn();

// Непрочитанные сообщения от владельцев, сгруппированные по заявке — это
// и карточка "Ждут ответа", и список конкретных диалогов для to-do.
$stmt = $pdo->prepare("
    SELECT a.id as application_id, l.title, l.city, u.full_name as owner_name,
           COUNT(m.id) as unread_count, MAX(m.created_at) as last_at
    FROM messages m
    JOIN applications a ON a.id = m.application_id
    JOIN locations l ON l.id = a.location_id
    JOIN users u ON u.id = a.owner_id
    WHERE m.receiver_id = ? AND m.is_read = 0
    GROUP BY a.id, l.title, l.city, u.full_name
    ORDER BY last_at DESC
");
$stmt->execute([$user_id]);
$unread_threads = $stmt->fetchAll();
$unread_messages_count = count($unread_threads);

// Ближайший подтверждённый визит/обслуживание
$stmt = $pdo->prepare("
    SELECT e.event_type, COALESCE(e.confirmed_datetime, e.proposed_datetime) as visit_at,
           l.title, l.city
    FROM installation_events e
    JOIN location_operators lo ON lo.id = e.location_operator_id
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ?
      AND lo.status = 'active'
      AND e.status = 'confirmed'
      AND COALESCE(e.confirmed_datetime, e.proposed_datetime) >= NOW()
    ORDER BY visit_at ASC
    LIMIT 1
");
$stmt->execute([$user_id]);
$nearest_visit = $stmt->fetch();

// Визиты, предложенные владельцем и ждущие подтверждения оператора —
// подтвердить своё же предложение нельзя (см. api/installation.php action=confirm),
// поэтому в "требует внимания" попадают только чужие, ещё не подтверждённые.
$stmt = $pdo->prepare("
    SELECT e.event_type, e.proposed_datetime, e.is_emergency, l.title, l.city
    FROM installation_events e
    JOIN location_operators lo ON lo.id = e.location_operator_id
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ?
      AND lo.status = 'active'
      AND e.status IN ('requested', 'reviewing')
      AND e.requested_by != ?
    ORDER BY e.proposed_datetime ASC
");
$stmt->execute([$user_id, $user_id]);
$pending_visits_list = $stmt->fetchAll();
$pending_visits_count = count($pending_visits_list);

$eventTypeLabels = [
    'installation' => 'Установка',
    'maintenance'  => 'Обслуживание',
    'restock'      => 'Пополнение',
    'repair'       => 'Ремонт',
    'removal'      => 'Демонтаж',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Кабинет арендатора — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="dashboard-wrapper">
        <aside class="dashboard-sidebar">
            <div class="avatar"><?php echo mb_strtoupper(mb_substr($user_name, 0, 1, 'UTF-8')); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="user-role"><?php echo rr_icon('check'); ?> Арендатор</div>

            <nav class="dashboard-nav">
                <a href="/pages/operator_dashboard.php" class="active">
                    <i><?php echo rr_icon('layout-dashboard'); ?></i> Дашборд
                </a>
                <a href="/pages/operator_applications.php">
                    <i><?php echo rr_icon('list'); ?></i> Мои заявки <span class="badge"><?php echo $bookings_count; ?></span>
                </a>
                <a href="/pages/operator_locations.php">
                    <i><?php echo rr_icon('map-pin'); ?></i> Мои точки <span class="badge"><?php echo $locations_count; ?></span>
                </a>
                <a href="/pages/events_calendar.php">
                    <i><?php echo rr_icon('calendar'); ?></i> Выезды
                </a>
                <a href="/pages/documents.php">
                    <i><?php echo rr_icon('file-text'); ?></i> Документы
                </a>
                <a href="/pages/edit_profile.php">
                    <i><?php echo rr_icon('settings'); ?></i> Настройки
                </a>
                <a href="/pages/logout.php" class="logout-link">
                    <i><?php echo rr_icon('log-out'); ?></i> Выйти
                </a>
            </nav>
        </aside>

        <main class="dashboard-main">
            <div class="welcome-text">
                Добро пожаловать, <strong><?php echo htmlspecialchars($user_name); ?></strong>!
            </div>

            <div class="dash-stats-grid">
                <div class="dash-stat-card">
                    <div class="number"><?php echo $locations_count; ?></div>
                    <div class="label">Активных точек</div>
                </div>
                <div class="dash-stat-card">
                    <div class="number"><?php echo number_format($total_rent, 0, ',', ' '); ?> ₽</div>
                    <div class="label">Аренда в месяц</div>
                </div>
                <div class="dash-stat-card">
                    <div class="number"><?php echo $bookings_count; ?></div>
                    <div class="label">Активных заявок</div>
                </div>
                <div class="dash-stat-card">
                    <div class="number"><?php echo $vending_count; ?></div>
                    <div class="label">Размещено вендингов</div>
                </div>
            </div>

            <div class="next-visit-card">
                <?php if ($nearest_visit): ?>
                    <div class="next-visit-icon"><?php echo rr_icon('calendar'); ?></div>
                    <div class="next-visit-body">
                        <div class="next-visit-title">
                            Ближайший визит — <?php echo $eventTypeLabels[$nearest_visit['event_type']] ?? $nearest_visit['event_type']; ?>
                        </div>
                        <div class="next-visit-sub">
                            <?php echo htmlspecialchars($nearest_visit['title'] . ', ' . $nearest_visit['city']); ?>
                            · <?php echo formatDateRu($nearest_visit['visit_at']); ?>, <?php echo date('H:i', strtotime($nearest_visit['visit_at'])); ?>
                        </div>
                    </div>
                    <a href="/pages/events_calendar.php" class="next-visit-link">Календарь →</a>
                <?php else: ?>
                    <div class="next-visit-icon"><?php echo rr_icon('calendar'); ?></div>
                    <div class="next-visit-body">
                        <div class="next-visit-title">Визитов не запланировано</div>
                        <div class="next-visit-sub">Подтверждённых выездов на ближайшее время нет.</div>
                    </div>
                    <a href="/pages/events_calendar.php" class="next-visit-link">Календарь →</a>
                <?php endif; ?>
            </div>

            <h3><?php echo rr_icon('bolt'); ?> Быстрые действия</h3>
            <div class="quick-actions">
                <a href="/pages/catalog.php" class="btn"><?php echo rr_icon('search'); ?> Найти локации</a>
            </div>

            <h3 class="attention-heading"><?php echo rr_icon('bell'); ?> Требует внимания</h3>
            <?php if ($maintenance_due_count === 0 && $pending_visits_count === 0 && $unread_messages_count === 0): ?>
                <div class="attention-empty"><?php echo rr_icon('check'); ?> Всё под контролем — срочных дел нет.</div>
            <?php else: ?>
                <div class="attention-groups">
                    <?php if ($maintenance_due_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span><?php echo rr_icon('warning'); ?> Обслуживание (<?php echo $maintenance_due_count; ?>)</span>
                                <a href="/pages/operator_locations.php">Все точки →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach ($maintenance_due_list as $m): ?>
                                    <li>
                                        <a href="/pages/operator_locations.php">
                                            <?php echo htmlspecialchars($m['title'] . ', ' . $m['city']); ?>
                                            — не обслуживалась с <?php echo formatDateRu($m['last_touch']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($pending_visits_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span><?php echo rr_icon('calendar'); ?> Ждут подтверждения (<?php echo $pending_visits_count; ?>)</span>
                                <a href="/pages/events_calendar.php">Календарь →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach (array_slice($pending_visits_list, 0, 3) as $v): ?>
                                    <li>
                                        <a href="/pages/events_calendar.php">
                                            <?php echo htmlspecialchars($eventTypeLabels[$v['event_type']] ?? $v['event_type']); ?> —
                                            <?php echo htmlspecialchars($v['title'] . ', ' . $v['city']); ?>,
                                            <?php echo formatDateRu($v['proposed_datetime']); ?>
                                            <?php echo $v['is_emergency'] ? ' ' . rr_icon('warning') : ''; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($unread_messages_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span><?php echo rr_icon('message-circle'); ?> Ждут ответа (<?php echo $unread_messages_count; ?>)</span>
                                <a href="/pages/operator_applications.php">Все заявки →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach (array_slice($unread_threads, 0, 3) as $t): ?>
                                    <li>
                                        <a href="/pages/application_chat.php?application_id=<?php echo $t['application_id']; ?>">
                                            <?php echo htmlspecialchars($t['owner_name']); ?> —
                                            <?php echo htmlspecialchars($t['title']); ?>
                                            (<?php echo $t['unread_count']; ?> <?php echo $t['unread_count'] == 1 ? 'сообщение' : 'сообщения'; ?>)
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>