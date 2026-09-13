<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$application_id = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
if ($application_id <= 0) {
    header('Location: /pages/catalog.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.*, l.title as location_title, 
           op.full_name as operator_name, ow.full_name as owner_name,
           op.id as operator_id, ow.id as owner_id,
           a.operator_tag, a.owner_tag, a.status, a.cancelled_by
    FROM applications a
    JOIN locations l ON a.location_id = l.id
    JOIN users op ON a.operator_id = op.id
    JOIN users ow ON a.owner_id = ow.id
    WHERE a.id = ? AND (a.operator_id = ? OR a.owner_id = ?)
");
$stmt->execute([$application_id, $user_id, $user_id]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: /pages/catalog.php');
    exit;
}

$backUrl = ($user_id == $application['operator_id']) ? '/pages/operator_applications.php' : '/pages/owner_applications.php';

if ($user_id == $application['operator_id']) {
    $notifications_enabled = $application['operator_notifications_enabled'];
    $my_tag = $application['operator_tag'];
} else {
    $notifications_enabled = $application['owner_notifications_enabled'];
    $my_tag = $application['owner_tag'];
}
$notifications_enabled = (bool)$notifications_enabled;

$stmt = $pdo->prepare("SELECT * FROM messages WHERE application_id = ? ORDER BY created_at ASC");
$stmt->execute([$application_id]);
$messages = $stmt->fetchAll();
$stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE application_id = ? AND receiver_id = ? AND is_read = 0");
$stmt->execute([$application_id, $user_id]);
notify_mark_link_read($pdo, $user_id, '/pages/application_chat.php?application_id=' . $application_id);
$is_operator = ($user_id == $application['operator_id']);
$other_party = $is_operator ? $application['owner_name'] : $application['operator_name'];

// Запрос на закрепление — это отметка на уже открытой заявке/чате
// (api/operator_assign.php, action=request ставит applications.assignment_requested=1),
// а не отдельная заявка. Оператор решает сам, когда его отправить, прямо
// из переписки; владельцу здесь же показываются кнопки "Одобрить"/"Отклонить",
// пока запрос не рассмотрен.
$isAssignmentRequest = !empty($application['assignment_requested']);
$canDecideAssignment = $isAssignmentRequest && $application['status'] === 'pending' && !$is_operator;
$canRequestAssignment = $is_operator && $application['status'] === 'pending' && !$isAssignmentRequest;

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

function formatDateSeparator($dateStr) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($dateStr === $today) return 'Сегодня';
    if ($dateStr === $yesterday) return 'Вчера';
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $ts = strtotime($dateStr);
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

$statusLabels = [
    'pending' => '⏳ Ожидает',
    'negotiating' => '🤝 В переговорах',
    'agreed' => '✅ Договорённость',
    'placed' => '📍 Размещено',
    'cancelled' => '❌ Отменена',
    'approved' => '✅ Закрепление подтверждено',
    'rejected' => '❌ Закрепление отклонено',
];

$currentPublicStatus = $application['status'];
$cancelled_by = $application['cancelled_by'];
$canChangeCancel = ($currentPublicStatus === 'cancelled' && $cancelled_by == $user_id);

// status хранит и финальные статусы запроса на закрепление (approved/
// rejected из api/operator_assign.php) — показываем их напрямую, как и
// cancelled, а не только личный тег ($my_tag), иначе решённый запрос
// выглядел бы вечно "ожидающим" (см. A19 в owner/operator_applications.php).
$displayStatus = in_array($currentPublicStatus, ['cancelled', 'approved', 'rejected'], true)
    ? $currentPublicStatus
    : ($my_tag ? $my_tag : 'pending');

// === Получаем активное событие выезда ===
$stmt = $pdo->prepare("
    SELECT e.*, u.full_name as requested_by_name
    FROM installation_events e
    JOIN users u ON e.requested_by = u.id
    WHERE e.application_id = ? AND e.status NOT IN ('completed','cancelled')
    ORDER BY e.created_at DESC LIMIT 1
");
$stmt->execute([$application_id]);
$current_event = $stmt->fetch();

// Планировать выезд можно только когда за локацией реально закреплён этот
// оператор (api/installation.php резолвит location_operator_id именно по
// этой паре) — без этого запрос всегда будет отклонён, поэтому скрываем
// кнопку и объясняем, чего не хватает, вместо непонятной ошибки при клике.
$stmt = $pdo->prepare("
    SELECT id FROM location_operators
    WHERE location_id = ? AND operator_id = ? AND status = 'active'
");
$stmt->execute([$application['location_id'], $application['operator_id']]);
$hasActiveAssignment = (bool)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Чат по заявке — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="chat-container">
    <a href="<?php echo $backUrl; ?>" class="chat-back-link">← Назад к списку заявок</a>

    <div class="chat-card" id="chatCard">

        <!-- ================= ОСНОВНАЯ КОЛОНКА: ТРЕД ================= -->
        <div class="chat-main">

            <!-- компактная шапка — видна только на мобилке -->
            <div class="chat-main-topbar">
                <div class="who">
                    <div class="party-avatar"><?php echo htmlspecialchars(getInitials($other_party)); ?></div>
                    <div class="name"><?php echo htmlspecialchars($other_party); ?></div>
                </div>
                <button class="details-toggle-btn" id="detailsToggleBtn">
                    <span class="status-dot dot-<?php echo $displayStatus; ?>" id="detailsToggleDot"></span>
                    Детали
                </button>
            </div>

            <!-- СООБЩЕНИЯ -->
            <div class="chat-messages" id="chatMessages">
                <?php if (count($messages) > 0): ?>
                    <?php $prevSenderId = null; $prevDate = null; ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php
                            $isOwn = ($msg['sender_id'] == $user_id);
                            $isOperatorMsg = ($msg['sender_id'] == $application['operator_id']);
                            $senderFullName = $isOperatorMsg ? $application['operator_name'] : $application['owner_name'];
                            $senderLabel = $senderFullName . ($isOperatorMsg ? ' (Оператор)' : ' (Владелец)');
                            $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                            $showDateSeparator = ($msgDate !== $prevDate);
                            $isGrouped = (!$showDateSeparator && $msg['sender_id'] == $prevSenderId);
                            $prevSenderId = $msg['sender_id'];
                            $prevDate = $msgDate;
                        ?>
                        <?php if ($showDateSeparator): ?>
                            <div class="date-separator"><span><?php echo formatDateSeparator($msgDate); ?></span></div>
                        <?php endif; ?>
                        <div class="message <?php echo $isOwn ? 'own' : ''; ?> <?php echo $isGrouped ? 'grouped' : ''; ?>">
                            <?php if (!$isOwn): ?>
                                <div class="avatar" style="<?php echo $isGrouped ? 'visibility:hidden;' : ''; ?>"><?php echo htmlspecialchars(getInitials($senderFullName)); ?></div>
                            <?php endif; ?>
                            <div class="message-body">
                                <?php if (!$isGrouped): ?>
                                    <div class="sender">
                                        <?php echo htmlspecialchars($senderLabel); ?>
                                        <span class="time"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                <?php if ($isOwn): ?>
                                    <div class="msg-meta">
                                        <?php if ($isGrouped): ?>
                                            <span class="time"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                                        <?php endif; ?>
                                        <span class="read-receipt <?php echo $msg['is_read'] ? 'read' : ''; ?>" title="<?php echo $msg['is_read'] ? 'Прочитано' : 'Отправлено'; ?>"><?php echo $msg['is_read'] ? '✓✓' : '✓'; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="chat-empty"><span class="chat-empty-icon">💬</span>Сообщений пока нет. Начните переписку первым.</div>
                <?php endif; ?>
            </div>

            <!-- зарезервировано под индикатор "печатает..." (следующий шаг) -->
            <div class="typing-indicator" id="typingIndicator">
                <span id="typingIndicatorText"></span>
            </div>

            <!-- ИНПУТ -->
            <div class="chat-input">
                <?php if ($currentPublicStatus !== 'cancelled'): ?>
                    <form>
                        <textarea name="message" placeholder="Напишите сообщение..." rows="1" required></textarea>
                        <button type="submit" aria-label="Отправить">➤</button>
                    </form>
                <?php else: ?>
                    <div class="chat-closed">Чат закрыт. Заявка отменена.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ================= БЭКДРОП ДЛЯ МОБИЛЬНОГО DRAWER ================= -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- ================= БОКОВАЯ ПАНЕЛЬ: КОНТЕКСТ ЗАЯВКИ ================= -->
        <div class="chat-sidebar" id="chatSidebar">

            <!-- заголовок панели, только на мобилке -->
            <div class="sidebar-header">
                <span class="sidebar-header-title">Детали заявки</span>
                <button class="sidebar-close-btn" id="sidebarCloseBtn">×</button>
            </div>

            <!-- профиль собеседника -->
            <div class="sidebar-section">
                <div class="sidebar-profile">
                    <div class="party-avatar"><?php echo htmlspecialchars(getInitials($other_party)); ?></div>
                    <div class="info">
                        <div class="name"><?php echo htmlspecialchars($other_party); ?></div>
                        <div class="meta">
                            Заявка №<?php echo $application['id']; ?> ·
                            <a href="/pages/location.php?id=<?php echo $application['location_id']; ?>">
                                <?php echo htmlspecialchars($application['location_title']); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canDecideAssignment): ?>
            <!-- запрос на закрепление за локацией — решение владельца -->
            <div class="sidebar-section" id="assignmentRequestBlock">
                <p class="sidebar-section-title">Запрос на закрепление</p>
                <p style="font-size:13px; color:var(--text-muted, #888); margin-bottom:10px;">
                    Оператор просит закрепить его за этой локацией.
                </p>
                <div class="modal-buttons">
                    <button type="button" id="approveAssignmentBtn" class="chat-btn-primary">✅ Одобрить</button>
                    <button type="button" id="rejectAssignmentBtn" class="btn-danger">❌ Отклонить</button>
                </div>
                <div id="assignmentRequestStatus" style="margin-top:8px; font-weight:bold; font-size:13px;"></div>
            </div>
            <?php endif; ?>

            <?php if ($canRequestAssignment): ?>
            <!-- оператор может по своей инициативе запросить закрепление прямо из чата -->
            <div class="sidebar-section" id="requestAssignmentBlock">
                <p class="sidebar-section-title">Закрепление за локацией</p>
                <p style="font-size:13px; color:var(--text-muted, #888); margin-bottom:10px;">
                    Если договорились с владельцем — отправьте запрос на закрепление за этой локацией.
                </p>
                <button type="button" id="requestAssignmentBtn" class="chat-btn-primary">📩 Запросить закрепление</button>
                <div id="requestAssignmentStatus" style="margin-top:8px; font-weight:bold; font-size:13px;"></div>
            </div>
            <?php elseif ($is_operator && $isAssignmentRequest && $currentPublicStatus === 'pending'): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">Закрепление за локацией</p>
                <p style="font-size:13px; color:var(--text-muted, #888);">
                    ⏳ Запрос на закрепление отправлен, ожидайте решения владельца.
                </p>
            </div>
            <?php endif; ?>

            <!-- статус заявки -->
            <div class="sidebar-section">
                <p class="sidebar-section-title">Статус</p>
                <div class="status-wrapper">
                    <div class="status-selector <?php echo ($currentPublicStatus === 'cancelled' && !$canChangeCancel) ? 'inactive' : ''; ?>" id="statusSelector" data-active="<?php echo ($currentPublicStatus !== 'cancelled' || $canChangeCancel) ? '1' : '0'; ?>">
                        <span class="chat-status-badge status-<?php echo $displayStatus; ?>" id="currentStatusBadge">
                            <?php echo $statusLabels[$displayStatus] ?? '⏳ Ожидает'; ?>
                        </span>
                        <span class="status-arrow" id="statusArrow" <?php echo ($currentPublicStatus === 'cancelled' && !$canChangeCancel) ? 'style="display:none;"' : ''; ?>>▼</span>
                    </div>
                    <div class="status-dropdown" id="statusDropdown"></div>
                </div>
            </div>

            <!-- событие выезда -->
            <div class="sidebar-section" id="eventBlock">
                <p class="sidebar-section-title">Выезд</p>
                <?php if ($current_event):
                    // Подтверждённое событие не требует решения прямо сейчас —
                    // сворачиваем его в одну строку, чтобы не отвлекало от переписки.
                    // "Ожидает"/"предложена другая дата" всегда развёрнуты — там нужно действие.
                    $isCollapsible = ($current_event['status'] === 'confirmed');
                ?>
                    <?php if ($isCollapsible): ?>
                        <button type="button" class="event-summary-pill" id="eventSummaryToggle">
                            <span class="event-summary-icon">✅</span>
                            <span class="event-summary-text">
                                <?php echo date('d.m.Y H:i', strtotime($current_event['confirmed_datetime'])); ?> · Подтверждено
                            </span>
                            <span class="event-summary-chevron" id="eventSummaryChevron">▾</span>
                        </button>
                    <?php endif; ?>
                    <div class="event-card" id="eventCardBody" <?php echo $isCollapsible ? 'style="display:none;"' : ''; ?>>
                        <span class="event-icon"><?php echo $current_event['is_emergency'] ? '🚨' : '📅'; ?></span>
                        <div class="event-body">
                            <div class="event-header">
                                <span class="event-status">
                                    <?php
                                        $eventStatuses = [
                                            'requested' => '⏳ Ожидает подтверждения',
                                            'reviewing' => '🔄 Предложена другая дата',
                                            'confirmed' => '✅ Подтверждено',
                                            'rescheduled' => '🔄 Перенесено'
                                        ];
                                        echo $eventStatuses[$current_event['status']] ?? $current_event['status'];
                                    ?>
                                </span>
                                <?php if ($current_event['is_emergency']): ?>
                                    <span class="event-emergency">🚨 Срочно</span>
                                <?php endif; ?>
                            </div>
                            <div class="event-datetime">
                                <?php
                                    $display_datetime = $current_event['status'] === 'confirmed' ? $current_event['confirmed_datetime'] : $current_event['proposed_datetime'];
                                    echo date('d.m.Y H:i', strtotime($display_datetime));
                                ?>
                            </div>
                            <?php if ($current_event['emergency_comment']): ?>
                                <div class="event-comment">💬 <?php echo htmlspecialchars($current_event['emergency_comment']); ?></div>
                            <?php endif; ?>
                            <div class="event-actions">
                                <?php if ($current_event['status'] === 'requested' || $current_event['status'] === 'reviewing'): ?>
                                    <?php if ($current_event['requested_by'] != $user_id): ?>
                                        <button class="btn-event confirm-btn" data-event-id="<?php echo $current_event['id']; ?>">✅ Подтвердить</button>
                                        <button class="btn-event reschedule-btn" data-event-id="<?php echo $current_event['id']; ?>">✏️ Другое время</button>
                                    <?php else: ?>
                                        <?php if ($current_event['status'] === 'reviewing'): ?>
                                            <button class="btn-event reschedule-btn" data-event-id="<?php echo $current_event['id']; ?>">✏️ Другое время</button>
                                        <?php endif; ?>
                                        <button class="btn-event cancel-btn" data-event-id="<?php echo $current_event['id']; ?>">❌ Отменить</button>
                                    <?php endif; ?>
                                <?php elseif ($current_event['status'] === 'confirmed'): ?>
                                    <button class="btn-event complete-btn" data-event-id="<?php echo $current_event['id']; ?>">✅ Завершить</button>
                                    <button class="btn-event cancel-btn" data-event-id="<?php echo $current_event['id']; ?>">❌ Отменить</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($currentPublicStatus !== 'cancelled' && $currentPublicStatus !== 'placed' && $hasActiveAssignment): ?>
                    <div class="event-card">
                        <span class="event-icon">📅</span>
                        <div class="event-body">
                            <div class="event-empty">Дата выезда пока не назначена</div>
                            <div class="event-actions">
                                <button class="btn-event propose-btn" id="proposeDateBtn">📅 Предложить дату</button>
                                <?php if (!$is_operator): ?>
                                    <button class="btn-event emergency-btn" id="emergencyBtn">🚨 Срочный выезд</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($currentPublicStatus !== 'cancelled' && $currentPublicStatus !== 'placed'): ?>
                    <div class="event-empty">Планировать выезд можно после того, как владелец закрепит оператора за этой локацией.</div>
                <?php else: ?>
                    <div class="event-empty">Нет активных событий.</div>
                <?php endif; ?>
            </div>

            <!-- действия -->
            <div class="sidebar-section">
                <p class="sidebar-section-title">Действия</p>
                <div class="sidebar-action-row">
                    <span>🔔 Уведомления</span>
                    <label class="switch">
                        <input type="checkbox" id="notifSwitch" <?php echo $notifications_enabled ? 'checked' : ''; ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
                <div class="sidebar-action-row danger-link">
                    <a href="/pages/delete_application.php?id=<?php echo $application['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" onclick="return confirm('Удалить заявку и всю переписку безвозвратно?')">🗑️ Удалить чат</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО ДЛЯ ДАТЫ -->
<div class="chat-modal-overlay" id="dateModal">
    <div class="chat-modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="modalTitle">📅 Выберите дату и время</h3>
                <p class="modal-subtitle">Укажите время выезда</p>
            </div>
            <button class="close-btn" onclick="closeDateModal()">×</button>
        </div>
        <form id="dateForm">
            <div class="chat-form-group">
                <label class="form-label" for="eventDatetime">Дата и время</label>
                <input type="datetime-local" id="eventDatetime" class="form-control" required>
            </div>
            <div id="emergencyCommentGroup" style="display: none;" class="chat-form-group">
                <label class="form-label" for="emergencyComment">Комментарий (причина срочного выезда)</label>
                <textarea id="emergencyComment" class="form-control" rows="3" placeholder="Опишите проблему..."></textarea>
            </div>
            <input type="hidden" id="modalAction" value="propose">
            <input type="hidden" id="modalEventId" value="0">
            <div class="modal-buttons">
                <button type="button" class="chat-btn-secondary" onclick="closeDateModal()">Отмена</button>
                <button type="submit" class="chat-btn-primary">Отправить</button>
            </div>
        </form>
    </div>
</div>

<!-- TOAST-контейнер -->
<div class="chat-toast-container" id="toastContainer"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var chatMessages = document.getElementById('chatMessages');
    if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;

    // --- SIDEBAR / DRAWER (мобилка) ---
    var chatSidebar = document.getElementById('chatSidebar');
    var sidebarBackdrop = document.getElementById('sidebarBackdrop');
    var detailsToggleBtn = document.getElementById('detailsToggleBtn');
    var sidebarCloseBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        chatSidebar.classList.add('open');
        sidebarBackdrop.classList.add('show');
    }
    function closeSidebar() {
        chatSidebar.classList.remove('open');
        sidebarBackdrop.classList.remove('show');
    }
    if (detailsToggleBtn) detailsToggleBtn.addEventListener('click', openSidebar);
    if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // --- УВЕДОМЛЕНИЯ ---
    var notifSwitch = document.getElementById('notifSwitch');
    if (notifSwitch) {
        notifSwitch.addEventListener('change', function() {
            var enabled = this.checked ? 1 : 0;
            var applicationId = <?php echo $application['id']; ?>;
            $.ajax({
                url: '/api/toggle_notifications.php',
                type: 'POST',
                data: { application_id: applicationId, enabled: enabled },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Уведомления ' + (response.enabled ? 'включены' : 'отключены'), 'success');
                    } else {
                        showToast('Ошибка при обновлении настроек', 'error');
                    }
                },
                error: function() {
                    showToast('Ошибка соединения', 'error');
                }
            });
        });
    }

    // --- ОТПРАВКА СООБЩЕНИЙ ---
    var applicationId = <?php echo $application['id']; ?>;
    // делаем application_id видимым глобально, чтобы notifications.js мог
    // не показывать тост о сообщении, если пользователь уже в этом чате
    window.currentChatApplicationId = applicationId;

    // --- РЕШЕНИЕ ПО ЗАПРОСУ НА ЗАКРЕПЛЕНИЕ ---
    var approveAssignmentBtn = document.getElementById('approveAssignmentBtn');
    var rejectAssignmentBtn = document.getElementById('rejectAssignmentBtn');
    var assignmentRequestStatus = document.getElementById('assignmentRequestStatus');

    function decideAssignmentRequest(action, btn) {
        var confirmText = action === 'approve' ? 'Одобрить закрепление этого оператора?' : 'Отклонить запрос на закрепление?';
        if (!confirm(confirmText)) return;

        approveAssignmentBtn.disabled = true;
        rejectAssignmentBtn.disabled = true;
        btn.textContent = 'Отправка...';
        assignmentRequestStatus.textContent = '';

        var formData = new FormData();
        formData.append('action', action);
        formData.append('application_id', applicationId);

        fetch('/api/operator_assign.php', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    assignmentRequestStatus.style.color = '#2ecc71';
                    assignmentRequestStatus.textContent = '✅ Готово, обновляем страницу...';
                    setTimeout(function() { location.reload(); }, 700);
                } else {
                    assignmentRequestStatus.style.color = '#e74c3c';
                    assignmentRequestStatus.textContent = '❌ ' + (data.error || 'Ошибка');
                    approveAssignmentBtn.disabled = false;
                    rejectAssignmentBtn.disabled = false;
                    approveAssignmentBtn.textContent = '✅ Одобрить';
                    rejectAssignmentBtn.textContent = '❌ Отклонить';
                }
            })
            .catch(function() {
                assignmentRequestStatus.style.color = '#e74c3c';
                assignmentRequestStatus.textContent = '❌ Ошибка соединения';
                approveAssignmentBtn.disabled = false;
                rejectAssignmentBtn.disabled = false;
                approveAssignmentBtn.textContent = '✅ Одобрить';
                rejectAssignmentBtn.textContent = '❌ Отклонить';
            });
    }

    if (approveAssignmentBtn && rejectAssignmentBtn) {
        approveAssignmentBtn.addEventListener('click', function() { decideAssignmentRequest('approve', approveAssignmentBtn); });
        rejectAssignmentBtn.addEventListener('click', function() { decideAssignmentRequest('reject', rejectAssignmentBtn); });
    }

    // --- ОПЕРАТОР ЗАПРАШИВАЕТ ЗАКРЕПЛЕНИЕ ПРЯМО ИЗ ЧАТА ---
    var requestAssignmentBtn = document.getElementById('requestAssignmentBtn');
    var requestAssignmentStatus = document.getElementById('requestAssignmentStatus');

    if (requestAssignmentBtn) {
        requestAssignmentBtn.addEventListener('click', function() {
            if (!confirm('Отправить владельцу запрос на закрепление за этой локацией?')) return;

            requestAssignmentBtn.disabled = true;
            requestAssignmentBtn.textContent = 'Отправка...';
            requestAssignmentStatus.textContent = '';

            var formData = new FormData();
            formData.append('action', 'request');
            formData.append('application_id', applicationId);

            fetch('/api/operator_assign.php', { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        requestAssignmentStatus.style.color = '#2ecc71';
                        requestAssignmentStatus.textContent = '✅ Запрос отправлен, обновляем страницу...';
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        requestAssignmentStatus.style.color = '#e74c3c';
                        requestAssignmentStatus.textContent = '❌ ' + (data.error || 'Ошибка');
                        requestAssignmentBtn.disabled = false;
                        requestAssignmentBtn.textContent = '📩 Запросить закрепление';
                    }
                })
                .catch(function() {
                    requestAssignmentStatus.style.color = '#e74c3c';
                    requestAssignmentStatus.textContent = '❌ Ошибка соединения';
                    requestAssignmentBtn.disabled = false;
                    requestAssignmentBtn.textContent = '📩 Запросить закрепление';
                });
        });
    }

    var userId = <?php echo $user_id; ?>;
    var lastMessageId = <?php echo !empty($messages) ? end($messages)['id'] : 0; ?>;

    var operatorId = <?php echo $application['operator_id']; ?>;
    // json_encode с HEX-флагами вместо addslashes(): addslashes() экранирует
    // только кавычки, а не угловые скобки и амперсанд, так что имя с
    // закрывающим тегом script внутри вырывалось бы из этого блока и
    // исполнялось как отдельный скрипт.
    var operatorName = <?php echo json_encode($application['operator_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var ownerName = <?php echo json_encode($application['owner_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function getInitials(name) {
        return name.split(/\s+/).filter(Boolean).slice(0, 2).map(function(p) {
            return p.charAt(0).toUpperCase();
        }).join('');
    }

    function appendMessage(msg, isOwn) {
        var isOperatorMsg = (msg.sender_id == operatorId);
        var senderFullName = isOperatorMsg ? operatorName : ownerName;
        var senderLabel = senderFullName + (isOperatorMsg ? ' (Оператор)' : ' (Владелец)');
        var date = new Date(msg.created_at * 1000);
        var time = date.toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit' });

        var div = document.createElement('div');
        div.className = 'message' + (isOwn ? ' own' : '');

        var avatarHtml = isOwn ? '' : '<div class="avatar">' + escapeHtml(getInitials(senderFullName)) + '</div>';
        var receiptHtml = isOwn ?
            '<div class="msg-meta"><span class="read-receipt' + (msg.is_read ? ' read' : '') + '">' + (msg.is_read ? '✓✓' : '✓') + '</span></div>' : '';
        div.innerHTML = avatarHtml +
                        '<div class="message-body">' +
                            '<div class="sender">' + escapeHtml(senderLabel) + ' <span class="time">' + time + '</span></div>' +
                            '<div class="text">' + escapeHtml(msg.message) + '</div>' +
                            receiptHtml +
                        '</div>';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    var form = document.querySelector('.chat-input form');
    var textarea = form ? form.querySelector('textarea[name="message"]') : null;
    var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 140) + 'px';
            // hook: здесь на следующем шаге появится debounce-вызов "печатает..."
        });
    }
    if (form && textarea && submitBtn) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var message = textarea.value.trim();
            if (!message) return;
            submitBtn.disabled = true;
            submitBtn.textContent = '…';
            var formData = new FormData();
            formData.append('application_id', applicationId);
            formData.append('message', message);
            fetch('/api/send_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    appendMessage(data.message, true);
                    textarea.value = '';
                    textarea.style.height = 'auto';
                    lastMessageId = data.message.id;
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(err => {
                showToast('Ошибка соединения с сервером', 'error');
                console.error(err);
            })
            .finally(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = '➤';
            });
        });
    }

    // --- ПОЛЛИНГ НОВЫХ СООБЩЕНИЙ ---
    function checkNewMessages() {
        fetch('/api/get_chat_messages.php?application_id=' + applicationId + '&last_id=' + lastMessageId)
        .then(response => response.json())
        .then(data => {
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(function(msg) {
                    var isOwn = (msg.sender_id == userId);
                    appendMessage(msg, isOwn);
                    lastMessageId = msg.id;
                });
            }
        })
        .catch(err => {});
    }
    setInterval(checkNewMessages, 5000);
    setTimeout(checkNewMessages, 1000);

    // --- СТАТУС ЗАЯВКИ ---
    var statusSelector = document.getElementById('statusSelector');
    var statusDropdown = document.getElementById('statusDropdown');
    var currentBadge = document.getElementById('currentStatusBadge');
    var statusArrow = document.getElementById('statusArrow');
    var detailsToggleDot = document.getElementById('detailsToggleDot');

    var allStatuses = {
        'pending': '⏳ Ожидает',
        'negotiating': '🤝 В переговорах',
        'agreed': '✅ Договорённость',
        'placed': '📍 Размещено',
        'cancelled': '❌ Отменена'
    };
    var publicStatus = '<?php echo $currentPublicStatus; ?>';
    var currentMyTag = '<?php echo $my_tag; ?>';
    var cancelledBy = <?php echo $cancelled_by ?: 'null'; ?>;
    var isOperator = <?php echo $is_operator ? 'true' : 'false'; ?>;
    var currentUser = <?php echo $user_id; ?>;

    function updateStatusDisplay(newStatus) {
        var label = allStatuses[newStatus] || '⏳ Ожидает';
        currentBadge.className = 'chat-status-badge status-' + newStatus;
        currentBadge.textContent = label;

        if (detailsToggleDot) {
            detailsToggleDot.className = 'status-dot dot-' + newStatus;
        }

        var isActive = true;
        if (newStatus === 'cancelled') {
            if (cancelledBy !== currentUser) {
                isActive = false;
            }
        }
        statusSelector.dataset.active = isActive ? '1' : '0';
        statusSelector.classList.toggle('inactive', !isActive);
        if (statusArrow) {
            statusArrow.style.display = isActive ? 'inline' : 'none';
        }
        if (newStatus === 'cancelled') {
            var chatInput = document.querySelector('.chat-input form');
            if (chatInput) chatInput.style.display = 'none';
            var closedMsg = document.querySelector('.chat-input .chat-closed');
            if (closedMsg) closedMsg.style.display = 'block';
        } else {
            var chatInput = document.querySelector('.chat-input form');
            if (chatInput) chatInput.style.display = 'flex';
            var closedMsg = document.querySelector('.chat-input .chat-closed');
            if (closedMsg) closedMsg.style.display = 'none';
        }
    }

    function renderDropdown() {
        var options = ['pending', 'negotiating', 'agreed', 'placed'];
        var showCancel = (publicStatus !== 'cancelled') || (publicStatus === 'cancelled' && cancelledBy === currentUser);
        if (showCancel) options.push('cancelled');
        var html = '';
        var currentDisplay = publicStatus === 'cancelled' ? 'cancelled' : (currentMyTag || 'pending');
        options.forEach(function(status) {
            var isActive = (status === currentDisplay);
            html += '<div class="dropdown-item status-option" data-status="' + status + '" style="' + (isActive ? 'background:var(--gray-bg);' : '') + '">' +
                        allStatuses[status] +
                    '</div>';
        });
        statusDropdown.innerHTML = html;
        statusDropdown.querySelectorAll('.status-option').forEach(function(item) {
            item.addEventListener('click', function() {
                var newStatus = this.dataset.status;
                if (newStatus === currentDisplay) {
                    statusDropdown.style.display = 'none';
                    return;
                }
                if (!confirm('Изменить статус на "' + this.textContent.trim() + '"?')) return;
                var statusFormData = new FormData();
                statusFormData.append('application_id', applicationId);
                statusFormData.append('status', newStatus);
                fetch('/api/change_status.php', { method: 'POST', body: statusFormData })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast('Ошибка: ' + data.error, 'error');
                    } else {
                        if (newStatus === 'cancelled') {
                            publicStatus = 'cancelled';
                            cancelledBy = currentUser;
                            currentMyTag = null;
                        } else {
                            if (publicStatus === 'cancelled') {
                                publicStatus = 'pending';
                                cancelledBy = null;
                            }
                            currentMyTag = newStatus;
                        }
                        var displayStatus = publicStatus === 'cancelled' ? 'cancelled' : (currentMyTag || 'pending');
                        updateStatusDisplay(displayStatus);
                        statusDropdown.style.display = 'none';
                        showToast('Статус обновлён', 'success');
                    }
                })
                .catch(err => {
                    showToast('Ошибка соединения', 'error');
                    console.error(err);
                });
            });
        });
    }

    statusSelector.addEventListener('click', function(e) {
        e.stopPropagation();
        if (this.dataset.active === '0') return;
        if (statusDropdown.style.display === 'block') {
            statusDropdown.style.display = 'none';
        } else {
            renderDropdown();
            statusDropdown.style.display = 'block';
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.status-selector') && !e.target.closest('.status-dropdown')) {
            statusDropdown.style.display = 'none';
        }
    });

    var initDisplay = publicStatus === 'cancelled' ? 'cancelled' : (currentMyTag || 'pending');
    updateStatusDisplay(initDisplay);

    // --- СОБЫТИЯ ВЫЕЗДА ---

    // Разворачивание/сворачивание подтверждённого события по клику на "таблетку"
    var eventSummaryToggle = document.getElementById('eventSummaryToggle');
    var eventCardBody = document.getElementById('eventCardBody');
    var eventSummaryChevron = document.getElementById('eventSummaryChevron');
    if (eventSummaryToggle && eventCardBody) {
        eventSummaryToggle.addEventListener('click', function() {
            var isHidden = eventCardBody.style.display === 'none';
            eventCardBody.style.display = isHidden ? 'flex' : 'none';
            if (eventSummaryChevron) eventSummaryChevron.textContent = isHidden ? '▴' : '▾';
        });
    }

    function refreshEventBlock() {
        fetch('/api/installation.php?action=get_for_application&application_id=' + applicationId)
        .then(response => response.json())
        .then(data => {
            location.reload();
        })
        .catch(err => {
            console.error('Ошибка обновления событий', err);
        });
    }

    document.getElementById('proposeDateBtn')?.addEventListener('click', function() {
        document.getElementById('modalTitle').textContent = '📅 Предложить дату выезда';
        document.getElementById('modalAction').value = 'propose';
        document.getElementById('modalEventId').value = 0;
        document.getElementById('emergencyCommentGroup').style.display = 'none';
        document.getElementById('dateModal').classList.add('active');
    });

    document.getElementById('emergencyBtn')?.addEventListener('click', function() {
        document.getElementById('modalTitle').textContent = '🚨 Срочный выезд';
        document.getElementById('modalAction').value = 'emergency';
        document.getElementById('modalEventId').value = 0;
        document.getElementById('emergencyCommentGroup').style.display = 'block';
        document.getElementById('dateModal').classList.add('active');
    });

    document.querySelectorAll('.reschedule-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var eventId = this.dataset.eventId;
            document.getElementById('modalTitle').textContent = '✏️ Предложить другое время';
            document.getElementById('modalAction').value = 'reschedule';
            document.getElementById('modalEventId').value = eventId;
            document.getElementById('emergencyCommentGroup').style.display = 'none';
            document.getElementById('dateModal').classList.add('active');
        });
    });

    document.querySelectorAll('.confirm-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var eventId = this.dataset.eventId;
            if (!confirm('Подтвердить эту дату?')) return;
            var formData = new FormData();
            formData.append('action', 'confirm');
            formData.append('event_id', eventId);
            fetch('/api/installation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Дата подтверждена!', 'success');
                    refreshEventBlock();
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(err => {
                showToast('Ошибка соединения', 'error');
                console.error(err);
            });
        });
    });

    document.querySelectorAll('.cancel-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var eventId = this.dataset.eventId;
            if (!confirm('Отменить событие?')) return;
            var formData = new FormData();
            formData.append('action', 'cancel');
            formData.append('event_id', eventId);
            fetch('/api/installation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Событие отменено', 'success');
                    refreshEventBlock();
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(err => {
                showToast('Ошибка соединения', 'error');
                console.error(err);
            });
        });
    });

    document.querySelectorAll('.complete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var eventId = this.dataset.eventId;
            if (!confirm('Завершить событие?')) return;
            var formData = new FormData();
            formData.append('action', 'complete');
            formData.append('event_id', eventId);
            fetch('/api/installation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Событие завершено', 'success');
                    refreshEventBlock();
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(err => {
                showToast('Ошибка соединения', 'error');
                console.error(err);
            });
        });
    });

    document.getElementById('dateForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var action = document.getElementById('modalAction').value;
        var datetime = document.getElementById('eventDatetime').value;
        var eventId = document.getElementById('modalEventId').value;
        var comment = document.getElementById('emergencyComment').value.trim();

        if (!datetime) {
            showToast('Выберите дату и время', 'warning');
            return;
        }

        var formData = new FormData();
        if (action === 'propose' || action === 'emergency') {
            formData.append('action', 'request');
            formData.append('application_id', applicationId);
            formData.append('datetime', datetime);
            if (action === 'emergency') {
                formData.append('is_emergency', 1);
                if (comment) formData.append('comment', comment);
            } else {
                formData.append('is_emergency', 0);
            }
        } else if (action === 'reschedule') {
            formData.append('action', 'reschedule');
            formData.append('event_id', eventId);
            formData.append('datetime', datetime);
        }

        fetch('/api/installation.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Запрос отправлен!', 'success');
                closeDateModal();
                refreshEventBlock();
            } else {
                showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(err => {
            showToast('Ошибка соединения', 'error');
            console.error(err);
        });
    });

    function closeDateModal() {
        document.getElementById('dateModal').classList.remove('active');
    }
    window.closeDateModal = closeDateModal;
    document.getElementById('dateModal').addEventListener('click', function(e) {
        if (e.target === this) closeDateModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDateModal();
    });

    // --- TOAST ---
    function showToast(message, type) {
        var container = document.getElementById('toastContainer');
        var toast = document.createElement('div');
        toast.className = 'toast ' + (type || '');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
    window.showToast = showToast;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>