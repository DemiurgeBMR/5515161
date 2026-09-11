<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

if (!csrf_verify($_GET['csrf'] ?? '')) {
    $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    header('Location: /admin/users.php');
    exit;
}

// На себя эти действия не распространяются — иначе админ мог бы случайно
// заблокировать себя или снять с себя единственные админ-права.
if ($id === (int) $_SESSION['user_id']) {
    $_SESSION['flash'] = 'Нельзя применить это действие к своему же аккаунту.';
    header('Location: /admin/users.php');
    exit;
}

$pdo = getDbConnection();

$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    $_SESSION['flash'] = 'Пользователь не найден.';
    header('Location: /admin/users.php');
    exit;
}

try {
    switch ($action) {
        case 'ban':
            $pdo->prepare("UPDATE users SET is_banned = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = 'Пользователь заблокирован.';
            break;

        case 'unban':
            $pdo->prepare("UPDATE users SET is_banned = 0, banned_reason = NULL WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = 'Пользователь разблокирован.';
            break;

        case 'make_admin':
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = 'Пользователь назначен администратором.';
            break;

        case 'remove_admin':
            $totalAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($target['role'] === 'admin' && $totalAdmins <= 1) {
                // Не может произойти при обычной работе интерфейса (кнопка скрыта),
                // но проверяем и на сервере — единственного админа снять нельзя.
                $_SESSION['flash'] = 'Нельзя снять права с последнего администратора.';
            } else {
                // Возвращаем в operator — самую нейтральную роль по умолчанию;
                // при необходимости роль всегда можно поменять вручную повторно.
                $pdo->prepare("UPDATE users SET role = 'operator' WHERE id = ?")->execute([$id]);
                $_SESSION['flash'] = 'Права администратора сняты.';
            }
            break;

        default:
            $_SESSION['flash'] = 'Неизвестное действие.';
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = 'Ошибка БД: ' . $e->getMessage();
}

header('Location: /admin/users.php');
exit;
