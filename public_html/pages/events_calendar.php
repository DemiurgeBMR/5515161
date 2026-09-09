<?php
session_start();
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
    <style>
        :root {
            --primary: #e94560;
            --primary-dark: #d63852;
            --text: #f2f2f5;
            --text-light: #9a9aa5;
            --border: #2a2a33;
            --background: #0b0b0f;
            --white: #16161c;
            --blue: #5b9bf7;
            --blue-bg: rgba(59, 130, 246, 0.15);
            --yellow: #f5a623;
            --yellow-bg: rgba(245, 158, 11, 0.15);
            --green: #2ecc71;
            --green-bg: rgba(34, 197, 94, 0.15);
            --red: #ff6b6b;
            --red-bg: rgba(239, 68, 68, 0.15);
            --gray: #9a9aa5;
            --gray-bg: #1c1c24;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 8px 30px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.6);
            --radius: 14px;
        }
        body { background: var(--background); }
        .calendar-container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 25px 25px 50px;
        }
        /* =========================================================
           HEADER
        ========================================================= */
        .calendar-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }
        .calendar-title-block { min-width: 0; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
            transition: .2s;
        }
        .back-link:hover { color: var(--primary); }
        .calendar-title {
            margin: 0;
            font-size: 30px;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.5px;
        }
        .calendar-subtitle {
            margin: 6px 0 0;
            color: var(--text-light);
            font-size: 14px;
        }
        /* =========================================================
           ACTIONS
        ========================================================= */
        .calendar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .action-btn {
            border: 1px solid var(--border);
            background: var(--white);
            height: 42px;
            padding: 0 15px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            transition: background .2s, border .2s, transform .2s, box-shadow .2s;
        }
        .action-btn:hover {
            border-color: var(--border-strong, #3a3a45);
            box-shadow: var(--shadow-sm);
        }
        .action-btn:active { transform: scale(.98); }
        .action-btn.primary {
            color: white;
            background: var(--primary);
            border-color: var(--primary);
        }
        .action-btn.primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        /* =========================================================
           STATS
        ========================================================= */
        .calendar-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: transform .2s, box-shadow .2s, border .2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .stat-card.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(233,69,96,.08);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            font-size: 22px;
        }
        .stat-icon.today { background: var(--blue-bg); }
        .stat-icon.pending { background: var(--yellow-bg); }
        .stat-icon.emergency { background: var(--red-bg); }
        .stat-icon.completed { background: var(--green-bg); }
        .stat-info { min-width: 0; }
        .stat-number {
            font-size: 25px;
            font-weight: 800;
            line-height: 1.1;
            color: var(--text);
        }
        .stat-label {
            margin-top: 3px;
            font-size: 13px;
            color: var(--text-light);
        }
        /* =========================================================
           TOOLBAR
        ========================================================= */
        .calendar-toolbar {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 13px 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }
        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-box { position: relative; }
        .search-box input {
            width: 280px;
            height: 40px;
            padding: 0 13px 0 38px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--gray-bg);
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: .2s;
        }
        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(233,69,96,.08);
        }
        .search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            pointer-events: none;
        }
        .filter-select {
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0 35px 0 12px;
            background: var(--gray-bg);
            color: var(--text);
            font-size: 14px;
            cursor: pointer;
            outline: none;
        }
        /* =========================================================
           LAYOUT
        ========================================================= */
        .calendar-layout {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 15px;
            align-items: start;
        }
        /* =========================================================
           TODAY SIDEBAR
        ========================================================= */
        .today-sidebar {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .today-sidebar-header {
            padding: 18px;
            border-bottom: 1px solid var(--border);
        }
        .today-sidebar-title {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
        }
        .today-sidebar-date {
            margin-top: 4px;
            font-size: 13px;
            color: var(--text-light);
        }
        .today-events {
            padding: 10px;
            max-height: 680px;
            overflow-y: auto;
        }
        .today-empty {
            padding: 35px 15px;
            text-align: center;
            color: var(--text-light);
            font-size: 14px;
        }
        .today-event {
            position: relative;
            padding: 12px;
            margin-bottom: 8px;
            border: 1px solid var(--border);
            border-radius: 11px;
            cursor: pointer;
            transition: .2s;
        }
        .today-event:last-child { margin-bottom: 0; }
        .today-event:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }
        .today-event-time {
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .today-event-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.35;
        }
        .today-event-city {
            margin-top: 3px;
            font-size: 12px;
            color: var(--text-light);
        }
        .today-event-status {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .status-confirmed { background: var(--blue-bg); color: #8ab8fb; }
        .status-proposed { background: var(--yellow-bg); color: #ffcf7a; }
        .status-completed { background: var(--green-bg); color: #6ee7a0; }
        .status-cancelled { background: var(--gray-bg); color: var(--gray); }
        .status-emergency { background: var(--red-bg); color: #ff8a9b; }
        /* =========================================================
           CALENDAR BOX
        ========================================================= */
        .calendar-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow-sm);
            min-width: 0;
            position: relative;
        }
        #calendar { min-height: 680px; }
        /* =========================================================
           FULLCALENDAR OVERRIDES
        ========================================================= */
        .fc { font-family: inherit, Arial, sans-serif; }
        .fc .fc-toolbar { margin-bottom: 18px; }
        .fc .fc-toolbar-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--text);
        }
        .fc .fc-button {
            background: var(--gray-bg);
            border: 1px solid var(--border);
            color: var(--text);
            box-shadow: none;
            font-weight: 700;
            border-radius: 9px;
            padding: 7px 12px;
        }
        .fc .fc-button:hover {
            background: var(--border);
            border-color: var(--border-strong, #3a3a45);
            color: var(--text);
        }
        .fc .fc-button-primary:not(:disabled).fc-button-active,
        .fc .fc-button-primary:not(:disabled):active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .fc .fc-today-button {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .fc .fc-today-button:hover { background: var(--primary-dark); }
        .fc .fc-col-header-cell { background: var(--gray-bg); }
        .fc, .fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid {
            border-color: var(--border);
        }
        .fc-daygrid-day, .fc-timegrid-slot-lane { background: var(--white); }
        .fc .fc-daygrid-day.fc-day-other { background: var(--background); }
        .fc-timegrid-axis, .fc-timegrid-slot-label { color: var(--text-light); }
        .fc-scrollgrid-sync-inner { color: var(--text); }
        .fc .fc-col-header-cell-cushion {
            color: var(--text-light);
            font-size: 12px;
            font-weight: 800;
            padding: 10px 5px;
        }
        .fc .fc-daygrid-day-number {
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
            padding: 8px;
        }
        .fc .fc-day-today {
            background: rgba(233,69,96,.035) !important;
        }
        .fc .fc-day-today .fc-daygrid-day-number {
            background: var(--primary);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 4px;
        }
        .fc .fc-daygrid-day-frame { min-height: 115px; }
        .fc .fc-daygrid-event {
            border: 0;
            border-radius: 7px;
            margin: 2px 4px;
            padding: 2px 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            overflow: hidden;
        }
        .fc .fc-event-title { font-weight: 700; }
        .fc .fc-event-time { font-weight: 800; }
        .fc .fc-timegrid-slot { height: 42px; }
        .fc .fc-timegrid-event {
            border-radius: 8px;
            border: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .fc .fc-timegrid-event .fc-event-main { padding: 7px; }
        .event-confirmed {
            background: #3b82f6 !important;
            border-color: #3b82f6 !important;
            color: white !important;
        }
        .event-proposed {
            background: #f59e0b !important;
            border-color: #f59e0b !important;
            color: white !important;
        }
        .event-completed {
            background: #22c55e !important;
            border-color: #22c55e !important;
            color: white !important;
            opacity: .78;
        }
        .event-cancelled {
            background: #9ca3af !important;
            border-color: #9ca3af !important;
            color: white !important;
            opacity: .55;
        }
        .event-emergency {
            box-shadow: 0 0 0 2px rgba(239,68,68,.45), 0 3px 10px rgba(239,68,68,.25);
            font-weight: 800 !important;
        }
        .event-emergency::before { content: "🚨 "; }

        /* =========================================================
           LOADING OVERLAY
        ========================================================= */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(11,11,15,0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            border-radius: var(--radius);
            backdrop-filter: blur(2px);
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* =========================================================
           MODAL
        ========================================================= */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17,24,39,.55);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 10000;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            width: min(520px, 100%);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            background: var(--white);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 25px;
            box-shadow: var(--shadow-lg);
            animation: modalIn .2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(10px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }
        .modal-title {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            color: var(--text);
        }
        .modal-subtitle {
            margin: 5px 0 0;
            color: var(--text-light);
            font-size: 13px;
        }
        .close-btn {
            border: 0;
            background: var(--gray-bg);
            width: 36px;
            height: 36px;
            border-radius: 9px;
            cursor: pointer;
            font-size: 22px;
            color: var(--text-light);
            flex: 0 0 36px;
        }
        .close-btn:hover { background: var(--border); }
        .form-group { margin-bottom: 17px; }
        .form-label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 800;
            color: var(--text);
        }
        .form-control {
            width: 100%;
            box-sizing: border-box;
            min-height: 44px;
            padding: 10px 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--gray-bg);
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: .2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(233,69,96,.08);
        }
        textarea.form-control { resize: vertical; }
        .emergency-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 13px;
            border: 1px solid rgba(239, 68, 68, 0.4);
            background: var(--red-bg);
            border-radius: 10px;
            cursor: pointer;
        }
        .emergency-toggle input { width: 18px; height: 18px; accent-color: var(--red); }
        .emergency-toggle span {
            font-size: 14px;
            font-weight: 800;
            color: #ff8a9b;
        }
        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }
        .modal-buttons button {
            flex: 1;
            height: 44px;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            font-weight: 800;
            font-size: 14px;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary {
            background: var(--gray-bg);
            color: var(--text);
        }
        .btn-secondary:hover { background: var(--border); }
        .btn-danger {
            background: var(--red);
            color: white;
        }
        /* =========================================================
           DETAILS
        ========================================================= */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }
        .detail-item {
            padding: 12px;
            border-radius: 10px;
            background: var(--gray-bg);
            border: 1px solid var(--border);
        }
        .detail-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--text-light);
            font-weight: 800;
        }
        .detail-value {
            margin-top: 4px;
            font-size: 14px;
            color: var(--text);
            font-weight: 700;
            line-height: 1.35;
        }
        .emergency-reason {
            padding: 13px;
            border-radius: 10px;
            background: var(--red-bg);
            border: 1px solid rgba(239, 68, 68, 0.4);
            margin-bottom: 18px;
        }
        .emergency-reason-title {
            color: #ff8a9b;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        .emergency-reason-text {
            font-size: 14px;
            color: var(--text);
            line-height: 1.45;
        }
        .detail-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 9px;
        }
        .detail-actions button {
            min-height: 43px;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            font-weight: 800;
            font-size: 13px;
        }
        .detail-actions .full { grid-column: 1 / -1; }
        /* =========================================================
           CONTEXT MENU
        ========================================================= */
        .context-menu {
            position: fixed;
            display: none;
            min-width: 210px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 11px;
            box-shadow: var(--shadow-lg);
            padding: 6px;
            z-index: 20000;
        }
        .menu-item {
            padding: 10px 12px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }
        .menu-item:hover { background: var(--gray-bg); }
        .menu-item.danger { color: var(--red); }
        .menu-item.danger:hover { background: var(--red-bg); }
        /* =========================================================
           TOAST
        ========================================================= */
        .toast-container {
            position: fixed;
            bottom: 25px;
            right: 25px;
            z-index: 50000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            min-width: 280px;
            max-width: 420px;
            padding: 13px 16px;
            background: #111827;
            color: white;
            border-radius: 11px;
            box-shadow: var(--shadow-lg);
            font-size: 14px;
            font-weight: 700;
            animation: toastIn .25s ease;
        }
        .toast.success { background: #15803d; }
        .toast.error { background: #dc2626; }
        .toast.warning { background: #b45309; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* =========================================================
           RESPONSIVE
        ========================================================= */
        @media (max-width: 1100px) {
            .calendar-layout { grid-template-columns: 1fr; }
            .today-sidebar { order: 2; }
            .today-events { max-height: 280px; }
        }
        @media (max-width: 850px) {
            .calendar-stats { grid-template-columns: repeat(2, 1fr); }
            .calendar-toolbar {
                align-items: stretch;
                flex-direction: column;
            }
            .toolbar-left, .toolbar-right { width: 100%; }
            .search-box { flex: 1; }
            .search-box input { width: 100%; }
        }
        @media (max-width: 600px) {
            .calendar-container { padding: 15px 10px 30px; }
            .calendar-header { flex-direction: column; }
            .calendar-title { font-size: 24px; }
            .calendar-actions { width: 100%; }
            .calendar-actions .action-btn { flex: 1; }
            .calendar-stats {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .stat-card { padding: 12px; }
            .stat-icon {
                width: 38px;
                height: 38px;
                flex-basis: 38px;
                font-size: 17px;
            }
            .stat-number { font-size: 20px; }
            .stat-label { font-size: 11px; }
            .calendar-card { padding: 7px; }
            .fc .fc-toolbar {
                flex-wrap: wrap;
                gap: 8px;
            }
            .fc .fc-toolbar-title { font-size: 17px; }
            .fc .fc-button {
                padding: 6px 8px;
                font-size: 12px;
            }
            .fc .fc-daygrid-day-frame { min-height: 85px; }
            .fc .fc-daygrid-event { font-size: 10px; }
            .detail-grid { grid-template-columns: 1fr; }
            .modal-box { padding: 18px; border-radius: 15px; }
        }
        /* Принудительные стили для кнопок в модалках */
.modal-buttons .btn-secondary {
    background: var(--gray-bg) !important;
    color: var(--text) !important;
}
.modal-buttons .btn-secondary:hover {
    background: var(--border) !important;
}
.modal-buttons .btn-primary {
    background: var(--primary) !important;
    color: white !important;
}
.modal-buttons .btn-primary:hover {
    background: var(--primary-dark) !important;
}

        /* =========================================================
           ПОСТФАКТУМ-ОТМЕТКИ (machine_service_log в том же календаре)
        ========================================================= */
        .event-quicklog {
            background: #95a5a6 !important;
            border-color: #95a5a6 !important;
            color: white !important;
            opacity: .8;
            font-style: italic;
        }
        .status-quicklog { background: var(--gray-bg); color: var(--text-light); }
        .filter-select#operatorFilter { min-width: 170px; }

    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="calendar-container">

    <div class="calendar-header">
        <div class="calendar-title-block">
            <a href="<?php echo $backLink; ?>" class="back-link">← Назад</a>
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
            <div class="search-box">
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
<div class="modal-overlay" id="eventModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="modalTitle">Новый выезд</h3>
                <p class="modal-subtitle" id="modalSub">Выберите точку и укажите время</p>
            </div>
            <button type="button" class="close-btn" onclick="closeModal()">×</button>
        </div>
        <form id="eventForm">
            <div class="form-group">
                <label class="form-label" for="modalLocationOperator">Точка *</label>
                <select id="modalLocationOperator" class="form-control" required>
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
            <div class="form-group">
                <label class="form-label" for="modalEventType">Тип визита *</label>
                <select id="modalEventType" class="form-control" required>
                    <option value="maintenance">Плановое обслуживание</option>
                    <option value="restock">Пополнение товара</option>
                    <option value="repair">Ремонт</option>
                    <option value="installation">Установка</option>
                    <option value="removal">Демонтаж</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="eventDatetime">Дата и время *</label>
                <input type="datetime-local" id="eventDatetime" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="emergency-toggle" for="isEmergency">
                    <input type="checkbox" id="isEmergency">
                    <span>🚨 Срочный выезд</span>
                </label>
            </div>
            <div class="form-group" id="emergencyGroup" style="display:none;">
                <label class="form-label" for="emergencyComment">Причина срочности *</label>
                <textarea id="emergencyComment" class="form-control" rows="3" placeholder="Опишите проблему или причину срочного выезда..."></textarea>
            </div>
            <div id="formError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="modal-buttons">
                <button type="button" class="btn-secondary" onclick="closeModal()">Отмена</button>
                <button type="submit" class="btn-primary" id="modalSubmitBtn">Запросить визит</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== МОДАЛКА ДЕТАЛЕЙ ===== -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="detailsTitle">Выезд</h3>
                <p class="modal-subtitle" id="detailsSubtitle">—</p>
            </div>
            <button type="button" class="close-btn" onclick="closeDetailsModal()">×</button>
        </div>
        <div id="detailsContent"></div>
    </div>
</div>

<!-- ===== МОДАЛКА ИЗМЕНЕНИЯ ДАТЫ ===== -->
<div class="modal-overlay" id="rescheduleModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">✏️ Изменить время</h3>
                <p class="modal-subtitle">Выберите новую дату и время</p>
            </div>
            <button type="button" class="close-btn" onclick="closeRescheduleModal()">×</button>
        </div>
        <form id="rescheduleForm">
            <div class="form-group">
                <label class="form-label" for="rescheduleDatetime">Новая дата и время *</label>
                <input type="datetime-local" id="rescheduleDatetime" class="form-control" required>
            </div>
            <div id="rescheduleError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="modal-buttons">
                <button type="button" class="btn-secondary" onclick="closeRescheduleModal()">Отмена</button>
                <button type="submit" class="btn-primary" id="rescheduleSubmitBtn">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== МОДАЛКА ЗАВЕРШЕНИЯ ВЫЕЗДА ===== -->
<div class="modal-overlay" id="completeModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">✔️ Завершить выезд</h3>
                <p class="modal-subtitle">Можно приложить фото подтверждения</p>
            </div>
            <button type="button" class="close-btn" onclick="closeCompleteModal()">×</button>
        </div>
        <form id="completeForm">
            <div class="form-group">
                <label class="form-label" for="completePhotos">Фото подтверждения</label>
                <input type="file" id="completePhotos" class="form-control" accept="image/*" multiple>
                <div style="color:#888; font-size:12px; margin-top:4px;">Необязательно, можно выбрать несколько фото</div>
            </div>
            <div id="completeProgressWrap" style="display:none; margin-bottom:14px;">
                <div style="background:#f3f4f6; border-radius:20px; overflow:hidden; height:8px;">
                    <div id="completeProgressBar" style="background:var(--primary); height:100%; width:0%; transition:width .15s;"></div>
                </div>
                <div id="completeProgressText" style="color:#888; font-size:12px; margin-top:4px;">0%</div>
            </div>
            <div id="completeError" style="display:none; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:9px; font-size:13px; font-weight:700;"></div>
            <div class="modal-buttons">
                <button type="button" class="btn-secondary" onclick="closeCompleteModal()">Отмена</button>
                <button type="submit" class="btn-primary" id="completeSubmitBtn">Завершить</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== ЛАЙТБОКС ФОТО ===== -->
<div class="modal-overlay" id="photoLightbox" style="background: rgba(17,24,39,.9); z-index: 30000;">
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
<div class="toast-container" id="toastContainer"></div>

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
        const toast = document.createElement('div');
        toast.className = 'toast ' + (type || '');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
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
                        if (Number(ev.is_emergency) === 1) classes.push('event-emergency');
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
            html += `<div class="detail-actions"><button class="btn-primary full" onclick="openRelatedPage()">📍 Открыть точку</button></div>`;
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
        html += `<button class="btn-primary full" onclick="openRelatedPage()">${props.application_id ? '💬 Открыть заявку' : '📍 Открыть точку'}</button>`;

        const isRequester = String(props.requested_by) === String(USER_ID);
        const isPending = props.status === 'requested' || props.status === 'reviewing';

        if (isPending && !isRequester) {
            html += `<button class="btn-primary" onclick="confirmActiveEvent()">✅ Подтвердить</button>`;
        }
        if (props.status !== 'completed' && props.status !== 'cancelled') {
            html += `<button class="btn-primary" onclick="openRescheduleModal()">✏️ Изменить время</button>`;
        }
        if (props.status === 'confirmed') {
            html += `<button class="btn-primary" onclick="completeActiveEvent()">✔️ Завершить</button>`;
        }
        if (props.status !== 'completed' && props.status !== 'cancelled') {
            html += `<button class="btn-danger" onclick="cancelActiveEvent()">🗑️ Отменить</button>`;
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