<?php
/**
 * Состояние интерактивного обучения текущего пользователя (см. rr-tour.js).
 *
 * POST action=...
 *   start    — начать (или пройти заново) с первой главы
 *   progress — перейти к главе `chapter`; только если обучение сейчас идёт
 *   complete — обучение пройдено до конца
 *   skip     — пользователь его пропустил/закрыл
 *
 * Ответ: {success: true, status: "...", chapter: N} — актуальное состояние
 * после операции (при `progress` оно может отличаться от запрошенного, если
 * обучение уже остановили в другой вкладке).
 */
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!rr_onboarding_applies($_SESSION['user_role'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Обучение доступно только собственникам и операторам.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getDbConnection();
rr_enforce_rate_limit($pdo, 'onboarding:' . $_SESSION['user_id'], 60, 60);

if (!csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$chapter = $_POST['chapter'] ?? 0;

switch ($action) {
    case 'start':
        $state = rr_onboarding_update($pdo, $userId, 'in_progress', 0);
        break;
    case 'progress':
        $state = rr_onboarding_update($pdo, $userId, 'in_progress', $chapter, true);
        break;
    case 'complete':
        $state = rr_onboarding_update($pdo, $userId, 'completed');
        break;
    case 'skip':
        $state = rr_onboarding_update($pdo, $userId, 'skipped');
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
}

if ($state === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось сохранить прогресс обучения.']);
    exit;
}

echo json_encode(['success' => true, 'status' => $state['status'], 'chapter' => $state['chapter']]);
