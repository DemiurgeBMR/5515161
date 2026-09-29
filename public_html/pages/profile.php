<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Если пользователь не авторизован — отправляем на вход
if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

// ★★★ Если админ — в админку ★★★
if ($_SESSION['user_role'] === 'admin') {
    header('Location: /admin/index.php');
    exit;
}

// ★★★ Если оператор — в его кабинет ★★★
if ($_SESSION['user_role'] === 'operator') {
    header('Location: /pages/operator_dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

// ★★★ ПОЛУЧАЕМ ФИЛЬТР ★★★
$filter = $_GET['filter'] ?? 'all'; // all, active, pending

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    // --- ОБРАБОТКА СМЕНЫ АВАТАРА ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_avatar'])) {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
            header('Location: /pages/profile.php');
            exit;
        }
        $color = $_POST['avatar_color'] ?? '';
        if (in_array($color, ALLOWED_AVATAR_COLORS, true)) {
            $stmt = $pdo->prepare("UPDATE users SET avatar_color = ? WHERE id = ?");
            $stmt->execute([$color, $user_id]);
            $_SESSION['avatar_color'] = $color;
            $_SESSION['flash'] = 'Аватар обновлён!';
        } else {
            $_SESSION['flash'] = 'Недопустимый цвет аватара.';
        }
        header('Location: /pages/profile.php');
        exit;
    }
    
    // Цвет аватара
    $avatar_color = $user['avatar_color'] ?? '#e94560';
    $first_letter = mb_strtoupper(mb_substr($user_name, 0, 1, 'UTF-8'), 'UTF-8');
    if (empty($first_letter) || $first_letter === '?') {
        $first_letter = 'U';
    }
    
    // ★★★ ПОЛУЧАЕМ ЛОКАЦИИ С УЧЁТОМ ФИЛЬТРА ★★★
    $my_locations = [];
    $total_locations = 0;
    $total_active = 0;
    $total_pending = 0;
    
