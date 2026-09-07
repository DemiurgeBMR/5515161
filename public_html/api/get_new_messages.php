<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$last_check = isset($_GET['last_check']) ? (int)$_GET['last_check'] : 0;

$pdo = getDbConnection();

$stmt = $pdo->prepare("
    SELECT m.*, a.id as application_id, l.title as location_title, 
           u.full_name as sender_name,
           CASE WHEN a.operator_id = ? THEN a.operator_notifications_enabled ELSE a.owner_notifications_enabled END as notifications_enabled
    FROM messages m
    JOIN applications a ON m.application_id = a.id
    JOIN users u ON m.sender_id = u.id
    JOIN locations l ON a.location_id = l.id
    WHERE m.receiver_id = ? AND m.is_read = 0 AND m.created_at > FROM_UNIXTIME(?)
    ORDER BY m.created_at ASC
");
$stmt->execute([$user_id, $user_id, $last_check]);
$messages = $stmt->fetchAll();

$result = [];
foreach ($messages as $msg) {
    if ($msg['notifications_enabled'] == 0) continue;
    $result[] = [
        'id' => $msg['id'],
        'application_id' => $msg['application_id'],
        'sender_name' => $msg['sender_name'],
        'message' => mb_substr($msg['message'], 0, 50) . (mb_strlen($msg['message']) > 50 ? '...' : ''),
        'location_title' => $msg['location_title'],
        'created_at' => strtotime($msg['created_at'])  // ← ТОЛЬКО ТАК
    ];
}

echo json_encode(['messages' => $result]);