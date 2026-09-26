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

// Активные заявки (не завершённые и не отменённые) — сразу с деталями для
// попапа по клику на карточку статистики "Активных заявок" на дашборде.
// 'approved' — терминальный статус самой заявки и не значит, что закрепление
// всё ещё живо (см. api/operator_assign.php, action=unassign — переводит
// саму location_operators в inactive, статус заявки не трогает). Без
// дополнительной проверки открепление оператора не убирало заявку из
// "активных" на дашборде, хотя по факту закрепления уже нет.
$stmt = $pdo->prepare("
    SELECT a.id, l.title, l.city, u.full_name as owner_name,
           a.status, a.operator_tag, a.created_at
    FROM applications a
    JOIN locations l ON l.id = a.location_id
    JOIN users u ON u.id = a.owner_id
    WHERE a.operator_id = ? AND a.status NOT IN ('cancelled', 'placed')
      AND (a.status != 'approved' OR EXISTS (
          SELECT 1 FROM location_operators lo
          WHERE lo.location_id = a.location_id AND lo.operator_id = a.operator_id AND lo.status = 'active'
      ))
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$active_applications = $stmt->fetchAll();
$bookings_count = count($active_applications);

$applicationStatusLabels = [
    'pending'     => 'Ожидает',
    'negotiating' => 'В переговорах',
    'agreed'      => 'Договорённость',
    'approved'    => 'Закрепление подтверждено',
    'rejected'    => 'Закрепление отклонено',
];

// Закреплённые точки — тоже с деталями сразу: та же выборка кормит и карточку
// "Активных точек", и "Аренда в месяц" (одни и те же локации, просто разный
// акцент), чтобы не считать это дважды двумя разными запросами.
$stmt = $pdo->prepare("
    SELECT lo.id as lo_id, l.title, l.city, l.price_month,
           (SELECT COUNT(*) FROM location_machines m WHERE m.location_operator_id = lo.id AND m.status != 'removed') as machines_count
    FROM location_operators lo
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ? AND lo.status = 'active'
    ORDER BY l.city, l.title
");
$stmt->execute([$user_id]);
$active_locations = $stmt->fetchAll();
$locations_count = count($active_locations);

// Размещённые вендинги — с деталями для попапа "Размещено вендингов".
// due считается тем же порогом SERVICE_DUE_DAYS/serviceDueCutoffDate(), что
// и "Требует внимания" ниже на этой же странице — единая точка правды.
$cutoffForList = serviceDueCutoffDate();
$stmt = $pdo->prepare("
    SELECT lo.id as lo_id, l.title, l.city, m.machine_type, m.model,
           m.installed_at, m.last_service_at,
           (COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
               AND COALESCE(m.last_service_at, m.installed_at) < ?) as is_due
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    JOIN locations l ON l.id = lo.location_id
    WHERE lo.operator_id = ? AND lo.status = 'active' AND m.status != 'removed'
    ORDER BY l.city, l.title
");
$stmt->execute([$cutoffForList, $user_id]);
$active_machines = $stmt->fetchAll();
$vending_count = count($active_machines);

$machineTypeLabelsShort = [
    'snacks' => 'Снеки',
    'drinks' => 'Напитки',
    'coffee' => 'Кофе',
    'combo'  => 'Комбо',
    'other'  => 'Другое',
];

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

// Общая аренда в месяц по всем активным точкам — считаем прямо из уже
// загруженного $active_locations, а не отдельным запросом на ту же выборку.
$total_rent = (float) array_sum(array_column($active_locations, 'price_month'));

// Баланс контактов — показываем сразу на дашборде (первое, что видит
// новый оператор после регистрации), а не только на странице тарифов.
$creditsSummary = rr_credits_summary($pdo, $user_id);

// Непрочитанные сообщения от собственников, сгруппированные по заявке — это
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

// Визиты, предложенные собственником и ждущие подтверждения оператора —
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
                <a href="/pages/subscription.php">
                    <i><?php echo rr_icon('card'); ?></i> Подписка
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

            <div class="credits-card <?php echo $creditsSummary['total_available'] > 0 ? '' : 'empty'; ?>">
                <div class="credits-card-icon"><?php echo rr_icon('unlock'); ?></div>
                <div class="credits-card-body">
                    <?php if ($creditsSummary['total_available'] > 0): ?>
                        <div class="credits-card-title">
                            <?php echo $creditsSummary['total_available']; ?>
                            <?php echo rr_plural_ru($creditsSummary['total_available'], 'контакт', 'контакта', 'контактов'); ?> доступно
                        </div>
                        <div class="credits-card-sub">
                            Контакт тратится один раз на локацию — открывает точный адрес и связь с собственником навсегда.
                            <?php if ($creditsSummary['monthly_remaining'] > 0): ?>
                                Из них <?php echo $creditsSummary['monthly_remaining']; ?> — из тарифа «<?php echo htmlspecialchars($creditsSummary['plan_label']); ?>» (сгорают в конце периода).
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="credits-card-title">Контакты закончились</div>
                        <div class="credits-card-sub">Купите пакет или тариф, чтобы открывать точный адрес и контакт собственника новых локаций.</div>
                    <?php endif; ?>
                </div>
                <a href="/pages/subscription.php" class="credits-card-link">
                    <?php echo $creditsSummary['total_available'] > 0 ? 'Смотреть тарифы' : 'Пополнить'; ?> →
                </a>
            </div>

            <div class="dash-stats-grid">
                <div class="dash-stat-card clickable" onclick="openModal('locationsModal')" role="button" tabindex="0" onkeydown="if(event.key==='Enter')openModal('locationsModal')">
                    <div class="number"><?php echo $locations_count; ?></div>
                    <div class="label">Активных точек</div>
                </div>
                <div class="dash-stat-card clickable" onclick="openModal('locationsModal')" role="button" tabindex="0" onkeydown="if(event.key==='Enter')openModal('locationsModal')">
                    <div class="number"><?php echo number_format($total_rent, 0, ',', ' '); ?> ₽</div>
                    <div class="label">Аренда в месяц</div>
                </div>
                <div class="dash-stat-card clickable" onclick="openModal('applicationsModal')" role="button" tabindex="0" onkeydown="if(event.key==='Enter')openModal('applicationsModal')">
                    <div class="number"><?php echo $bookings_count; ?></div>
                    <div class="label">Активных заявок</div>
                </div>
                <div class="dash-stat-card clickable" onclick="openModal('machinesModal')" role="button" tabindex="0" onkeydown="if(event.key==='Enter')openModal('machinesModal')">
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

    <!-- ===== Попап: активные точки / аренда в месяц (одни и те же данные) ===== -->
    <div class="modal-overlay" id="locationsModal">
        <div class="modal-box">
            <button class="close-btn" onclick="closeModal('locationsModal')" aria-label="Закрыть">&times;</button>
            <h3><?php echo rr_icon('map-pin'); ?> Мои активные точки</h3>
            <?php if ($active_locations): ?>
                <table>
                    <thead>
                        <tr><th>Локация</th><th>Город</th><th>Аренда/мес</th><th>Вендингов</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_locations as $loc): ?>
                            <tr>
                                <td><a href="/pages/operator_locations.php#ol-loc-<?php echo $loc['lo_id']; ?>"><?php echo htmlspecialchars($loc['title']); ?></a></td>
                                <td><?php echo htmlspecialchars($loc['city']); ?></td>
                                <td><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽</td>
                                <td><?php echo (int) $loc['machines_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>Итого</strong></td>
                            <td><strong><?php echo number_format($total_rent, 0, ',', ' '); ?> ₽</strong></td>
                            <td><strong><?php echo $vending_count; ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <p class="page-intro">Пока нет закреплённых точек. <a href="/pages/catalog.php" class="accent-link">Найдите локацию</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== Попап: активные заявки ===== -->
    <div class="modal-overlay" id="applicationsModal">
        <div class="modal-box">
            <button class="close-btn" onclick="closeModal('applicationsModal')" aria-label="Закрыть">&times;</button>
            <h3><?php echo rr_icon('list'); ?> Активные заявки</h3>
            <?php if ($active_applications): ?>
                <table>
                    <thead>
                        <tr><th>Локация</th><th>Собственник</th><th>Статус</th><th>Дата</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_applications as $app):
                            $appDisplayStatus = in_array($app['status'], ['approved', 'rejected'], true)
                                ? $app['status']
                                : ($app['operator_tag'] ?: 'pending');
                        ?>
                            <tr>
                                <td><a href="/pages/application_chat.php?application_id=<?php echo $app['id']; ?>"><?php echo htmlspecialchars($app['title']); ?>, <?php echo htmlspecialchars($app['city']); ?></a></td>
                                <td><?php echo htmlspecialchars($app['owner_name']); ?></td>
                                <td><?php echo htmlspecialchars($applicationStatusLabels[$appDisplayStatus] ?? $appDisplayStatus); ?></td>
                                <td><?php echo date('d.m.Y', strtotime($app['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="page-intro">Активных заявок нет. <a href="/pages/catalog.php" class="accent-link">Найдите локации</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== Попап: размещённые вендинги ===== -->
    <div class="modal-overlay" id="machinesModal">
        <div class="modal-box">
            <button class="close-btn" onclick="closeModal('machinesModal')" aria-label="Закрыть">&times;</button>
            <h3><?php echo rr_icon('wrench'); ?> Размещённые вендинги</h3>
            <?php if ($active_machines): ?>
                <table>
                    <thead>
                        <tr><th>Локация</th><th>Тип</th><th>Модель</th><th>Обслуживание</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_machines as $m): ?>
                            <tr>
                                <td><a href="/pages/operator_locations.php#ol-loc-<?php echo $m['lo_id']; ?>"><?php echo htmlspecialchars($m['title']); ?>, <?php echo htmlspecialchars($m['city']); ?></a></td>
                                <td><?php echo htmlspecialchars($machineTypeLabelsShort[$m['machine_type']] ?? $m['machine_type']); ?></td>
                                <td><?php echo htmlspecialchars($m['model'] ?: '—'); ?></td>
                                <td>
                                    <?php if ($m['is_due']): ?>
                                        <span class="service-badge service-due"><?php echo rr_icon('warning'); ?> Требует обслуживания</span>
                                    <?php else: ?>
                                        <span class="service-badge service-ok"><?php echo rr_icon('check'); ?> В порядке</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="page-intro">Вендинги пока не указаны. <a href="/pages/operator_locations.php" class="accent-link">Укажите на странице точек</a></p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(function (overlay) {
                    closeModal(overlay.id);
                });
            }
        });
    </script>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>