if ($user_role === 'owner') {
    // Получаем все локации с количеством pending ревизий
    $stmt_all = $pdo->prepare("
        SELECT l.*,
               (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo,
               (SELECT COUNT(*) FROM location_revisions WHERE location_id = l.id AND status = 'pending') as pending_revisions_count,
               EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active') as is_occupied
        FROM locations l
        WHERE l.owner_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt_all->execute([$user_id]);
    $all_locations = $stmt_all->fetchAll();
    
    $total_locations = count($all_locations);
    foreach ($all_locations as $loc) {
        if ($loc['pending_revisions_count'] > 0) {
            $total_pending++;
        } elseif ($loc['is_active'] == 1 && $loc['is_moderated'] == 1) {
            $total_active++;
        }
    }
    
    // Фильтрация
    $my_locations = [];
    foreach ($all_locations as $loc) {
        if ($filter === 'active') {
            if ($loc['is_active'] == 1 && $loc['is_moderated'] == 1 && $loc['pending_revisions_count'] == 0) {
                $my_locations[] = $loc;
            }
        } elseif ($filter === 'pending') {
            if ($loc['pending_revisions_count'] > 0) {
                $my_locations[] = $loc;
            }
        } else {
            $my_locations[] = $loc;
        }
    }

    // ★★★ "Требует внимания" — та же логика, что и на operator_dashboard.php,
    // зеркально для собственника (свои локации вместо своих закреплений). ★★★
    $eventTypeLabels = [
        'installation' => 'Установка',
        'maintenance'  => 'Обслуживание',
        'restock'      => 'Пополнение',
        'repair'       => 'Ремонт',
        'removal'      => 'Демонтаж',
    ];

    // Точки, где вендинги давно не обслуживались.
    $cutoff = serviceDueCutoffDate();
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM location_machines m
        JOIN location_operators lo ON lo.id = m.location_operator_id
        WHERE lo.owner_id = ?
          AND lo.status = 'active'
          AND m.status != 'removed'
          AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
          AND COALESCE(m.last_service_at, m.installed_at) < ?
    ");
    $stmt->execute([$user_id, $cutoff]);
    $maintenance_due_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT l.title, l.city, COALESCE(m.last_service_at, m.installed_at) as last_touch
        FROM location_machines m
        JOIN location_operators lo ON lo.id = m.location_operator_id
        JOIN locations l ON l.id = lo.location_id
        WHERE lo.owner_id = ?
          AND lo.status = 'active'
          AND m.status != 'removed'
          AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
          AND COALESCE(m.last_service_at, m.installed_at) < ?
        ORDER BY last_touch ASC
        LIMIT 3
    ");
    $stmt->execute([$user_id, $cutoff]);
    $maintenance_due_list = $stmt->fetchAll();

    // Визиты, предложенные оператором и ждущие подтверждения собственника.
    $stmt = $pdo->prepare("
        SELECT e.event_type, e.proposed_datetime, e.is_emergency, l.title, l.city
        FROM installation_events e
        JOIN location_operators lo ON lo.id = e.location_operator_id
        JOIN locations l ON l.id = lo.location_id
        WHERE lo.owner_id = ?
          AND lo.status = 'active'
          AND e.status IN ('requested', 'reviewing')
          AND e.requested_by != ?
        ORDER BY e.proposed_datetime ASC
    ");
    $stmt->execute([$user_id, $user_id]);
    $pending_visits_list = $stmt->fetchAll();
    $pending_visits_count = count($pending_visits_list);

    // Подтверждённые, но ещё не закрытые визиты — без ограничения по дате,
    // остаются "требующими внимания" до самого закрытия (action=complete).
    $stmt = $pdo->prepare("
        SELECT e.event_type, COALESCE(e.confirmed_datetime, e.proposed_datetime) as visit_at, l.title, l.city
        FROM installation_events e
        JOIN location_operators lo ON lo.id = e.location_operator_id
        JOIN locations l ON l.id = lo.location_id
        WHERE lo.owner_id = ?
          AND lo.status = 'active'
          AND e.status = 'confirmed'
        ORDER BY visit_at ASC
    ");
    $stmt->execute([$user_id]);
    $open_visits_list = $stmt->fetchAll();
    $open_visits_count = count($open_visits_list);

    // Непрочитанные сообщения от операторов, сгруппированные по заявке.
    $stmt = $pdo->prepare("
        SELECT a.id as application_id, l.title, l.city, u.full_name as operator_name,
               COUNT(m.id) as unread_count, MAX(m.created_at) as last_at
        FROM messages m
        JOIN applications a ON a.id = m.application_id
        JOIN locations l ON l.id = a.location_id
        JOIN users u ON u.id = a.operator_id
        WHERE m.receiver_id = ? AND m.is_read = 0
        GROUP BY a.id, l.title, l.city, u.full_name
        ORDER BY last_at DESC
    ");
    $stmt->execute([$user_id]);
    $unread_threads = $stmt->fetchAll();
    $unread_messages_count = count($unread_threads);
}

} catch (PDOException $e) {
    error_log('profile.php: ' . $e->getMessage());
    $error = 'Ошибка загрузки профиля';
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="profile-wrapper">
        <!-- ===== ЛЕВАЯ КОЛОНКА ===== -->
        <aside class="profile-sidebar">
            <div class="avatar" style="background: <?php echo htmlspecialchars($avatar_color, ENT_QUOTES); ?>;" onclick="openModal()" title="Сменить цвет аватара">
                <?php echo $first_letter; ?>
                <span class="hint"><?php echo rr_icon('refresh'); ?> Сменить цвет</span>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role">
                    <?php if ($user_role === 'owner'): ?>
                        <span class="badge badge-owner"><?php echo rr_icon('building'); ?> Собственник</span>
                    <?php else: ?>
                        <span class="badge badge-operator"><?php echo rr_icon('check'); ?> Оператор</span>
                    <?php endif; ?>
                </div>
                <div class="user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            </div>

            <div class="stats-grid">
                <div class="stat-item">
                    <div class="number"><?php echo $total_locations ?? 0; ?></div>
                    <div class="label">Всего</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?php echo $total_active ?? 0; ?></div>
                    <div class="label">Активных</div>
                </div>
                <div class="stat-item">
                    <div class="number warning"><?php echo $total_pending ?? 0; ?></div>
                    <div class="label">На модерации</div>
                </div>
            </div>

<div class="profile-actions">
    <?php if ($user_role === 'owner'): ?>
        <a href="/pages/add_location.php" class="btn-action primary"><?php echo rr_icon('plus-circle'); ?> Добавить локацию</a>
    <?php else: ?>
        <a href="/pages/catalog.php" class="btn-action primary"><?php echo rr_icon('search'); ?> Найти локации</a>
    <?php endif; ?>
    <a href="/pages/edit_profile.php" class="btn-action secondary"><?php echo rr_icon('edit'); ?> Редактировать профиль</a>
    <a href="/pages/events_calendar.php" class="btn-action secondary"><?php echo rr_icon('calendar'); ?> Выезды</a>
    <a href="/pages/documents.php" class="btn-action secondary"><?php echo rr_icon('file-text'); ?> Документы</a>
    <a href="/pages/owner_applications.php" class="btn-action secondary"><?php echo rr_icon('mail'); ?> Заявки</a>
    <a href="/pages/owner_operators.php" class="btn-action secondary"><?php echo rr_icon('users'); ?> Мои операторы</a>
    <?php if ($user_role === 'operator'): ?>
        <a href="/pages/subscription.php" class="btn-action secondary"><?php echo rr_icon('card'); ?> Подписка</a>
    <?php endif; ?>
    <a href="/pages/logout.php" class="btn-action danger"><?php echo rr_icon('log-out'); ?> Выйти</a>
</div>
        </aside>

        <!-- ===== ОСНОВНАЯ ОБЛАСТЬ ===== -->
        <main class="profile-main">
            <?php if ($flash): ?>
                <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <?php if ($user_role === 'owner'): ?>
            <button type="button" class="attention-heading attention-toggle" id="attentionToggle" aria-expanded="true" aria-controls="attentionContent">
                <?php echo rr_icon('bell'); ?> Требует внимания
                <span class="attention-toggle-chevron" id="attentionToggleChevron"><?php echo rr_icon('chevron-down'); ?></span>
            </button>
            <div id="attentionContent">
            <?php if ($maintenance_due_count === 0 && $pending_visits_count === 0 && $open_visits_count === 0 && $unread_messages_count === 0): ?>
                <div class="attention-empty"><?php echo rr_icon('check'); ?> Всё под контролем — срочных дел нет.</div>
            <?php else: ?>
                <div class="attention-groups">
                    <?php if ($maintenance_due_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span><?php echo rr_icon('warning'); ?> Обслуживание (<?php echo $maintenance_due_count; ?>)</span>
                                <a href="/pages/owner_operators.php">Мои операторы →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach ($maintenance_due_list as $m): ?>
                                    <li>
                                        <a href="/pages/owner_operators.php">
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

                    <?php if ($open_visits_count > 0): ?>
                        <div class="attention-group">
                            <div class="attention-group-head">
                                <span><?php echo rr_icon('clock'); ?> Ожидают завершения (<?php echo $open_visits_count; ?>)</span>
                                <a href="/pages/events_calendar.php">Календарь →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach (array_slice($open_visits_list, 0, 3) as $v): ?>
                                    <li>
                                        <a href="/pages/events_calendar.php">
                                            <?php echo htmlspecialchars($eventTypeLabels[$v['event_type']] ?? $v['event_type']); ?> —
                                            <?php echo htmlspecialchars($v['title'] . ', ' . $v['city']); ?>,
                                            <?php echo formatDateRu($v['visit_at']); ?>
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
                                <a href="/pages/owner_applications.php">Все заявки →</a>
                            </div>
                            <ul class="attention-list">
                                <?php foreach (array_slice($unread_threads, 0, 3) as $t): ?>
                                    <li>
                                        <a href="/pages/application_chat.php?application_id=<?php echo $t['application_id']; ?>">
                                            <?php echo htmlspecialchars($t['operator_name']); ?> —
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
            </div>
            <?php endif; ?>

            <h2><?php echo rr_icon('list'); ?> Мои локации</h2>

            <!-- Вкладки -->
            <div class="tabs">
                <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Все</a>
                <a href="?filter=active" class="<?php echo $filter === 'active' ? 'active' : ''; ?>">Активные</a>
                <a href="?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">
                    На модерации
                    <?php if ($total_pending > 0): ?>
                        <span class="count"><?php echo $total_pending; ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <?php if (count($my_locations) > 0): ?>
                <div class="location-grid">
                    <?php foreach ($my_locations as $loc): ?>
                        <div class="location-card">
                            <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="location-link">
                                <div class="card-image">
                                    <?php if (!empty($loc['main_photo'])): ?>
                                        <img src="/<?php echo $loc['main_photo']; ?>" alt="<?php echo htmlspecialchars($loc['title']); ?>">
                                    <?php else: ?>
                                        <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="card-title"><?php echo htmlspecialchars($loc['title']); ?></div>
                                    <div class="card-address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                    <div class="card-price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ / мес</div>
<div class="card-status">
<?php if ($loc['pending_revisions_count'] > 0): ?>
    <!-- Здесь теперь проверяем, является ли локация новой -->
    <?php if (!$loc['is_moderated']): ?>
        <span class="status-badge status-pending"><?php echo rr_icon('clock'); ?> На модерации (новая)</span>
        <div class="status-hint">Объявление проверяется перед публикацией</div>
    <?php else: ?>
        <span class="status-badge status-pending-changes"><?php echo rr_icon('clock'); ?> Ожидает модерации (правки)</span>
        <div class="status-hint">Текущая версия активна до проверки</div>
    <?php endif; ?>
<?php elseif (!$loc['is_moderated']): ?>
    <!-- Сюда попадаем, если is_moderated=0 и ревизий нет (отозвано) -->
    <span class="status-badge status-hidden"><?php echo rr_icon('file-text'); ?> Отозвано (черновик)</span>
    <div class="status-hint">Вы отозвали правки, объявление не будет опубликовано</div>
<?php elseif ($loc['is_occupied']): ?>
    <span class="status-badge status-occupied"><?php echo rr_icon('lock'); ?> Занято оператором</span>
    <div class="status-hint">Скрыто из каталога — за локацией закреплён оператор</div>
<?php elseif ($loc['is_active']): ?>
    <span class="status-badge status-active"><?php echo rr_icon('check'); ?> Активно</span>
<?php else: ?>
    <span class="status-badge status-hidden"><?php echo rr_icon('ban'); ?> Скрыто</span>
<?php endif; ?>
</div>
                                </div>
                            </a>
<div class="card-actions">
    <?php if ($loc['pending_revisions_count'] > 0): ?>
        <a href="/pages/owner_actions.php?action=withdraw_and_edit&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-withdraw" data-rr-confirm="Отозвать правки и перейти к редактированию?" data-rr-confirm-ok="Отозвать"><?php echo rr_icon('edit'); ?> Отозвать и редактировать</a>
        <a href="/pages/owner_actions.php?action=delete&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-delete" data-rr-confirm="Удалить объявление?" data-rr-confirm-ok="Удалить" data-rr-confirm-danger><?php echo rr_icon('trash'); ?> Удалить</a>
    <?php elseif (!$loc['is_moderated']): ?>
        <a href="/pages/edit_location.php?id=<?php echo $loc['id']; ?>" class="btn-action small btn-edit"><?php echo rr_icon('edit'); ?> Редактировать</a>
        <a href="/pages/owner_actions.php?action=delete&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-delete" data-rr-confirm="Удалить объявление?" data-rr-confirm-ok="Удалить" data-rr-confirm-danger><?php echo rr_icon('trash'); ?> Удалить</a>
    <?php else: ?>
        <a href="/pages/edit_location.php?id=<?php echo $loc['id']; ?>" class="btn-action small btn-edit"><?php echo rr_icon('edit'); ?> Редактировать</a>
        <?php if ($loc['is_active'] == 1): ?>
            <a href="/pages/owner_actions.php?action=toggle&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-toggle" data-rr-confirm="Скрыть?" data-rr-confirm-ok="Скрыть"><?php echo rr_icon('eye-off'); ?> Скрыть</a>
        <?php else: ?>
            <a href="/pages/owner_actions.php?action=toggle&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-toggle" data-rr-confirm="Показать?" data-rr-confirm-ok="Показать"><?php echo rr_icon('eye'); ?> Показать</a>
        <?php endif; ?>
        <a href="/pages/owner_actions.php?action=delete&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-action small btn-delete" data-rr-confirm="Удалить безвозвратно?" data-rr-confirm-ok="Удалить" data-rr-confirm-danger><?php echo rr_icon('trash'); ?> Удалить</a>
    <?php endif; ?>
</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-locations">
                    <?php if ($filter === 'pending'): ?>
                        <p><?php echo rr_icon('check'); ?> Нет локаций на модерации</p>
                        <p><a href="?filter=all">Посмотреть все локации</a></p>
                    <?php else: ?>
                        <p>У вас пока нет добавленных локаций.</p>
                        <p><a href="/pages/add_location.php"><?php echo rr_icon('plus-circle'); ?> Добавить первую локацию</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- МОДАЛЬНОЕ ОКНО ДЛЯ ВЫБОРА ЦВЕТА -->
    <div class="modal-overlay" id="avatarModal">
        <div class="modal">
            <h3>Выберите цвет аватара</h3>
            <form method="POST" id="avatarForm">
                <?php echo csrf_field(); ?>
                <div class="color-grid">
                    <?php
                    foreach (ALLOWED_AVATAR_COLORS as $c):
                        $checked = ($c === $avatar_color) ? 'active' : '';
                    ?>
                        <div class="color-item <?php echo $checked; ?>"
                             style="background: <?php echo htmlspecialchars($c, ENT_QUOTES); ?>;"
                             data-color="<?php echo htmlspecialchars($c, ENT_QUOTES); ?>"
                             onclick="selectColor(this)"></div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="avatar_color" id="selectedColor" value="<?php echo htmlspecialchars($avatar_color, ENT_QUOTES); ?>">
                <div class="modal-actions">
                    <button type="button" class="btn-close" onclick="closeModal()">Отмена</button>
                    <button type="submit" name="change_avatar" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('avatarModal').classList.add('active');
            const currentColor = <?php echo json_encode($avatar_color, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            document.querySelectorAll('.color-item').forEach(el => {
                el.classList.toggle('active', el.dataset.color === currentColor);
            });
            document.getElementById('selectedColor').value = currentColor;
        }
        function closeModal() {
            document.getElementById('avatarModal').classList.remove('active');
        }
        function selectColor(el) {
            document.querySelectorAll('.color-item').forEach(item => item.classList.remove('active'));
            el.classList.add('active');
            document.getElementById('selectedColor').value = el.dataset.color;
        }
        document.getElementById('avatarModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // Сворачивание "Требует внимания" — состояние запоминаем в
        // localStorage, чтобы оно не сбрасывалось при каждом заходе на
        // страницу (сюда возвращаются регулярно, а не один раз за сессию).
        (function() {
            var toggle = document.getElementById('attentionToggle');
            var content = document.getElementById('attentionContent');
            var chevron = document.getElementById('attentionToggleChevron');
            if (!toggle || !content) return;

            var STORAGE_KEY = 'rr_attention_collapsed';

            function applyState(collapsed) {
                content.hidden = collapsed;
                toggle.classList.toggle('collapsed', collapsed);
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                if (chevron) chevron.style.transform = collapsed ? 'rotate(-90deg)' : '';
            }

            var collapsed = false;
            try {
                collapsed = localStorage.getItem(STORAGE_KEY) === '1';
            } catch (e) {}
            applyState(collapsed);

            toggle.addEventListener('click', function() {
                var newCollapsed = !content.hidden;
                applyState(newCollapsed);
                try {
                    localStorage.setItem(STORAGE_KEY, newCollapsed ? '1' : '0');
                } catch (e) {}
            });
        })();
    </script>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>