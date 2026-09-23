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
$pdo = getDbConnection();
rr_enforce_rate_limit($pdo, 'send_service_order_message:' . $user_id, 20, 60);

// Файл больше post_max_size из php.ini — PHP тихо отбрасывает ВЕСЬ $_POST и
// $_FILES ещё до того, как скрипт вообще начал выполняться (в т.ч. наш
// собственный csrf_token тоже пропадает из тела запроса). Без этой проверки
// пользователь увидел бы обманчивое "не удалось подтвердить запрос" вместо
// понятного "файл слишком большой".
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Файл больше ' . round(rr_service_order_attachment_max_bytes() / 1024 / 1024) . ' МБ']);
    exit;
}

if (!csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$message = trim($_POST['message'] ?? '');
$hasAttachment = !empty($_FILES['attachment']['name']);

// Сообщение может быть пустым, только если есть вложение (фото/документ
// без подписи — обычный случай для "вот скан паспорта").
if ($order_id <= 0 || ($message === '' && !$hasAttachment)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id, service, price FROM service_orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

// Доступ — только сам заказчик или любой админ (у заказов нет персональной
// очереди на конкретного сотрудника).
if (!$order || (!$isAdmin && $order['user_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$attachment = null;
if ($hasAttachment) {
    $attachment = rr_save_service_order_attachment($pdo, $user_id, $_FILES['attachment']);
    if (isset($attachment['error'])) {
        http_response_code(400);
        echo json_encode(['error' => $attachment['error']]);
        exit;
    }
}

$stmt = $pdo->prepare("
    INSERT INTO service_order_messages (service_order_id, sender_id, message, attachment_path, attachment_name, attachment_size, attachment_type)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $order_id,
    $user_id,
    $message,
    $attachment['path'] ?? null,
    $attachment['name'] ?? null,
    $attachment['size'] ?? null,
    $attachment['type'] ?? null,
]);
$message_id = $pdo->lastInsertId();

$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM service_order_messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.id = ?
");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

// Заказчик пишет → уведомляем всех админов; админ отвечает → уведомляем
// заказчика. Не создаём уведомление самому себе (несколько админов могут
// читать один и тот же тред).
$preview = $message !== ''
    ? (mb_substr($message, 0, 80) . (mb_strlen($message) > 80 ? '…' : ''))
    : ($attachment ? '📎 ' . $attachment['name'] : '');
$link = '/pages/service_order_chat.php?order_id=' . $order_id;
if ($isAdmin) {
    if ($order['user_id'] != $user_id) {
        notify($pdo, $order['user_id'], 'service_order_message', $preview, $link, ['order_id' => $order_id]);
    }
} else {
    rr_notify_admins($pdo, 'service_order_message', $preview, $link, ['order_id' => $order_id]);
}

echo json_encode([
    'success' => true,
    'message' => [
        'id' => $msg['id'],
        'sender_id' => (int) $msg['sender_id'],
        'sender_name' => $msg['sender_name'],
        'message' => $msg['message'],
        'is_system' => false,
        'created_at' => strtotime($msg['created_at']),
        'attachment_name' => $msg['attachment_name'],
        'attachment_size' => $msg['attachment_size'] !== null ? (int) $msg['attachment_size'] : null,
        'attachment_type' => $msg['attachment_type'],
        'attachment_url' => $msg['attachment_path'] ? '/api/download_service_order_attachment.php?message_id=' . $msg['id'] : null,
    ],
]);
