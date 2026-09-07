<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$application_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
$message = trim($_POST['message'] ?? '');

if ($application_id <= 0 || empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$user_id = $_SESSION['user_id'];

$pdo = getDbConnection();

// Проверяем, что пользователь участник чата и чат активен
$stmt = $pdo->prepare("SELECT operator_id, owner_id, status FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}
if ($app['status'] == 'cancelled' || $app['status'] == 'placed') {
    http_response_code(400);
    echo json_encode(['error' => 'Chat is closed']);
    exit;
}

$receiver_id = ($app['operator_id'] == $user_id) ? $app['owner_id'] : $app['operator_id'];

// Вставляем сообщение
$stmt = $pdo->prepare("
    INSERT INTO messages (application_id, sender_id, receiver_id, message)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$application_id, $user_id, $receiver_id, $message]);
$message_id = $pdo->lastInsertId();

// Получаем данные отправленного сообщения для ответа
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.id = ?
");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

echo json_encode([
    'success' => true,
    'message' => [
        'id' => $msg['id'],
        'sender_id' => $msg['sender_id'],
        'sender_name' => $msg['sender_name'],
        'message' => $msg['message'],
        'created_at' => strtotime($msg['created_at'])
    ]
]);