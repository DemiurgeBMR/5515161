<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];
$isAdmin = ($_SESSION['user_role'] ?? null) === 'admin';
$message_id = isset($_GET['message_id']) ? (int) $_GET['message_id'] : 0;

if ($message_id <= 0) {
    http_response_code(400);
    exit('Invalid parameters');
}

$pdo = getDbConnection();
rr_enforce_rate_limit($pdo, 'download_service_order_attachment:' . $user_id, 60, 60);

$stmt = $pdo->prepare("
    SELECT m.attachment_path, m.attachment_name, m.attachment_type, so.user_id as order_user_id
    FROM service_order_messages m
    JOIN service_orders so ON so.id = m.service_order_id
    WHERE m.id = ?
");
$stmt->execute([$message_id]);
$row = $stmt->fetch();

// Тот же принцип доступа, что и у самого чата: только заказчик или любой
// админ — вложение приватно, а не публичная ссылка, которую можно
// переслать кому угодно.
if (!$row || !$row['attachment_path'] || (!$isAdmin && $row['order_user_id'] != $user_id)) {
    http_response_code(404);
    exit('Not found');
}

$fullPath = rr_service_order_attachments_dir() . $row['attachment_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Not found');
}

$mimeByType = [
    'image' => [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    ],
    'document' => [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
];
$ext = strtolower(pathinfo($row['attachment_path'], PATHINFO_EXTENSION));
$contentType = $mimeByType[$row['attachment_type']][$ext] ?? 'application/octet-stream';

// Изображения отдаём inline (превью прямо по клику), документы — как
// вложение (не у всех в браузере настроен просмотр .docx/.xlsx).
$disposition = $row['attachment_type'] === 'image' ? 'inline' : 'attachment';
$downloadName = $row['attachment_name'] !== null && $row['attachment_name'] !== ''
    ? $row['attachment_name']
    : basename($row['attachment_path']);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($fullPath);
