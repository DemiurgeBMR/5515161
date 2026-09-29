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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();
rr_enforce_rate_limit($pdo, 'favorites:' . $user_id, 60, 60);

// Избранное — рабочий инструмент оператора при подборе локации под аренду;
// у собственника и админа для него нет сценария использования.
if (($_SESSION['user_role'] ?? '') !== 'operator') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {

switch ($action) {
    // Переключить локацию в избранном/из избранного одним действием — сам
    // клиент не обязан заранее знать текущее состояние.
    case 'toggle':
        $location_id = (int)($_POST['location_id'] ?? 0);
        if ($location_id <= 0) {
            echo json_encode(['error' => 'Invalid location_id']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM locations WHERE id = ?");
        $stmt->execute([$location_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Location not found']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND location_id = ?");
        $stmt->execute([$user_id, $location_id]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = ?");
            $stmt->execute([$existing]);
            echo json_encode(['success' => true, 'favorited' => false]);
        } else {
            // INSERT IGNORE — на случай двойного клика/гонки: уникальный
            // индекс (user_id, location_id) не даст вставить дубликат.
            $stmt = $pdo->prepare("INSERT IGNORE INTO favorites (user_id, location_id) VALUES (?, ?)");
            $stmt->execute([$user_id, $location_id]);
            echo json_encode(['success' => true, 'favorited' => true]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}

} catch (Throwable $e) {
    error_log('favorites.php (' . $action . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось выполнить запрос, попробуйте ещё раз позже.']);
}
