<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($application_id <= 0) {
    header('Location: /pages/catalog.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

if (!csrf_verify($_GET['csrf'] ?? '')) {
    header('Location: /pages/catalog.php');
    exit;
}

// Проверяем, что пользователь участник заявки
$stmt = $pdo->prepare("SELECT operator_id, owner_id, location_id, status FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    header('Location: /pages/catalog.php');
    exit;
}

// 'approved' — терминальный статус самой заявки и сам по себе не значит,
// что закрепление всё ещё живо: реальный факт закрепления — это активная
// строка в location_operators (см. дуальный статус заявок). Открепление
// (api/operator_assign.php, action=unassign) переводит эту строку в
// inactive, а не удаляет её и не трогает статус заявки — без этой
// проверки заявка навсегда оставалась бы неудаляемой, даже когда
// закрепления по факту уже нет.
$hasActiveAssignment = false;
if ($app['status'] === 'approved') {
    $stmt = $pdo->prepare("SELECT 1 FROM location_operators WHERE location_id = ? AND operator_id = ? AND status = 'active'");
    $stmt->execute([$app['location_id'], $app['operator_id']]);
    $hasActiveAssignment = (bool) $stmt->fetchColumn();
}

if ($hasActiveAssignment || $app['status'] === 'placed') {
    $backUrl = ($user_id == $app['operator_id']) ? '/pages/operator_applications.php' : '/pages/owner_applications.php';
    $_SESSION['flash'] = 'Нельзя удалить заявку с активным закреплением — сначала откажитесь от него.';
    header('Location: ' . $backUrl);
    exit;
}

// Удаляем заявку (сообщения удалятся каскадно, если внешние ключи настроены)
$stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
$stmt->execute([$application_id]);

// Перенаправляем на список заявок в зависимости от роли
if ($user_id == $app['operator_id']) {
    header('Location: /pages/operator_applications.php');
} else {
    header('Location: /pages/owner_applications.php');
}
exit;