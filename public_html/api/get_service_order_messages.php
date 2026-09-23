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
$isAdmin = ($_SESSION['user_role'] ?? null) === 'admin';
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$last_id = isset($_GET['last_id']) ? (int) $_GET['last_id'] : 0;

if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDbConnection();
rr_enforce_rate_limit($pdo, 'get_service_order_messages:' . $user_id, 60, 60);

$stmt = $pdo->prepare("SELECT user_id FROM service_orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || (!$isAdmin && $order['user_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

notify_mark_link_read($pdo, $user_id, '/pages/service_order_chat.php?order_id=' . $order_id);

$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM service_order_messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.service_order_id = ? AND m.id > ?
    ORDER BY m.id ASC
");
$stmt->execute([$order_id, $last_id]);
$rows = $stmt->fetchAll();

$messages = array_map(function ($m) {
    return [
        'id' => (int) $m['id'],
        'sender_id' => (int) $m['sender_id'],
        'sender_name' => $m['sender_name'],
        'message' => $m['message'],
        'is_system' => (bool) $m['is_system'],
        'created_at' => strtotime($m['created_at']),
        'attachment_name' => $m['attachment_name'],
        'attachment_size' => $m['attachment_size'] !== null ? (int) $m['attachment_size'] : null,
        'attachment_type' => $m['attachment_type'],
        'attachment_url' => $m['attachment_path'] ? '/api/download_service_order_attachment.php?message_id=' . $m['id'] : null,
    ];
}, $rows);

echo json_encode(['messages' => $messages]);
