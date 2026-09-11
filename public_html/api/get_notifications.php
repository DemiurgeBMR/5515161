<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

$pdo = getDbConnection();

/**
 * Добавляет иконку/категорию из общего справочника (config.php) к сырой
 * строке notifications — единая точка правды для UI вместо ручных emoji,
 * вписанных в текст сообщения при создании (как было раньше).
 */
function enrichNotification($row) {
    $meta = NOTIFICATION_META[$row['type']] ?? ['category' => $row['category'], 'icon' => 'ℹ️'];
    $row['icon'] = $meta['icon'];
    $row['is_unread'] = $row['read_at'] === null;
    $row['data'] = $row['data'] ? json_decode($row['data'], true) : null;
    unset($row['read_at']);
    return $row;
}

if ($action === 'count') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id]);
    echo json_encode(['count' => (int)$stmt->fetchColumn()]);
    exit;
}

// Непрочитанные после last_id — источник для тостов (единый поллер вместо
// прежних трёх независимых: бейдж/тосты уведомлений/тосты чата).
if ($action === 'list') {
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    $stmt = $pdo->prepare("
        SELECT * FROM notifications
        WHERE user_id = ? AND id > ? AND read_at IS NULL
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$user_id, $last_id]);
    $notifications = array_map('enrichNotification', $stmt->fetchAll());

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $countStmt->execute([$user_id]);

    echo json_encode([
        'notifications' => $notifications,
        'unread_count'  => (int) $countStmt->fetchColumn(),
    ]);
    exit;
}

// Последние N уведомлений (независимо от статуса прочтения) — превью в
// выпадающей панели колокольчика.
if ($action === 'recent') {
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit");
    $stmt->execute([$user_id]);
    $notifications = array_map('enrichNotification', $stmt->fetchAll());
    echo json_encode(['notifications' => $notifications]);
    exit;
}

if ($action === 'mark_read') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL");
        $stmt->execute([$id, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$user_id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
