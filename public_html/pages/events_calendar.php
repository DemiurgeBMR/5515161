<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['operator', 'owner'], true)) {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$pdo = getDbConnection();

// Точки, доступные текущему пользователю — для выпадающего списка в "Новый выезд"
if ($role === 'operator') {
    $stmt = $pdo->prepare("
        SELECT lo.id, l.title, l.city, u.full_name as counterpart_name
        FROM location_operators lo
        JOIN locations l ON l.id = lo.location_id
        JOIN users u ON u.id = lo.owner_id
        WHERE lo.operator_id = ? AND lo.status = 'active'
        ORDER BY l.city, l.title
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT lo.id, l.title, l.city, u.full_name as counterpart_name
        FROM location_operators lo
        JOIN locations l ON l.id = lo.location_id
        JOIN users u ON u.id = lo.operator_id
        WHERE lo.owner_id = ? AND lo.status = 'active'
        ORDER BY l.city, l.title
    ");
}
$stmt->execute([$user_id]);
$myLocationOperators = $stmt->fetchAll();

$backLink = ($role === 'operator') ? '/pages/operator_dashboard.php' : '/pages/profile.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Календарь выездов — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- FullCalendar уже подключён глобально в header.php -->
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="calendar-container">

    <div class="calendar-header">
        <div class="calendar-title-block">
            <a href="<?php echo $backLink; ?>" class="cal-back-link">← Назад</a>
            <h1 class="calendar-title">📅 Выезды и обслуживание</h1>
            <p class="calendar-subtitle">Планирование, согласование и история обслуживания точек</p>
        </div>
        <div class="calendar-actions">
            <button class="action-btn" id="historyBtn">📜 История и экспорт</button>
            <button class="action-btn" id="refreshCalendarBtn">↻ Обновить</button>
            <button class="action-btn primary" id="newEventBtn">＋ Новый выезд</button>
        </div>
    </div>

    <div class="calendar-stats">
        <div class="stat-card" data-filter="today" id="statToday">
            <div class="stat-icon today">📅</div>
            <div class="stat-info">
                <div class="stat-number" id="todayCount">0</div>
                <div class="stat-label">Событий сегодня</div>
            </div>
        </div>
        <div class="stat-card" data-filter="pending" id="statPending">
            <div class="stat-icon pending">⏳</div>
            <div class="stat-info">
                <div class="stat-number" id="pendingCount">0</div>
                <div class="stat-label">Ожидают подтверждения</div>
            </div>
        </div>
        <div class="stat-card" data-filter="emergency" id="statEmergency">
            <div class="stat-icon emergency">🚨</div>
            <div class="stat-info">
                <div class="stat-number" id="emergencyCount">0</div>
                <div class="stat-label">Срочных выездов</div>
            </div>
        </div>
        <div class="stat-card" id="statCompleted">
            <div class="stat-icon completed">✅</div>
            <div class="stat-info">
                <div class="stat-number" id="completedCount">0</div>
                <div class="stat-label">Выполнено за месяц</div>
            </div>
        </div>
    </div>

    <div class="calendar-toolbar">
        <div class="toolbar-left">
            <div class="cal-search-box">
                <span class="search-icon">🔎</span>
                <input type="text" id="eventSearch" placeholder="Поиск по точке, городу, оператору...">
            </div>
        </div>
        <div class="toolbar-right">
            <select class="filter-select" id="typeFilter">
                <option value="all">Все типы</option>
                <option value="installation">Установка</option>
                <option value="maintenance">Плановое ТО</option>
                <option value="restock">Пополнение</option>
                <option value="repair">Ремонт</option>
                <option value="removal">Демонтаж</option>
            </select>
            <select class="filter-select" id="statusFilter">
                <option value="all">Все статусы</option>
                <option value="requested">⏳ Ожидают</option>
                <option value="reviewing">🔄 На рассмотрении</option>
                <option value="confirmed">✅ Подтверждены</option>
                <option value="completed">✔️ Завершены</option>
                <option value="cancelled">⛔ Отменены</option>
                <option value="quicklog">📝 Постфактум-отметки</option>
            </select>
            <select class="filter-select" id="emergencyFilter">
                <option value="all">Все выезды</option>
                <option value="emergency">🚨 Только срочные</option>
                <option value="normal">Обычные</option>
            </select>
            <?php if ($role === 'owner'): ?>
            <select class="filter-select" id="operatorFilter">
                <option value="all">Все операторы</option>
            </select>
            <?php endif; ?>
        </div>
    </div>

    <div class="calendar-layout">
        <aside class="today-sidebar">
            <div class="today-sidebar-header">
                <h3 class="today-sidebar-title">Сегодня</h3>
                <div class="today-sidebar-date" id="todaySidebarDate">—</div>
            </div>
            <div class="today-events" id="todayEvents">
                <div class="today-empty">Загрузка...</div>
            </div>
        </aside>

        <main class="calendar-card">
            <div id="calendar"></div>
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
            </div>
        </main>
    </div>
</div>

<!-- ===== МОДАЛКА СОЗДАНИЯ ===== -->
<div class="cal-modal-overlay" id="eventModal">
    <div class="cal-modal-box">
        <div class="cal-modal-header">
            <div>
                <h3 class="cal-modal-title" id="modalTitle">Новый выезд</h3>
                <p class="cal-modal-subtitle" id="modalSub">Выберите точку и укажите время</p>
            </div>
            <button type="button" class="cal-close-btn" onclick="closeModal()">×</button>
        </div>
        <form id="eventForm">
            <div class="cal-form-group">
                <label class="cal-form-label" for="modalLocationOperator">Точка *</label>
                <select id="modalLocationOperator" class="cal-form-control" required>
                    <option value="">-- Выберите точку --</option>
                    <?php foreach ($myLocationOperators as $lo): ?>
                        <option value="<?php echo (int)$lo['id']; ?>">
                            <?php echo htmlspecialchars($lo['title'] . ' (' . $lo['city'] . ') — ' . $lo['counterpart_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($myLocationOperators)): ?>
                    <div style="color:#dc2626; font-size:13px; margin-top:7px;">
                        Нет закреплённых точек, для которых можно запросить визит.
                    </div>
                <?php endif; ?>
            </div>
            <div class="cal-form-group">
                <label class="cal-form-label" for="modalEventType">Тип визита *</label>
                <select id="modalEventType" class="cal-form-control" required>
                    <option value="maintenance">Плановое обслуживание</option>
                    <option value="restock">Пополнение товара</option>
                    <option value="repair">Ремонт</option>
                    <option value="installation">Установка</option>
                    <option value="removal">Демонтаж</option>
                </select>
            </div>
            <div class="cal-form-group">
                <label class="cal-form-label" for="eventDatetime">Дата и время *</label>
                <input type="datetime-local" id="eventDatetime" class="cal-form-control" required>
            </div>
            <div class="cal-form-group">
                <label class="emergency-toggle" for="isEmergency">
                    <input type="checkbox" id="isEmergency">
                    <span>🚨 Срочный выезд</span>
                </label>
            </div>
            <div class="cal-form-group" id="emergencyGroup" style="display:none;">
                <label class="cal-form-label" for="emergencyComment">Причина срочности *</label>
                <textarea id="emergencyComment" class="cal-form-control" rows="3" placeholder="Опишите проблему или причину срочного выезда..."></textarea>
            </div>
            <div id="formError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="cal-modal-buttons">
                <button type="button" class="cal-btn-secondary" onclick="closeModal()">Отмена</button>
                <button type="submit" class="cal-btn-primary" id="modalSubmitBtn">Запросить визит</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== МОДАЛКА ДЕТАЛЕЙ ===== -->
<div class="cal-modal-overlay" id="detailsModal">
    <div class="cal-modal-box">
        <div class="cal-modal-header">
            <div>
                <h3 class="cal-modal-title" id="detailsTitle">Выезд</h3>
                <p class="cal-modal-subtitle" id="detailsSubtitle">—</p>
            </div>
            <button type="button" class="cal-close-btn" onclick="closeDetailsModal()">×</button>
        </div>
        <div id="detailsContent"></div>
    </div>
</div>

<!-- ===== МОДАЛКА ИЗМЕНЕНИЯ ДАТЫ ===== -->
<div class="cal-modal-overlay" id="rescheduleModal">
    <div class="cal-modal-box">
        <div class="cal-modal-header">
            <div>
                <h3 class="cal-modal-title">✏️ Изменить время</h3>
                <p class="cal-modal-subtitle">Выберите новую дату и время</p>
            </div>
            <button type="button" class="cal-close-btn" onclick="closeRescheduleModal()">×</button>
        </div>
        <form id="rescheduleForm">
            <div class="cal-form-group">
                <label class="cal-form-label" for="rescheduleDatetime">Новая дата и время *</label>
                <input type="datetime-local" id="rescheduleDatetime" class="cal-form-control" required>
            </div>
            <div id="rescheduleError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="cal-modal-buttons">
                <button type="button" class="cal-btn-secondary" onclick="closeRescheduleModal()">Отмена</button>
                <button type="submit" class="cal-btn-primary" id="rescheduleSubmitBtn">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== МОДАЛКА ЗАВЕРШЕНИЯ ВЫЕЗДА ===== -->
<div class="cal-modal-overlay" id="completeModal">
    <div class="cal-modal-box">
        <div class="cal-modal-header">
            <div>
                <h3 class="cal-modal-title">✔️ Завершить выезд</h3>
                <p class="cal-modal-subtitle">Можно приложить фото подтверждения</p>
            </div>
            <button type="button" class="cal-close-btn" onclick="closeCompleteModal()">×</button>
        </div>
        <form id="completeForm">
            <div class="cal-form-group">
                <label class="cal-form-label" for="completePhotos">Фото подтверждения</label>
                <input type="file" id="completePhotos" class="cal-form-control" accept="image/*" multiple>
                <div style="color:var(--text-muted); font-size:12px; margin-top:4px;">Необязательно, можно выбрать несколько фото</div>
            </div>
            <div id="completeProgressWrap" style="display:none; margin-bottom:14px;">
                <div style="background:#f3f4f6; border-radius:20px; overflow:hidden; height:8px;">
                    <div id="completeProgressBar" style="background:var(--primary); height:100%; width:0%; transition:width .15s;"></div>
                </div>
                <div id="completeProgressText" style="color:var(--text-muted); font-size:12px; margin-top:4px;">0%</div>
            </div>
            <div id="completeError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="cal-modal-buttons">
                <button type="button" class="cal-btn-secondary" onclick="closeCompleteModal()">Отмена</button>
                <button type="submit" class="cal-btn-primary" id="completeSubmitBtn">Завершить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== ЛАЙТБОКС ФОТО ===== -->
<div class="cal-modal-overlay" id="photoLightbox" style="background: rgba(17,24,39,.9); z-index: 30000;">
    <button type="button" id="lightboxClose" style="position:absolute; top:20px; right:24px; background:rgba(255,255,255,.12); border:0; color:white; width:42px; height:42px; border-radius:50%; font-size:24px; cursor:pointer;">×</button>
    <button type="button" id="lightboxPrev" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,.12); border:0; color:white; width:48px; height:48px; border-radius:50%; font-size:22px; cursor:pointer;">‹</button>
    <img id="lightboxImg" src="" alt="Фото подтверждения" style="max-width:85vw; max-height:82vh; border-radius:10px; box-shadow:0 20px 60px rgba(0,0,0,.5);">
    <button type="button" id="lightboxNext" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,.12); border:0; color:white; width:48px; height:48px; border-radius:50%; font-size:22px; cursor:pointer;">›</button>
    <div id="lightboxCounter" style="position:absolute; bottom:24px; left:50%; transform:translateX(-50%); color:white; font-size:13px; font-weight:700; background:rgba(255,255,255,.12); padding:6px 14px; border-radius:20px;"></div>
</div>

<!-- ===== КОНТЕКСТНОЕ МЕНЮ ===== -->
<div class="context-menu" id="contextMenu">
    <div class="menu-item" id="ctxView">📍 Открыть точку</div>
    <div class="menu-item" id="ctxConfirm">✅ Подтвердить</div>
    <div class="menu-item" id="ctxComplete">✔️ Завершить выезд</div>
    <div class="menu-item danger" id="ctxDelete">🗑️ Отменить выезд</div>
</div>

<!-- ===== TOAST ===== -->
<div class="cal-toast-container" id="toastContainer"></div>

<script>
const ROLE = <?php echo json_encode($role); ?>;
const USER_ID = <?php echo (int)$user_id; ?>;
document.addEventListener('DOMContentLoaded', function () {

    // ============================================================
    // 1. ГЛОБАЛЬНОЕ СОСТОЯНИЕ
    // ============================================================
    let calendar = null;
    let allItems = [];       // события + постфактум-отметки в едином формате FullCalendar
    let activeEvent = null;  // выбранный объект FullCalendar (event или quicklog)
    let activeQuickFilter = null;
    let isLoading = false;

    const eventTypeLabels = {
        installation: 'Установка', maintenance: 'Плановое обслуживание',
        restock: 'Пополнение товара', repair: 'Ремонт', removal: 'Демонтаж'
    };

    // ============================================================
    // 2. DOM-ЭЛЕМЕНТЫ
    // ============================================================
    const calendarEl = document.getElementById('calendar');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const eventModal = document.getElementById('eventModal');
    const detailsModal = document.getElementById('detailsModal');
    const rescheduleModal = document.getElementById('rescheduleModal');
    const completeModal = document.getElementById('completeModal');
    const photoLightbox = document.getElementById('photoLightbox');
    const contextMenu = document.getElementById('contextMenu');
    const operatorFilterEl = document.getElementById('operatorFilter'); // только у владельца

    // ============================================================
    // 3. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
    // ============================================================
    function showLoading(show) {
        isLoading = show;
        loadingOverlay.classList.toggle('active', show);
    }

    function showToast(message, type) {
        const container = document.getElementById('toastContainer');
        const cal-toast = document.createElement('div');
        cal-toast.className = 'cal-toast ' + (type || '');
        cal-toast.textContent = message;
        container.appendChild(cal-toast);
        setTimeout(() => {
            cal-toast.style.opacity = '0';
            cal-toast.style.transform = 'translateY(10px)';
            setTimeout(() => cal-toast.remove(), 300);
        }, 3000);
    }

    function showFormError(errorEl, message) {
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }
    function hideFormError(errorEl) {
        errorEl.style.display = 'none';
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : value;
        return div.innerHTML;
    }

    function getStatusText(status) {
        const map = {
            'requested': 'Ожидает', 'reviewing': 'На рассмотрении',
            'confirmed': 'Подтверждено', 'completed': 'Завершено',
            'cancelled': 'Отменено', 'quicklog': 'Постфактум-отметка'
        };
        return map[status] || status || 'Неизвестно';
    }

    function getStatusIcon(status) {
        const map = {
            'requested': '🟡', 'reviewing': '🟠', 'confirmed': '🔵',
            'completed': '🟢', 'cancelled': '⚫', 'quicklog': '📝'
        };
        return map[status] || '⚪';
    }

    function formatLocalDate(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function formatReadableDate(dateString) {
        const parts = dateString.split('-');
        if (parts.length !== 3) return dateString;
        return `${parts[2]}.${parts[1]}.${parts[0]}`;
    }

    function formatDateTime(date) {
        if (!date) return '—';
        const d = date;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth()+1).padStart(2, '0');
        const year = d.getFullYear();
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        return `${day}.${month}.${year} • ${hours}:${minutes}`;
    }

    function formatDateTimeString(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) return value;
        return formatDateTime(date);
    }

    function getEventsForDate(dateStr) {
        return allItems.filter(e => e.start && e.start.startsWith(dateStr));
    }

    // ============================================================
    // 4. ИНИЦИАЛИЗАЦИЯ КАЛЕНДАРЯ
    // ============================================================
    calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'ru',
        initialView: 'dayGridMonth',
        firstDay: 1,
        height: 'auto',
        nowIndicator: true,
        selectable: true,
        editable: false,
        dayMaxEvents: 4,
        fixedWeekCount: false,
        navLinkDayClick: false,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        buttonText: { today: 'Сегодня', month: 'Месяц', week: 'Неделя', day: 'День' },
        views: {
            dayGridMonth: { titleFormat: { year: 'numeric', month: 'long' } },
            timeGridWeek: { titleFormat: { day: 'numeric', month: 'long', year: 'numeric' } },
            timeGridDay: { titleFormat: { day: 'numeric', month: 'long', year: 'numeric' } }
        },

        dateClick: function(info) {
            const dateStr = info.dateStr;
            const itemsOnDate = getEventsForDate(dateStr);
            if (itemsOnDate.length > 0) {
                const first = itemsOnDate[0];
                const obj = calendar.getEventById(first.id);
                if (obj) { activeEvent = obj; openDetailsModal(obj); return; }
            }
            let time = '10:00';
            if (info.date && info.date.getHours()) {
                const hours = String(info.date.getHours()).padStart(2, '0');
                const minutes = String(info.date.getMinutes()).padStart(2, '0');
                time = `${hours}:${minutes}`;
            }
            openCreateModal(dateStr, time);
        },

        events: function(info, successCallback, failureCallback) {
            showLoading(true);
            fetch('/api/installation.php?action=get_for_user&role=' + ROLE)
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    const scheduled = (data.events || []).map(ev => {
                        const displayDatetime = ev.status === 'confirmed' || ev.status === 'completed'
                            ? (ev.confirmed_datetime || ev.proposed_datetime)
                            : ev.proposed_datetime;
                        const classes = ['event-' + ev.status];
                        if (Number(ev.is_emergency) === 1) classes.push('cal-event-emergency');
                        let title = (eventTypeLabels[ev.event_type] || ev.event_type) + ' — ' + ev.location_title + ' (' + ev.city + ')';
                        if (ROLE === 'owner' && ev.operator_name) title += ' · ' + ev.operator_name;
                        return {
                            id: 'evt-' + ev.id,
                            title: title,
                            start: displayDatetime,
                            classNames: classes,
                            extendedProps: {
                                kind: 'event',
                                dbId: ev.id,
                                application_id: ev.application_id,
                                location_id: ev.location_id,
                                event_type: ev.event_type,
                                status: ev.status,
                                is_emergency: Number(ev.is_emergency) === 1,
                                requested_by: ev.requested_by,
                                location_title: ev.location_title,
                                city: ev.city,
                                operator_name: ev.operator_name,
                                owner_name: ev.owner_name,
                                proposed_datetime: ev.proposed_datetime,
                                confirmed_datetime: ev.confirmed_datetime,
                                emergency_comment: ev.emergency_comment || '',
                                photos: ev.photos || []
                            }
                        };
                    });

                    const logs = (data.quick_logs || []).map(l => {
                        let title = '✔ ' + (eventTypeLabels[l.event_type] || l.event_type) + ' — ' + l.location_title + ' (' + l.city + ')';
                        if (ROLE === 'owner' && l.operator_name) title += ' · ' + l.operator_name;
                        return {
                            id: 'log-' + l.id,
                            title: title,
                            start: l.performed_at,
                            classNames: ['event-quicklog'],
                            extendedProps: {
                                kind: 'quicklog',
                                dbId: l.id,
                                location_id: l.location_id,
                                event_type: l.event_type,
                                status: 'quicklog',
                                is_emergency: false,
                                location_title: l.location_title,
                                city: l.city,
                                operator_name: l.operator_name,
                                owner_name: l.owner_name,
                                performed_at: l.performed_at,
                                comment: l.comment || '',
                                photos: l.photos || []
                            }
                        };
                    });

                    allItems = scheduled.concat(logs);
                    populateOperatorFilter();
                    updateInterface();
                    successCallback(getFilteredEvents());
                    showLoading(false);
                })
                .catch(error => {
                    console.error(error);
                    showToast('Не удалось загрузить события: ' + error.message, 'error');
                    failureCallback(error);
                    showLoading(false);
                });
        },

        eventClick: function(info) {
            info.jsEvent.preventDefault();
            activeEvent = info.event;
            openDetailsModal(info.event);
        },

        eventDidMount: function(info) {
            const event = info.event;
            const props = event.extendedProps;
            let tooltip = props.location_title;
            if (props.city) tooltip += '\n' + props.city;
            tooltip += '\nСтатус: ' + getStatusText(props.status);
            if (props.is_emergency && props.emergency_comment) tooltip += '\nПричина: ' + props.emergency_comment;
            info.el.title = tooltip;

            info.el.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                e.stopPropagation();
                activeEvent = event;
                showContextMenu(e, event);
            });
        }
    });

    calendar.render();

    // ============================================================
    // 5. ФИЛЬТР ПО ОПЕРАТОРУ (только владелец) — заполняется из загруженных данных
    // ============================================================
    function populateOperatorFilter() {
        if (!operatorFilterEl) return;
        const current = operatorFilterEl.value;
        const seen = new Set();
        operatorFilterEl.innerHTML = '<option value="all">Все операторы</option>';
        allItems.forEach(function(e) {
            const name = e.extendedProps.operator_name;
            if (name && !seen.has(name)) {
                seen.add(name);
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                operatorFilterEl.appendChild(opt);
            }
        });
        if ([...operatorFilterEl.options].some(o => o.value === current)) {
            operatorFilterEl.value = current;
        }
    }

    // ============================================================
    // 6. ОБНОВЛЕНИЕ ИНТЕРФЕЙСА
    // ============================================================
    function updateInterface() {
        updateStats();
        updateTodaySidebar();
    }

    function updateStats() {
        const today = formatLocalDate(new Date());
        const todayItems = allItems.filter(e => e.start && e.start.startsWith(today));
        const pendingEvents = allItems.filter(e =>
            e.extendedProps.kind === 'event' && (e.extendedProps.status === 'requested' || e.extendedProps.status === 'reviewing')
        );
        const emergencyEvents = allItems.filter(e => e.extendedProps.is_emergency);
        const currentMonth = new Date().toISOString().slice(0, 7);
        const completedThisMonth = allItems.filter(e => {
            if (!e.start || !e.start.startsWith(currentMonth)) return false;
            return (e.extendedProps.kind === 'event' && e.extendedProps.status === 'completed')
                || e.extendedProps.kind === 'quicklog';
        });

        document.getElementById('todayCount').textContent = todayItems.length;
        document.getElementById('pendingCount').textContent = pendingEvents.length;
        document.getElementById('emergencyCount').textContent = emergencyEvents.length;
        document.getElementById('completedCount').textContent = completedThisMonth.length;
    }

    function updateTodaySidebar() {
        const today = formatLocalDate(new Date());
        const todayItems = allItems
            .filter(e => e.start && e.start.startsWith(today))
            .sort((a, b) => new Date(a.start) - new Date(b.start));

        document.getElementById('todaySidebarDate').textContent = formatReadableDate(today);

        const container = document.getElementById('todayEvents');
        if (todayItems.length === 0) {
            container.innerHTML = `<div class="today-empty">🎉 Сегодня событий нет</div>`;
            return;
        }

        container.innerHTML = todayItems.map(item => {
            const props = item.extendedProps;
            const time = item.start.substring(11, 16);
            let statusClass = 'status-' + props.status;
            if (props.is_emergency) statusClass = 'status-emergency';
            const icon = props.kind === 'quicklog' ? '📝 ' : (props.is_emergency ? '🚨 ' : '');
            return `
                <div class="today-event" onclick="openEventFromSidebar('${item.id}')">
                    <div class="today-event-time">${icon}${time}</div>
                    <div class="today-event-title">${escapeHtml(props.location_title || item.title)}</div>
                    <div class="today-event-city">📍 ${escapeHtml(props.city || '')}${props.operator_name && ROLE === 'owner' ? ' · ' + escapeHtml(props.operator_name) : ''}</div>
                    <span class="today-event-status ${statusClass}">${getStatusIcon(props.status)} ${getStatusText(props.status)}</span>
                </div>
            `;
        }).join('');
    }

    window.openEventFromSidebar = function(itemId) {
        const item = calendar.getEventById(itemId);
        if (item) { activeEvent = item; openDetailsModal(item); }
    };

    // ============================================================
    // 7. ФИЛЬТРАЦИЯ
    // ============================================================
    function getFilteredEvents() {
        let items = allItems.slice();

        const search = document.getElementById('eventSearch').value.trim().toLowerCase();
        const type = document.getElementById('typeFilter').value;
        const status = document.getElementById('statusFilter').value;
        const emergency = document.getElementById('emergencyFilter').value;
        const operator = operatorFilterEl ? operatorFilterEl.value : 'all';

        if (search) {
            items = items.filter(e => {
                const p = e.extendedProps;
                const text = (e.title + ' ' + (p.location_title || '') + ' ' + (p.city || '') + ' ' + (p.operator_name || '')).toLowerCase();
                return text.includes(search);
            });
        }

        if (type !== 'all') {
            items = items.filter(e => e.extendedProps.event_type === type);
        }

        if (status !== 'all') {
            if (status === 'quicklog') {
                items = items.filter(e => e.extendedProps.kind === 'quicklog');
            } else {
                items = items.filter(e => e.extendedProps.kind === 'event' && e.extendedProps.status === status);
            }
        }

        if (emergency === 'emergency') {
            items = items.filter(e => e.extendedProps.is_emergency);
        } else if (emergency === 'normal') {
            items = items.filter(e => !e.extendedProps.is_emergency);
        }

        if (operator !== 'all') {
            items = items.filter(e => e.extendedProps.operator_name === operator);
        }

        if (activeQuickFilter === 'today') {
            const today = formatLocalDate(new Date());
            items = items.filter(e => e.start && e.start.startsWith(today));
        } else if (activeQuickFilter === 'pending') {
            items = items.filter(e => e.extendedProps.kind === 'event' && (e.extendedProps.status === 'requested' || e.extendedProps.status === 'reviewing'));
        } else if (activeQuickFilter === 'emergency') {
            items = items.filter(e => e.extendedProps.is_emergency);
        } else if (activeQuickFilter === 'completed') {
            items = items.filter(e => (e.extendedProps.kind === 'event' && e.extendedProps.status === 'completed') || e.extendedProps.kind === 'quicklog');
        }

        return items;
    }

    // ============================================================
    // 8. СОЗДАНИЕ (запрос визита)
    // ============================================================
    function openCreateModal(date, time) {
        document.getElementById('modalTitle').textContent = '📅 Запросить визит';
        document.getElementById('modalSub').textContent = 'Дата: ' + formatReadableDate(date);
        document.getElementById('modalLocationOperator').value = '';
        document.getElementById('modalEventType').value = 'maintenance';
        document.getElementById('eventDatetime').value = date + 'T' + time;
        document.getElementById('isEmergency').checked = false;
        document.getElementById('emergencyComment').value = '';
        document.getElementById('emergencyGroup').style.display = 'none';
        document.getElementById('emergencyComment').required = false;
        hideFormError(document.getElementById('formError'));
        eventModal.classList.add('active');
    }

    window.closeModal = function() { eventModal.classList.remove('active'); };
    eventModal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });

    document.getElementById('isEmergency').addEventListener('change', function() {
        const group = document.getElementById('emergencyGroup');
        const comment = document.getElementById('emergencyComment');
        group.style.display = this.checked ? 'block' : 'none';
        comment.required = this.checked;
    });

    document.getElementById('eventForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const locOpId = document.getElementById('modalLocationOperator').value;
        const eventType = document.getElementById('modalEventType').value;
        const datetime = document.getElementById('eventDatetime').value;
        const isEmergency = document.getElementById('isEmergency').checked ? 1 : 0;
        const comment = document.getElementById('emergencyComment').value.trim();

        const errorEl = document.getElementById('formError');
        hideFormError(errorEl);

        if (!locOpId) { showFormError(errorEl, 'Выберите точку'); return; }
        if (!datetime) { showFormError(errorEl, 'Выберите дату и время'); return; }
        if (isEmergency && !comment) { showFormError(errorEl, 'Для срочного выезда укажите причину'); return; }

        const formData = new FormData();
        formData.append('action', 'request');
        formData.append('location_operator_id', locOpId);
        formData.append('event_type', eventType);
        formData.append('datetime', datetime.replace('T', ' ') + ':00');
        formData.append('is_emergency', isEmergency);
        if (comment) formData.append('comment', comment);

        const submitBtn = document.getElementById('modalSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправка...';
        showLoading(true);

        fetch('/api/installation.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    showToast(isEmergency ? '🚨 Срочный визит запрошен' : '✅ Визит запрошен', 'success');
                    calendar.refetchEvents();
                } else {
                    showFormError(errorEl, data.error || 'Ошибка создания запроса');
                }
            })
            .catch(err => showFormError(errorEl, 'Ошибка соединения: ' + err.message))
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Запросить визит';
                showLoading(false);
            });
    });

    // ============================================================
    // 9. ДЕТАЛИ
    // ============================================================
    function renderPhotosBlock(photos) {
        if (!photos || photos.length === 0) return '';
        const thumbs = photos.map((p, i) =>
            `<button type="button" onclick='openPhotoLightbox(${JSON.stringify(photos)}, ${i})' style="display:inline-block; width:70px; height:70px; margin:4px; padding:0; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb; cursor:pointer; background:none;">
                <img src="/${p}" style="width:100%; height:100%; object-fit:cover; display:block;" alt="Фото подтверждения">
            </button>`
        ).join('');
        return `<div class="detail-item" style="margin-bottom:18px;"><div class="detail-label">Фото подтверждения</div><div>${thumbs}</div></div>`;
    }

    // ===== ЛАЙТБОКС ФОТО =====
    let lightboxPhotos = [];
    let lightboxIndex = 0;

    window.openPhotoLightbox = function(photos, index) {
        lightboxPhotos = photos;
        lightboxIndex = index;
        renderLightbox();
        photoLightbox.classList.add('active');
    };

    function renderLightbox() {
        document.getElementById('lightboxImg').src = '/' + lightboxPhotos[lightboxIndex];
        document.getElementById('lightboxCounter').textContent = (lightboxIndex + 1) + ' / ' + lightboxPhotos.length;
        const multi = lightboxPhotos.length > 1;
        document.getElementById('lightboxPrev').style.display = multi ? 'flex' : 'none';
        document.getElementById('lightboxNext').style.display = multi ? 'flex' : 'none';
        document.getElementById('lightboxCounter').style.display = multi ? 'block' : 'none';
    }

    function lightboxShowPrev() {
        lightboxIndex = (lightboxIndex - 1 + lightboxPhotos.length) % lightboxPhotos.length;
        renderLightbox();
    }
    function lightboxShowNext() {
        lightboxIndex = (lightboxIndex + 1) % lightboxPhotos.length;
        renderLightbox();
    }

    window.closePhotoLightbox = function() {
        photoLightbox.classList.remove('active');
    };

    document.getElementById('lightboxClose').addEventListener('click', closePhotoLightbox);
    document.getElementById('lightboxPrev').addEventListener('click', lightboxShowPrev);
    document.getElementById('lightboxNext').addEventListener('click', lightboxShowNext);
    photoLightbox.addEventListener('click', function(e) {
        if (e.target === this) closePhotoLightbox();
    });

    function openDetailsModal(event) {
        const props = event.extendedProps;

        if (props.kind === 'quicklog') {
            document.getElementById('detailsTitle').textContent = '📝 Постфактум-отметка';
            document.getElementById('detailsSubtitle').textContent = props.location_title + (props.city ? ' • ' + props.city : '');
            let html = `
                <div class="detail-grid">
                    <div class="detail-item"><div class="detail-label">Тип</div><div class="detail-value">${escapeHtml(eventTypeLabels[props.event_type] || props.event_type)}</div></div>
                    <div class="detail-item"><div class="detail-label">Когда</div><div class="detail-value">${escapeHtml(formatDateTimeString(props.performed_at))}</div></div>
            `;
            if (ROLE === 'owner' && props.operator_name) {
                html += `<div class="detail-item"><div class="detail-label">Оператор</div><div class="detail-value">${escapeHtml(props.operator_name)}</div></div>`;
            }
            html += `</div>`;
            if (props.comment) {
                html += `<div class="detail-item" style="margin-bottom:18px;"><div class="detail-label">Комментарий</div><div class="detail-value">${escapeHtml(props.comment)}</div></div>`;
            }
            html += renderPhotosBlock(props.photos);
            html += `<div class="detail-actions"><button class="cal-btn-primary full" onclick="openRelatedPage()">📍 Открыть точку</button></div>`;
            document.getElementById('detailsContent').innerHTML = html;
            detailsModal.classList.add('active');
            return;
        }

        document.getElementById('detailsTitle').textContent = props.is_emergency ? '🚨 Срочный выезд' : ('📅 ' + (eventTypeLabels[props.event_type] || props.event_type));
        document.getElementById('detailsSubtitle').textContent = props.location_title + (props.city ? ' • ' + props.city : '');

        let statusClass = 'status-' + props.status;
        if (props.is_emergency) statusClass = 'status-emergency';
        let datetime = event.start ? formatDateTime(event.start) : '—';

        let html = `
            <div class="detail-grid">
                <div class="detail-item"><div class="detail-label">Локация</div><div class="detail-value">${escapeHtml(props.location_title || '—')}</div></div>
                <div class="detail-item"><div class="detail-label">Город</div><div class="detail-value">${escapeHtml(props.city || '—')}</div></div>
                <div class="detail-item"><div class="detail-label">Дата и время</div><div class="detail-value">${escapeHtml(datetime)}</div></div>
                <div class="detail-item"><div class="detail-label">Статус</div><div class="detail-value"><span class="today-event-status ${statusClass}">${getStatusIcon(props.status)} ${getStatusText(props.status)}</span></div></div>
        `;
        if (ROLE === 'owner') {
            html += `<div class="detail-item"><div class="detail-label">Оператор</div><div class="detail-value">${escapeHtml(props.operator_name || '—')}</div></div>`;
        } else {
            html += `<div class="detail-item"><div class="detail-label">Владелец</div><div class="detail-value">${escapeHtml(props.owner_name || '—')}</div></div>`;
        }
        html += `</div>`;

        if (props.proposed_datetime || props.confirmed_datetime) {
            html += `
                <div class="detail-item" style="margin-bottom:18px;">
                    <div class="detail-label">Время</div>
                    <div class="detail-value">
                        ${props.proposed_datetime ? 'Предложено: ' + formatDateTimeString(props.proposed_datetime) : ''}
                        ${props.confirmed_datetime ? '<br>Подтверждено: ' + formatDateTimeString(props.confirmed_datetime) : ''}
                    </div>
                </div>
            `;
        }

        if (props.is_emergency) {
            html += `
                <div class="emergency-reason">
                    <div class="emergency-reason-title">🚨 Причина срочности</div>
                    <div class="emergency-reason-text">${escapeHtml(props.emergency_comment || 'Причина не указана')}</div>
                </div>
            `;
        }

        html += renderPhotosBlock(props.photos);

        html += `<div class="detail-actions">`;
        html += `<button class="cal-btn-primary full" onclick="openRelatedPage()">${props.application_id ? '💬 Открыть заявку' : '📍 Открыть точку'}</button>`;

        const isRequester = String(props.requested_by) === String(USER_ID);
        const isPending = props.status === 'requested' || props.status === 'reviewing';

        if (isPending && !isRequester) {
            html += `<button class="cal-btn-primary" onclick="confirmActiveEvent()">✅ Подтвердить</button>`;
        }
        if (props.status !== 'completed' && props.status !== 'cancelled') {
            html += `<button class="cal-btn-primary" onclick="openRescheduleModal()">✏️ Изменить время</button>`;
        }
        if (props.status === 'confirmed') {
            html += `<button class="cal-btn-primary" onclick="completeActiveEvent()">✔️ Завершить</button>`;
        }
        if (props.status !== 'completed' && props.status !== 'cancelled') {
            html += `<button class="cal-btn-danger" onclick="cancelActiveEvent()">🗑️ Отменить</button>`;
        }
        html += `</div>`;

        document.getElementById('detailsContent').innerHTML = html;
        detailsModal.classList.add('active');
    }

    window.closeDetailsModal = function() { detailsModal.classList.remove('active'); };
    detailsModal.addEventListener('click', function(e) { if (e.target === this) closeDetailsModal(); });

    window.openRelatedPage = function() {
        if (!activeEvent) return;
        const props = activeEvent.extendedProps;
        if (props.application_id) {
            window.location.href = '/pages/application_chat.php?application_id=' + encodeURIComponent(props.application_id);
        } else if (props.location_id) {
            window.location.href = '/pages/location.php?id=' + encodeURIComponent(props.location_id);
        }
    };

    // ============================================================
    // 10. ИЗМЕНЕНИЕ ДАТЫ
    // ============================================================
    let rescheduleEventId = null;

    window.openRescheduleModal = function() {
        if (!activeEvent) return;
        rescheduleEventId = activeEvent.extendedProps.dbId;
        const currentDate = activeEvent.start ? new Date(activeEvent.start) : new Date();
        const dateStr = formatLocalDate(currentDate);
        const hours = String(currentDate.getHours()).padStart(2, '0');
        const minutes = String(currentDate.getMinutes()).padStart(2, '0');
        document.getElementById('rescheduleDatetime').value = dateStr + 'T' + hours + ':' + minutes;
        hideFormError(document.getElementById('rescheduleError'));
        rescheduleModal.classList.add('active');
    };

    window.closeRescheduleModal = function() {
        rescheduleModal.classList.remove('active');
        rescheduleEventId = null;
    };
    rescheduleModal.addEventListener('click', function(e) { if (e.target === this) closeRescheduleModal(); });

    document.getElementById('rescheduleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const rawDatetime = document.getElementById('rescheduleDatetime').value;
        const errorEl = document.getElementById('rescheduleError');
        hideFormError(errorEl);

        if (!rawDatetime) { showFormError(errorEl, 'Выберите новую дату и время'); return; }
        if (!rescheduleEventId) { showFormError(errorEl, 'Ошибка: событие не выбрано'); return; }

        const dt = new Date(rawDatetime);
        if (isNaN(dt.getTime())) { showFormError(errorEl, 'Некорректная дата и время'); return; }
        const formattedDatetime =
            dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0') + ' ' +
            String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0') + ':00';

        const formData = new FormData();
        formData.append('action', 'reschedule');
        formData.append('event_id', rescheduleEventId);
        formData.append('datetime', formattedDatetime);

        const submitBtn = document.getElementById('rescheduleSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Сохранение...';
        showLoading(true);

        fetch('/api/installation.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeRescheduleModal();
                    closeDetailsModal();
                    showToast('✅ Дата выезда обновлена', 'success');
                    calendar.refetchEvents();
                } else {
                    showFormError(errorEl, data.error || 'Ошибка изменения даты');
                }
            })
            .catch(err => showFormError(errorEl, 'Ошибка соединения: ' + err.message))
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Сохранить';
                showLoading(false);
            });
    });

    // ============================================================
    // 11. ДЕЙСТВИЯ (confirm / complete / cancel)
    // ============================================================
    function sendEventAction(action, eventId, onSuccess) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('event_id', eventId);
        showLoading(true);
        fetch('/api/installation.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) onSuccess();
                else showToast(data.error || 'Операция не выполнена', 'error');
            })
            .catch(err => showToast('Ошибка соединения: ' + err.message, 'error'))
            .finally(() => showLoading(false));
    }

    window.confirmActiveEvent = function() {
        if (!activeEvent) return;
        sendEventAction('confirm', activeEvent.extendedProps.dbId, function() {
            closeDetailsModal();
            showToast('✅ Визит подтверждён', 'success');
            calendar.refetchEvents();
        });
    };

    let completeEventId = null;

    window.completeActiveEvent = function() {
        if (!activeEvent) return;
        completeEventId = activeEvent.extendedProps.dbId;
        document.getElementById('completePhotos').value = '';
        hideFormError(document.getElementById('completeError'));
        document.getElementById('completeProgressWrap').style.display = 'none';
        document.getElementById('completeProgressBar').style.width = '0%';
        completeModal.classList.add('active');
    };

    window.closeCompleteModal = function() {
        completeModal.classList.remove('active');
        completeEventId = null;
    };
    completeModal.addEventListener('click', function(e) { if (e.target === this) closeCompleteModal(); });

    document.getElementById('completeForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!completeEventId) return;
        const errorEl = document.getElementById('completeError');
        hideFormError(errorEl);

        const submitBtn = document.getElementById('completeSubmitBtn');
        if (submitBtn.disabled) return; // защита от повторной отправки, пока идёт первая
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправка...';
        showLoading(true);

        const progressWrap = document.getElementById('completeProgressWrap');
        const progressBar = document.getElementById('completeProgressBar');
        const progressText = document.getElementById('completeProgressText');
        progressWrap.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.textContent = '0%';

        const formData = new FormData();
        formData.append('action', 'complete');
        formData.append('event_id', completeEventId);
        const photoFiles = document.getElementById('completePhotos').files;
        for (let i = 0; i < photoFiles.length; i++) {
            formData.append('photos[]', photoFiles[i]);
        }

        function resetButton() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Завершить';
            progressWrap.style.display = 'none';
            showLoading(false);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/installation.php');

        xhr.upload.addEventListener('progress', function(ev) {
            if (!ev.lengthComputable) return;
            const percent = Math.round((ev.loaded / ev.total) * 100);
            progressBar.style.width = percent + '%';
            progressText.textContent = percent + '%' + (percent === 100 ? ' · обработка на сервере...' : '');
        });

        xhr.onload = function() {
            resetButton();
            let data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (err) {
                showFormError(errorEl, 'Сервер вернул некорректный ответ');
                return;
            }
            if (data.success) {
                closeCompleteModal();
                closeDetailsModal();
                showToast('✅ Выезд завершён', 'success');
                if (data.photo_errors && data.photo_errors.length > 0) {
                    showToast('⚠️ Не все фото сохранились: ' + data.photo_errors.join('; '), 'warning');
                }
                calendar.refetchEvents();
            } else {
                showFormError(errorEl, data.error || 'Ошибка завершения');
            }
        };

        xhr.onerror = function() {
            resetButton();
            showFormError(errorEl, 'Ошибка соединения');
        };

        xhr.send(formData);
    });

    window.cancelActiveEvent = function() {
        if (!activeEvent) return;
        if (!confirm('Отменить этот выезд?')) return;
        sendEventAction('cancel', activeEvent.extendedProps.dbId, function() {
            closeDetailsModal();
            showToast('🗑️ Выезд отменён', 'success');
            calendar.refetchEvents();
        });
    };

    // ============================================================
    // 12. КОНТЕКСТНОЕ МЕНЮ
    // ============================================================
    function showContextMenu(e, event) {
        const props = event.extendedProps;
        contextMenu.dataset.eventId = props.dbId;

        const isQuicklog = props.kind === 'quicklog';
        const isRequester = String(props.requested_by) === String(USER_ID);
        const isPending = props.status === 'requested' || props.status === 'reviewing';

        document.getElementById('ctxConfirm').style.display = (!isQuicklog && isPending && !isRequester) ? 'block' : 'none';
        document.getElementById('ctxComplete').style.display = (!isQuicklog && props.status === 'confirmed') ? 'block' : 'none';
        document.getElementById('ctxDelete').style.display = (!isQuicklog && props.status !== 'completed' && props.status !== 'cancelled') ? 'block' : 'none';

        contextMenu.style.display = 'block';
        let left = e.clientX, top = e.clientY;
        const rect = contextMenu.getBoundingClientRect();
        if (left + rect.width > window.innerWidth) left = window.innerWidth - rect.width - 10;
        if (top + rect.height > window.innerHeight) top = window.innerHeight - rect.height - 10;
        contextMenu.style.left = Math.max(10, left) + 'px';
        contextMenu.style.top = Math.max(10, top) + 'px';
    }

    document.addEventListener('click', function(e) {
        if (!contextMenu.contains(e.target)) contextMenu.style.display = 'none';
    });

    document.getElementById('ctxView').addEventListener('click', function() {
        contextMenu.style.display = 'none';
        openRelatedPage();
    });
    document.getElementById('ctxConfirm').addEventListener('click', function() {
        contextMenu.style.display = 'none';
        confirmActiveEvent();
    });
    document.getElementById('ctxComplete').addEventListener('click', function() {
        contextMenu.style.display = 'none';
        completeActiveEvent();
    });
    document.getElementById('ctxDelete').addEventListener('click', function() {
        contextMenu.style.display = 'none';
        cancelActiveEvent();
    });

    // ============================================================
    // 13. ФИЛЬТРЫ UI
    // ============================================================
    function debounce(fn, delay) {
        let timer;
        return function() {
            clearTimeout(timer);
            const args = arguments;
            timer = setTimeout(() => fn.apply(null, args), delay);
        };
    }

    document.getElementById('eventSearch').addEventListener('input', debounce(function() {
        calendar.refetchEvents();
    }, 250));

    ['typeFilter', 'statusFilter', 'emergencyFilter'].forEach(function(id) {
        document.getElementById(id).addEventListener('change', function() {
            activeQuickFilter = null;
            removeActiveStats();
            calendar.refetchEvents();
        });
    });
    if (operatorFilterEl) {
        operatorFilterEl.addEventListener('change', function() { calendar.refetchEvents(); });
    }

    document.getElementById('statToday').addEventListener('click', function() { activateQuickFilter('today'); });
    document.getElementById('statPending').addEventListener('click', function() { activateQuickFilter('pending'); });
    document.getElementById('statEmergency').addEventListener('click', function() { activateQuickFilter('emergency'); });
    document.getElementById('statCompleted').addEventListener('click', function() { activateQuickFilter('completed'); });

    function activateQuickFilter(type) {
        if (activeQuickFilter === type) {
            activeQuickFilter = null;
            removeActiveStats();
        } else {
            activeQuickFilter = type;
            removeActiveStats();
            const map = { 'today': 'statToday', 'pending': 'statPending', 'emergency': 'statEmergency', 'completed': 'statCompleted' };
            const el = document.getElementById(map[type]);
            if (el) el.classList.add('active');
        }
        calendar.refetchEvents();
    }

    function removeActiveStats() {
        document.querySelectorAll('.stat-card').forEach(el => el.classList.remove('active'));
    }

    // ============================================================
    // 14. ОБНОВЛЕНИЕ / НОВЫЙ
    // ============================================================
    document.getElementById('historyBtn').addEventListener('click', function() {
        const params = new URLSearchParams();
        const type = document.getElementById('typeFilter').value;
        const status = document.getElementById('statusFilter').value;
        if (type !== 'all') params.set('event_type', type);
        if (status === 'quicklog') params.set('source', 'log');
        window.location.href = '/pages/service_history.php' + (params.toString() ? '?' + params.toString() : '');
    });

    document.getElementById('refreshCalendarBtn').addEventListener('click', function() {
        calendar.refetchEvents();
        showToast('Календарь обновлён', 'success');
    });

    document.getElementById('newEventBtn').addEventListener('click', function() {
        const now = new Date();
        openCreateModal(formatLocalDate(now), '10:00');
    });

    // ============================================================
    // 15. ESCAPE
    // ============================================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closeDetailsModal();
            closeRescheduleModal();
            closeCompleteModal();
            closePhotoLightbox();
            contextMenu.style.display = 'none';
        }
        if (photoLightbox.classList.contains('active')) {
            if (e.key === 'ArrowLeft') lightboxShowPrev();
            if (e.key === 'ArrowRight') lightboxShowNext();
        }
    });

    // ============================================================
    // 16. УВЕДОМЛЕНИЯ (шапка сайта)
    // ============================================================
    if (window.updateNotificationCount) {
        window.updateNotificationCount();
    }

});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>