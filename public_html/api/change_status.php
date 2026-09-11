<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$application_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
$new_status = $_POST['status'] ?? '';
$allowed_personal = ['negotiating', 'agreed', 'placed'];

if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid application ID']);
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT operator_id, owner_id, status, cancelled_by FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$is_operator = ($app['operator_id'] == $user_id);
$is_owner = ($app['owner_id'] == $user_id);

// === 1. Установка отмены (cancelled) ===
if ($new_status === 'cancelled') {
    if ($app['status'] === 'cancelled') {
        echo json_encode(['success' => true, 'status' => 'cancelled']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE applications SET status = 'cancelled', cancelled_by = ? WHERE id = ?");
    $stmt->execute([$user_id, $application_id]);
    echo json_encode(['success' => true, 'status' => 'cancelled']);
    exit;
}

// === 2. Попытка изменить отмену (если текущий статус cancelled) ===
if ($app['status'] === 'cancelled') {
    if ($app['cancelled_by'] != $user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Вы не можете изменить статус, так как отмена была поставлена другим пользователем.']);
        exit;
    }
    // Снимаем отмену
    $stmt = $pdo->prepare("UPDATE applications SET status = 'pending', cancelled_by = NULL WHERE id = ?");
    $stmt->execute([$application_id]);
    // Если выбран личный тег, устанавливаем его
    if (in_array($new_status, $allowed_personal)) {
        $field = $is_operator ? 'operator_tag' : 'owner_tag';
        $stmt = $pdo->prepare("UPDATE applications SET $field = ? WHERE id = ?");
        $stmt->execute([$new_status, $application_id]);
    }
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit;
}

// === 3. Обычная смена личного тега (статус не cancelled) ===
if (in_array($new_status, $allowed_personal)) {
    $field = $is_operator ? 'operator_tag' : 'owner_tag';
    $stmt = $pdo->prepare("UPDATE applications SET $field = ? WHERE id = ?");
    $stmt->execute([$new_status, $application_id]);
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid status']);
exit;