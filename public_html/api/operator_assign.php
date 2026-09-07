<?php
session_start();
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
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = getDbConnection();

// Проверка прав: только владельцы и операторы имеют доступ
$role = $_SESSION['user_role'] ?? '';

if (!in_array($role, ['owner', 'operator'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

switch ($action) {
    // 1. Владелец закрепляет оператора за своей локацией
    case 'assign':
        // Проверяем, что пользователь – владелец
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can assign operators']);
            exit;
        }

        $location_id = (int)($_POST['location_id'] ?? 0);
        $operator_id = (int)($_POST['operator_id'] ?? 0);

        if ($location_id <= 0 || $operator_id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }

        // Проверяем, что локация принадлежит этому владельцу
        $stmt = $pdo->prepare("SELECT owner_id FROM locations WHERE id = ?");
        $stmt->execute([$location_id]);
        $loc = $stmt->fetch();
        if (!$loc || $loc['owner_id'] != $user_id) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not own this location']);
            exit;
        }

        // Проверяем, что оператор существует
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$operator_id]);
        $op = $stmt->fetch();
        if (!$op || $op['role'] !== 'operator') {
            echo json_encode(['error' => 'User is not an operator']);
            exit;
        }

        // Проверяем, нет ли уже закрепления
        $stmt = $pdo->prepare("SELECT id FROM location_operators WHERE location_id = ? AND operator_id = ?");
        $stmt->execute([$location_id, $operator_id]);
        if ($stmt->fetch()) {
            // Если уже есть, обновляем статус
            $stmt = $pdo->prepare("UPDATE location_operators SET status = 'active', updated_at = NOW() WHERE location_id = ? AND operator_id = ?");
            $stmt->execute([$location_id, $operator_id]);
            echo json_encode(['success' => true, 'message' => 'Operator re-assigned']);
            exit;
        }

        // Создаём новую запись
        $stmt = $pdo->prepare("INSERT INTO location_operators (location_id, operator_id, owner_id, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$location_id, $operator_id, $user_id]);

        // Уведомление оператору
        $stmt = $pdo->prepare("SELECT title FROM locations WHERE id = ?");
        $stmt->execute([$location_id]);
        $locTitle = $stmt->fetchColumn();
        $link = '/pages/location.php?id=' . $location_id;
        $message = 'Вас закрепили за локацией ' . $locTitle;
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'operator_assigned', ?, ?)");
        $stmt->execute([$operator_id, $message, $link]);

        echo json_encode(['success' => true, 'message' => 'Operator assigned successfully']);
        break;

    // 2. Оператор запрашивает закрепление (создаёт заявку)
    case 'request':
        if ($role !== 'operator') {
            http_response_code(403);
            echo json_encode(['error' => 'Only operators can request assignment']);
            exit;
        }

        $location_id = (int)($_POST['location_id'] ?? 0);
        if ($location_id <= 0) {
            echo json_encode(['error' => 'Invalid location ID']);
            exit;
        }

        // Проверяем, что локация существует и активна
        $stmt = $pdo->prepare("SELECT id, owner_id, title FROM locations WHERE id = ? AND is_active = 1 AND is_moderated = 1");
        $stmt->execute([$location_id]);
        $loc = $stmt->fetch();
        if (!$loc) {
            echo json_encode(['error' => 'Location not found or not available']);
            exit;
        }

        $owner_id = $loc['owner_id'];
        if ($owner_id == $user_id) {
            echo json_encode(['error' => 'You cannot request your own location']);
            exit;
        }

        // Проверяем, есть ли уже активная заявка от этого оператора на эту локацию
        $stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('rejected', 'cancelled')");
        $stmt->execute([$location_id, $user_id]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'You already have a pending request for this location']);
            exit;
        }

        // Создаём заявку (новый тип – запрос на закрепление)
        $stmt = $pdo->prepare("
            INSERT INTO applications (location_id, operator_id, owner_id, status, operator_approved, initial_message)
            VALUES (?, ?, ?, 'pending', 0, 'Запрос на закрепление')
        ");
        $stmt->execute([$location_id, $user_id, $owner_id]);
        $application_id = $pdo->lastInsertId();

        // Уведомление владельцу
        $link = '/pages/application_chat.php?application_id=' . $application_id;
        $message = 'Оператор запросил закрепление за локацией ' . $loc['title'];
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'assignment_request', ?, ?)");
        $stmt->execute([$owner_id, $message, $link]);

        echo json_encode(['success' => true, 'application_id' => $application_id]);
        break;

    // 3. Владелец подтверждает закрепление (одобряет заявку)
    case 'approve':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can approve assignments']);
            exit;
        }

        $application_id = (int)($_POST['application_id'] ?? 0);
        if ($application_id <= 0) {
            echo json_encode(['error' => 'Invalid application ID']);
            exit;
        }

        // Проверяем, что заявка принадлежит этому владельцу и статус pending
        $stmt = $pdo->prepare("SELECT a.*, l.title as location_title FROM applications a JOIN locations l ON a.location_id = l.id WHERE a.id = ? AND a.owner_id = ? AND a.status = 'pending'");
        $stmt->execute([$application_id, $user_id]);
        $app = $stmt->fetch();
        if (!$app) {
            echo json_encode(['error' => 'Application not found or not pending']);
            exit;
        }

        // Создаём закрепление
        $stmt = $pdo->prepare("INSERT INTO location_operators (location_id, operator_id, owner_id, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$app['location_id'], $app['operator_id'], $user_id]);

        // Обновляем заявку
        $stmt = $pdo->prepare("UPDATE applications SET status = 'approved', operator_approved = 1 WHERE id = ?");
        $stmt->execute([$application_id]);

        // Уведомление оператору
        $link = '/pages/location.php?id=' . $app['location_id'];
        $message = 'Владелец подтвердил ваше закрепление за локацией ' . $app['location_title'];
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'assignment_approved', ?, ?)");
        $stmt->execute([$app['operator_id'], $message, $link]);

        echo json_encode(['success' => true, 'message' => 'Assignment approved']);
        break;

    // 4. Владелец отклоняет закрепление
    case 'reject':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can reject assignments']);
            exit;
        }

        $application_id = (int)($_POST['application_id'] ?? 0);
        if ($application_id <= 0) {
            echo json_encode(['error' => 'Invalid application ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT a.*, l.title as location_title FROM applications a JOIN locations l ON a.location_id = l.id WHERE a.id = ? AND a.owner_id = ? AND a.status = 'pending'");
        $stmt->execute([$application_id, $user_id]);
        $app = $stmt->fetch();
        if (!$app) {
            echo json_encode(['error' => 'Application not found or not pending']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE applications SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$application_id]);

        // Уведомление оператору
        $link = '/pages/catalog.php';
        $message = 'Владелец отклонил ваше закрепление за локацией ' . $app['location_title'];
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'assignment_rejected', ?, ?)");
        $stmt->execute([$app['operator_id'], $message, $link]);

        echo json_encode(['success' => true, 'message' => 'Assignment rejected']);
        break;

    // 5. Владелец открепляет оператора
    case 'unassign':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can unassign operators']);
            exit;
        }

        $location_operator_id = (int)($_POST['location_operator_id'] ?? 0);
        if ($location_operator_id <= 0) {
            echo json_encode(['error' => 'Invalid ID']);
            exit;
        }

        // Проверяем, что эта запись принадлежит владельцу
        $stmt = $pdo->prepare("SELECT id, operator_id, location_id FROM location_operators WHERE id = ? AND owner_id = ?");
        $stmt->execute([$location_operator_id, $user_id]);
        $record = $stmt->fetch();
        if (!$record) {
            echo json_encode(['error' => 'Record not found or access denied']);
            exit;
        }

        // Удаляем или делаем inactive
        $stmt = $pdo->prepare("DELETE FROM location_operators WHERE id = ?");
        $stmt->execute([$location_operator_id]);

        // Или можно обновить статус, но для простоты удалим

        echo json_encode(['success' => true, 'message' => 'Operator unassigned']);
        break;

    // 6. Получить список операторов, доступных владельцу (для закрепления)
    case 'get_available_operators':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can view this']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.full_name
            FROM users u
            JOIN applications a ON a.operator_id = u.id
            WHERE a.owner_id = ? AND u.role = 'operator'
            ORDER BY u.full_name
        ");
        $stmt->execute([$user_id]);
        $operators = $stmt->fetchAll();

        echo json_encode(['operators' => $operators]);
        break;

    // 7. Получить список закреплённых операторов для владельца (со всеми данными)
    case 'get_assigned':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only owners can view this']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT lo.*, l.title as location_title, u.full_name as operator_name
            FROM location_operators lo
            JOIN locations l ON lo.location_id = l.id
            JOIN users u ON lo.operator_id = u.id
            WHERE lo.owner_id = ? AND lo.status = 'active'
        ");
        $stmt->execute([$user_id]);
        $assigned = $stmt->fetchAll();

        echo json_encode(['assigned' => $assigned]);
        break;

        case 'get_for_location':
    if ($role !== 'owner') {
        http_response_code(403);
        echo json_encode(['error' => 'Only owners can view this']);
        exit;
    }
    $location_id = (int)($_GET['location_id'] ?? 0);
    if ($location_id <= 0) {
        echo json_encode(['error' => 'Invalid location ID']);
        exit;
    }
    // Проверяем, что локация принадлежит этому владельцу
    $stmt = $pdo->prepare("SELECT owner_id FROM locations WHERE id = ?");
    $stmt->execute([$location_id]);
    $loc = $stmt->fetch();
    if (!$loc || $loc['owner_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name
        FROM location_operators lo
        JOIN users u ON lo.operator_id = u.id
        WHERE lo.location_id = ? AND lo.status = 'active'
        ORDER BY u.full_name
    ");
    $stmt->execute([$location_id]);
    $operators = $stmt->fetchAll();
    echo json_encode(['operators' => $operators]);
    break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}