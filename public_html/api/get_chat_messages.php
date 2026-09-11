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

$application_id = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

// Проверяем, что пользователь участник этой заявки
$stmt = $pdo->prepare("SELECT operator_id, owner_id FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Пользователь прямо сейчас смотрит в этот чат — помечаем адресованные ему
// сообщения прочитанными, чтобы бейдж уведомлений не копился зря
$stmt = $pdo->prepare("
    UPDATE messages
    SET is_read = 1
    WHERE application_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->execute([$application_id, $user_id]);

// Забираем всё новое после last_id (is_read уже актуален после апдейта выше)
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.application_id = ? AND m.id > ?
    ORDER BY m.id ASC
");
$stmt->execute([$application_id, $last_id]);
$rows = $stmt->fetchAll();

$messages = array_map(function ($m) {
    return [
        'id' => (int)$m['id'],
        'sender_id' => (int)$m['sender_id'],
        'sender_name' => $m['sender_name'],
        'message' => $m['message'],
        'created_at' => strtotime($m['created_at']),
        'is_read' => (bool)$m['is_read'],
    ];
}, $rows);

echo json_encode(['messages' => $messages]);