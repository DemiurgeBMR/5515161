<?php
session_start();
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
$is_operator = ($user_id == $application['operator_id']);
$other_party = $is_operator ? $application['owner_name'] : $application['operator_name'];

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
    'cancelled' => '❌ Отменена'
];

$currentPublicStatus = $application['status']; // pending или cancelled
$cancelled_by = $application['cancelled_by'];
$canChangeCancel = ($currentPublicStatus === 'cancelled' && $cancelled_by == $user_id);

$displayStatus = $currentPublicStatus === 'cancelled' ? 'cancelled' : ($my_tag ? $my_tag : 'pending');

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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Чат по заявке — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* =========================================================
           ROOT (единый стиль с календарём)
        ========================================================= */
        :root {
            --primary: #e94560;
            --primary-dark: #d63852;
            --text: #202124;
            --text-light: #6b7280;
            --border: #e5e7eb;
            --background: #f6f7fb;
            --white: #ffffff;
            --blue: #3b82f6;
            --blue-bg: #eff6ff;
            --yellow: #f59e0b;
            --yellow-bg: #fffbeb;
            --green: #22c55e;
            --green-bg: #f0fdf4;
            --red: #ef4444;
            --red-bg: #fef2f2;
            --gray: #6b7280;
            --gray-bg: #f3f4f6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 8px 30px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.15);
            --radius: 14px;
            --sidebar-w: 300px;
        }
        body { background: var(--background); }

        /* =========================================================
           КОНТЕЙНЕР
        ========================================================= */
        .chat-container {
            max-width: 1180px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 15px;
            transition: .2s;
        }
        .back-link:hover { color: var(--primary); }

        /* =========================================================
           КАРТОЧКА ЧАТА — теперь двухколоночный layout
        ========================================================= */
        .chat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            align-items: stretch;
            height: min(720px, calc(100vh - 140px));
            min-height: 480px;
            position: relative;
        }

        /* =========================================================
           ОСНОВНАЯ КОЛОНКА (тред)
        ========================================================= */
        .chat-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        /* Компактная мобильная шапка треда: имя + кнопка "Детали" */
        .chat-main-topbar {
            display: none;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }
        .chat-main-topbar .who {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .chat-main-topbar .who .party-avatar {
            width: 34px;
            height: 34px;
            font-size: 13px;
        }
        .chat-main-topbar .who .name {
            font-size: 15px;
            font-weight: 800;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .details-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--gray-bg);
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            flex-shrink: 0;
            transition: .2s;
        }
        .details-toggle-btn:hover { background: var(--border); }
        .details-toggle-btn .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--yellow);
            flex-shrink: 0;
        }
        .details-toggle-btn .status-dot.dot-agreed,
        .details-toggle-btn .status-dot.dot-placed { background: var(--green); }
        .details-toggle-btn .status-dot.dot-cancelled { background: var(--red); }

        /* =========================================================
           БОКОВАЯ ПАНЕЛЬ (контекст заявки)
        ========================================================= */
        .chat-sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            border-left: 1px solid var(--border);
            background: #fbfbfd;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .sidebar-section {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }
        .sidebar-section:last-child { border-bottom: none; }
        .sidebar-section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--text-light);
            margin: 0 0 12px;
        }

        /* --- профиль собеседника в сайдбаре --- */
        .sidebar-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .party-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            font-size: 15px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sidebar-profile .info { min-width: 0; }
        .sidebar-profile .name {
            font-size: 15px;
            font-weight: 800;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-profile .meta {
            margin-top: 3px;
            font-size: 12.5px;
            color: var(--text-light);
        }
        .sidebar-profile .meta a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }
        .sidebar-profile .meta a:hover { text-decoration: underline; }

        /* --- статус в сайдбаре --- */
        .status-wrapper { position: relative; }
        .status-selector {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 2px 0;
        }
        .status-selector.inactive { cursor: default; opacity: 0.6; }
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
        }
        .status-pending { background: var(--yellow-bg); color: #b45309; }
        .status-negotiating { background: #fef3c7; color: #92400e; }
        .status-agreed { background: var(--green-bg); color: #15803d; }
        .status-placed { background: #dbeafe; color: #1d4ed8; }
        .status-cancelled { background: var(--red-bg); color: #dc2626; }
        .status-arrow {
            font-size: 13px;
            color: var(--text-light);
            margin-left: 2px;
        }
        .status-dropdown {
            display: none;
            position: absolute;
            left: 0;
            top: calc(100% + 4px);
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            padding: 6px;
            min-width: 180px;
            z-index: 900;
        }
        .status-dropdown .dropdown-item {
            padding: 8px 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            color: var(--text);
        }
        .status-dropdown .dropdown-item:hover { background: var(--gray-bg); }

        /* --- карточка события выезда в сайдбаре --- */
        .event-card {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .event-icon {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            background: var(--blue-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }
        .event-body {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .event-header {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .event-status {
            font-weight: 700;
            font-size: 12.5px;
        }
        .event-emergency {
            background: var(--red);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .event-datetime {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
        }
        .event-comment {
            background: var(--yellow-bg);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            border-left: 4px solid var(--yellow);
            color: #78350f;
        }
        .event-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 2px;
        }
        .event-empty {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }
        .btn-event {
            padding: 7px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 700;
            background: var(--primary);
            color: white;
            transition: .2s;
        }
        .btn-event:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-event.propose-btn { background: var(--blue); }
        .btn-event.emergency-btn { background: var(--red); }
        .btn-event.confirm-btn { background: var(--green); }
        .btn-event.reschedule-btn { background: var(--yellow); color: #78350f; }
        .btn-event.cancel-btn { background: var(--gray); }
        .btn-event.complete-btn { background: var(--green); }

        /* --- действия (уведомления / удалить) в сайдбаре --- */
        .sidebar-action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text);
        }
        .sidebar-action-row + .sidebar-action-row { border-top: 1px solid var(--border); }
        .sidebar-action-row.danger-link {
            cursor: pointer;
            color: #dc2626;
        }
        .sidebar-action-row.danger-link a {
            color: #dc2626;
            text-decoration: none;
            width: 100%;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 22px;
        }
        .slider:before {
            content: "";
            position: absolute;
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(18px); }

        /* =========================================================
           СООБЩЕНИЯ
        ========================================================= */
        .chat-messages {
            padding: 20px 24px;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            background: #fafafa;
            scroll-behavior: smooth;
        }
        .chat-messages::-webkit-scrollbar { width: 8px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: #d7dae0; border-radius: 10px; }
        .chat-messages::-webkit-scrollbar-thumb:hover { background: #b7bcc4; }

        .date-separator {
            text-align: center;
            margin: 4px 0 18px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .date-separator span {
            display: inline-block;
            background: var(--white);
            border: 1px solid var(--border);
            color: var(--text-light);
            font-size: 12px;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .date-separator:first-child { margin-top: 0; }

        .message {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            margin-bottom: 14px;
            animation: msgIn .2s ease;
        }
        .message:last-child { margin-bottom: 0; }
        .message.grouped { margin-top: -8px; }
        .message.own { justify-content: flex-end; }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--blue-bg);
            color: var(--blue);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
        }
        .message-body {
            display: flex;
            flex-direction: column;
            max-width: 75%;
        }
        .message.own .message-body { align-items: flex-end; }
        .message .sender {
            font-weight: 700;
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            padding: 0 4px;
        }
        .message .sender .time {
            font-weight: 400;
            font-size: 11px;
            color: #9ca3af;
            margin-left: 8px;
        }
        .message .text {
            display: inline-block;
            padding: 9px 14px;
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            background: var(--white);
            border: 1px solid var(--border);
            font-size: 14px;
            line-height: 1.5;
            word-break: break-word;
            box-shadow: 0 1px 2px rgba(0,0,0,.03);
        }
        .message.own .text {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            border-radius: 16px;
            border-bottom-right-radius: 4px;
        }
        .msg-meta {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 3px;
            padding: 0 4px;
            font-size: 11px;
            color: #9ca3af;
        }
        .read-receipt { font-size: 13px; letter-spacing: -2px; color: #9ca3af; }
        .read-receipt.read { color: var(--blue); letter-spacing: -1px; }
        .chat-empty {
            color: var(--text-light);
            text-align: center;
            padding: 50px 0;
            font-size: 14px;
        }
        .chat-empty .chat-empty-icon {
            font-size: 30px;
            display: block;
            margin-bottom: 8px;
            opacity: .6;
        }

        /* Место под будущий индикатор "печатает..." — зарезервировано,
           заполняется следующим шагом (см. #typingIndicator) */
        .typing-indicator {
            display: none;
            align-items: center;
            gap: 6px;
            padding: 0 24px 8px;
            font-size: 12.5px;
            color: var(--text-light);
            flex-shrink: 0;
        }
        .typing-indicator.show { display: flex; }

        /* =========================================================
           ИНПУТ
        ========================================================= */
        .chat-input {
            padding: 14px 24px 20px;
            border-top: 1px solid var(--border);
            background: var(--white);
            flex-shrink: 0;
        }
        .chat-input form {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            background: var(--gray-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 6px 6px 6px 18px;
            transition: .2s;
        }
        .chat-input form:focus-within {
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(233,69,96,.08);
        }
        .chat-input textarea {
            flex: 1;
            border: none;
            background: transparent;
            resize: none;
            min-height: 24px;
            max-height: 140px;
            padding: 8px 0;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            line-height: 1.4;
        }
        .chat-input button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 16px;
            cursor: pointer;
            transition: .2s;
            flex-shrink: 0;
        }
        .chat-input button:hover { background: var(--primary-dark); transform: scale(1.05); }
        .chat-input button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .chat-closed {
            color: var(--text-light);
            font-style: italic;
            padding: 10px 0 0;
            text-align: center;
        }

        /* =========================================================
           МОДАЛКА (единый стиль)
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
            background: white;
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
            display: flex;
            align-items: center;
            justify-content: center;
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
            background: white;
            font-size: 14px;
            outline: none;
            transition: .2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(233,69,96,.08);
        }
        textarea.form-control { resize: vertical; }
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
           SIDEBAR BACKDROP (только мобилка)
        ========================================================= */
        .sidebar-backdrop {
            display: none;
            position: absolute;
            inset: 0;
            background: rgba(17,24,39,.45);
            z-index: 190;
        }
        .sidebar-backdrop.show { display: block; }

        /* =========================================================
           RESPONSIVE — ниже 768px сайдбар становится drawer
        ========================================================= */
        @media (max-width: 768px) {
            .chat-card { height: min(88vh, 760px); }
            .chat-main-topbar { display: flex; }
            .chat-sidebar {
                position: absolute;
                top: 0;
                right: 0;
                bottom: 0;
                width: min(86%, 340px);
                border-left: 1px solid var(--border);
                box-shadow: var(--shadow-lg);
                transform: translateX(100%);
                transition: transform .25s ease;
                z-index: 200;
            }
            .chat-sidebar.open { transform: translateX(0); }
            .sidebar-close-btn {
                display: inline-flex;
            }
        }
        @media (min-width: 769px) {
            .sidebar-close-btn { display: none; }
        }

        @media (max-width: 640px) {
            .chat-container { padding: 0 12px 30px; }
            .chat-messages { padding: 14px; }
            .chat-input { padding: 12px 16px; }
            .modal-box { padding: 18px; }
            .event-actions { flex-direction: column; }
            .event-actions .btn-event { width: 100%; }
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }
        .sidebar-header-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text);
        }
        .sidebar-close-btn {
            border: 0;
            background: var(--gray-bg);
            width: 32px;
            height: 32px;
            border-radius: 9px;
            cursor: pointer;
            font-size: 19px;
            color: var(--text-light);
            align-items: center;
            justify-content: center;
        }
        .sidebar-close-btn:hover { background: var(--border); }

        /* --- свёрнутая "таблетка" для подтверждённого события --- */
        .event-summary-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            background: var(--green-bg);
            border: 1px solid transparent;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #15803d;
            text-align: left;
            font-family: inherit;
            transition: .15s;
        }
        .event-summary-pill:hover { background: #dcfce7; }
        .event-summary-icon { flex-shrink: 0; }
        .event-summary-text { flex: 1; min-width: 0; }
        .event-summary-chevron { flex-shrink: 0; font-size: 11px; opacity: .7; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="chat-container">
    <a href="<?php echo $backUrl; ?>" class="back-link">← Назад к списку заявок</a>

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

            <!-- статус заявки -->
            <div class="sidebar-section">
                <p class="sidebar-section-title">Статус</p>
                <div class="status-wrapper">
                    <div class="status-selector <?php echo ($currentPublicStatus === 'cancelled' && !$canChangeCancel) ? 'inactive' : ''; ?>" id="statusSelector" data-active="<?php echo ($currentPublicStatus !== 'cancelled' || $canChangeCancel) ? '1' : '0'; ?>">
                        <span class="status-badge status-<?php echo $displayStatus; ?>" id="currentStatusBadge">
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
                <?php elseif ($currentPublicStatus !== 'cancelled' && $currentPublicStatus !== 'placed'): ?>
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
                    <a href="/pages/delete_application.php?id=<?php echo $application['id']; ?>" onclick="return confirm('Удалить заявку и всю переписку безвозвратно?')">🗑️ Удалить чат</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО ДЛЯ ДАТЫ -->
<div class="modal-overlay" id="dateModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="modalTitle">📅 Выберите дату и время</h3>
                <p class="modal-subtitle">Укажите время выезда</p>
            </div>
            <button class="close-btn" onclick="closeDateModal()">×</button>
        </div>
        <form id="dateForm">
            <div class="form-group">
                <label class="form-label" for="eventDatetime">Дата и время</label>
                <input type="datetime-local" id="eventDatetime" class="form-control" required>
            </div>
            <div id="emergencyCommentGroup" style="display: none;" class="form-group">
                <label class="form-label" for="emergencyComment">Комментарий (причина срочного выезда)</label>
                <textarea id="emergencyComment" class="form-control" rows="3" placeholder="Опишите проблему..."></textarea>
            </div>
            <input type="hidden" id="modalAction" value="propose">
            <input type="hidden" id="modalEventId" value="0">
            <div class="modal-buttons">
                <button type="button" class="btn-secondary" onclick="closeDateModal()">Отмена</button>
                <button type="submit" class="btn-primary">Отправить</button>
            </div>
        </form>
    </div>
</div>

<!-- TOAST-контейнер -->
<div class="toast-container" id="toastContainer"></div>

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
    var userId = <?php echo $user_id; ?>;
    var lastMessageId = <?php echo !empty($messages) ? end($messages)['id'] : 0; ?>;

    var operatorId = <?php echo $application['operator_id']; ?>;
    var operatorName = '<?php echo addslashes($application['operator_name']); ?>';
    var ownerName = '<?php echo addslashes($application['owner_name']); ?>';

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
        currentBadge.className = 'status-badge status-' + newStatus;
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
            html += '<div class="dropdown-item status-option" data-status="' + status + '" style="' + (isActive ? 'background:#f0f0f0;' : '') + '">' +
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
                fetch('/api/change_status.php?application_id=' + applicationId + '&status=' + newStatus + '&ajax=1')
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