<?php
session_start();
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
$stmt = $pdo->prepare("SELECT operator_id, owner_id, status FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch();
if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
    header('Location: /pages/catalog.php');
    exit;
}

// Заявки со статусом approved/placed уже породили реальное закрепление
// (запись в location_operators) или размещение — удаление самой заявки
// в этом случае просто стёрло бы историю согласования, оставив эту связь
// "осиротевшей", без возможности понять, как и когда она возникла.
if (in_array($app['status'], ['approved', 'placed'], true)) {
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