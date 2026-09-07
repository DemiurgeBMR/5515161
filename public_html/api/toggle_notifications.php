<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$application_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
$enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;

if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid application ID']);
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT operator_id, owner_id FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$field = ($app['operator_id'] == $user_id) ? 'operator_notifications_enabled' : 'owner_notifications_enabled';

$stmt = $pdo->prepare("UPDATE applications SET $field = ? WHERE id = ?");
$stmt->execute([$enabled, $application_id]);

echo json_encode(['success' => true, 'enabled' => (bool)$enabled]);