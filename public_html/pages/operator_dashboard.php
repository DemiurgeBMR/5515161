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
    <title>Кабинет арендатора — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .dashboard-wrapper {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 30px;
        }
        .dashboard-sidebar {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
            align-self: start;
        }
        .dashboard-sidebar .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e94560;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            color: white;
            margin: 0 auto 12px;
        }
        .dashboard-sidebar .user-name {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .dashboard-sidebar .user-role {
            text-align: center;
            color: var(--text-muted, #9a9aa5);
            font-size: 14px;
            margin-bottom: 20px;
        }
        .dashboard-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .dashboard-nav a {
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text, #f2f2f5);
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-nav a:hover {
            background: var(--bg-elevated-2, #1c1c24);
        }
        .dashboard-nav a.active {
            background: #e94560;
            color: white;
        }
        .dashboard-nav a i {
            font-style: normal;
            width: 24px;
            text-align: center;
        }
        .dashboard-main {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }
        .dashboard-main h2 {
            margin-top: 0;
            margin-bottom: 16px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--bg-elevated-2, #1c1c24);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: var(--text, #f2f2f5);
        }
        .stat-card .label {
            font-size: 14px;
            color: var(--text-muted, #9a9aa5);
        }
        .stat-card-warning {
            background: rgba(231, 76, 60, 0.15);
        }
        .stat-card-warning .number {
            color: #ff6b6b;
        }
        .welcome-text {
            font-size: 18px;
            margin-bottom: 20px;
        }
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .quick-actions .btn {
            padding: 12px 24px;
            border-radius: 8px;
            background: #e94560;
            color: white;
            text-decoration: none;
            font-weight: bold;
        }
        .quick-actions .btn:hover {
            background: #c73652;
        }
        .quick-actions .btn-outline {
            background: transparent;
            color: #e94560;
            border: 2px solid #e94560;
        }
        .quick-actions .btn-outline:hover {
            background: #e94560;
            color: white;
        }

        /* ===== Ближайший визит ===== */
        .next-visit-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--bg-elevated-2, #1c1c24);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 30px;
        }
        .next-visit-icon {
            font-size: 28px;
            flex-shrink: 0;
        }
        .next-visit-body {
            flex: 1;
            min-width: 0;
        }
        .next-visit-title {
            font-weight: bold;
            color: var(--text, #f2f2f5);
        }
        .next-visit-sub {
            font-size: 13px;
            color: var(--text-muted, #9a9aa5);
            margin-top: 2px;
        }
        .next-visit-link {
            flex-shrink: 0;
            color: #e94560;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }
        .next-visit-link:hover {
            text-decoration: underline;
        }

        /* ===== "Требует внимания" ===== */
        .attention-heading {
            margin-top: 30px;
        }
        .attention-empty {
            background: rgba(46, 204, 113, 0.15);
            color: var(--text, #f2f2f5);
            border-radius: 12px;
            padding: 16px 20px;
        }
        .attention-groups {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .attention-group {
            background: var(--bg-elevated-2, #1c1c24);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            padding: 16px 20px;
        }
        .attention-group-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .attention-group-head a {
            font-size: 13px;
            font-weight: 600;
            color: #e94560;
            text-decoration: none;
        }
        .attention-group-head a:hover {
            text-decoration: underline;
        }
        .attention-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .attention-list a {
            color: var(--text-muted, #9a9aa5);
            text-decoration: none;
            font-size: 14px;
        }
        .attention-list a:hover {
            color: var(--text, #f2f2f5);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="dashboard-wrapper">
        <aside class="dashboard-sidebar">
            <div class="avatar"><?php echo mb_strtoupper(mb_substr($user_name, 0, 1, 'UTF-8')); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="user-role">🤝 Арендатор</div>

            <nav class="dashboard-nav">
                <a href="/pages/operator_dashboard.php" class="active">
                    <i>📊</i> Дашборд
                </a>
                <a href="/pages/operator_applications.php">
                    <i>📋</i> Мои заявки <span class="badge"><?php echo $bookings_count; ?></span>
                </a>
                <a href="/pages/operator_locations.php">
                    <i>📍</i> Мои точки <span class="badge"><?php echo $locations_count; ?></span>
                </a>
                <a href="/pages/events_calendar.php">
                    <i>📅</i> Выезды
                </a>
                <a href="/pages/documents.php">
                    <i>📄</i> Документы
                </a>
                <a href="/pages/edit_profile.php">
                    <i>⚙️</i> Настройки
                </a>
                <a href="/pages/logout.php" style="color: #e74c3c; margin-top: 12px;">
                    <i>🚪</i> Выйти
                </a>
            </nav>
        </aside>

        <main class="dashboard-main">
            <div class="welcome-text">
                👋 Добро пожаловать, <strong><?php echo htmlspecialchars($user_name); ?></strong>!
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?php echo $locations_count; ?></div>
                    <div class="label">Активных точек</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo number_format($total_rent, 0, ',', ' '); ?> ₽</div>
                    <div class="label">Аренда в месяц</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo $bookings_count; ?></div>
                    <div class="label">Активных заявок</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo $vending_count; ?></div>
                    <div class="label">Размещено вендингов</div>
                </div>
            </div>

            <div class="next-visit-card">
                <?php if ($nearest_visit): ?>
                    <div class="next-visit-icon">📅</div>
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
                    <div class="next-visit-icon">🗓️</div>
                    <div class="next-visit-body">
                        <div class="next-visit-title">Визитов не запланировано</div>
                        <div class="next-visit-sub">Подтверждённых выездов на ближайшее время нет.</div>
                    </div>
                    <a href="/pages/events_calendar.php" class="next-visit-link">Календарь →</a>
                <?php endif; ?>
            </div>

            <h3>🚀 Быстрые действия</h3>
            <div class="quick-actions">
                <a href="/pages/catalog.php" class="btn">🔍 Найти локации</a>
            </div>

            <h3 class="attention-heading">🔔 Требует внимания</h3>
            <?php if ($maintenance_due_count === 0 && $pending_visits_count === 0 && $unread_messages_count === 0): ?>
                <div class="attention-empty">✅ Всё под контролем — срочных дел нет.</div>
            <?php else: ?>
                <div class="attention-groups">
                    <?php if ($maintenance_due_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span>⚠️ Обслуживание (<?php echo $maintenance_due_count; ?>)</span>
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
                                <span>📅 Ждут подтверждения (<?php echo $pending_visits_count; ?>)</span>
                                <a href="/pages/events_calendar.php">Календарь →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach (array_slice($pending_visits_list, 0, 3) as $v): ?>
                                    <li>
                                        <a href="/pages/events_calendar.php">
                                            <?php echo htmlspecialchars($eventTypeLabels[$v['event_type']] ?? $v['event_type']); ?> —
                                            <?php echo htmlspecialchars($v['title'] . ', ' . $v['city']); ?>,
                                            <?php echo formatDateRu($v['proposed_datetime']); ?>
                                            <?php echo $v['is_emergency'] ? ' 🚨' : ''; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($unread_messages_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span>💬 Ждут ответа (<?php echo $unread_messages_count; ?>)</span>
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