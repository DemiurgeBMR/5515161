<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

// $_REQUEST, а не только $_GET — action=extend_subscription приходит POST'ом
// с формой выбора тарифа (admin/users.php), остальные действия по-прежнему
// простые GET-ссылки с подтверждением на клиенте.
$action = $_REQUEST['action'] ?? '';
$id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

if ($id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

if (!csrf_verify($_REQUEST['csrf'] ?? '')) {
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

        case 'extend_subscription':
            if ($target['role'] !== 'operator') {
                $_SESSION['flash'] = 'Тариф доступен только операторам.';
                break;
            }
            $planKey = $_POST['plan'] ?? '';
            $newEndDate = rr_purchase_subscription($pdo, $id, $planKey);
            if ($newEndDate === false) {
                $_SESSION['flash'] = 'Неизвестный тариф.';
            } else {
                $plans = rr_recurring_plans();
                $_SESSION['flash'] = 'Тариф «' . $plans[$planKey]['label'] . '» выдан. Действует до ' . formatDate($newEndDate) . '.';
            }
            break;

        case 'cancel_subscription':
            rr_cancel_subscription($pdo, $id);
            $_SESSION['flash'] = 'Тариф отменён досрочно.';
            break;

        case 'grant_credits':
            if ($target['role'] !== 'operator') {
                $_SESSION['flash'] = 'Кредиты доступны только операторам.';
                break;
            }
            $credits = (int) ($_POST['credits'] ?? 0);
            if ($credits <= 0 || $credits > 100) {
                $_SESSION['flash'] = 'Укажите от 1 до 100 кредитов.';
            } else {
                rr_grant_credits($pdo, $id, 'free_grant', $credits);
                $_SESSION['flash'] = 'Начислено ' . $credits . ' кредит(ов).';
            }
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
    error_log('admin/user_actions.php (' . $action . '): ' . $e->getMessage());
    $_SESSION['flash'] = DEBUG_MODE ? ('Ошибка БД: ' . $e->getMessage()) : 'Произошла ошибка. Попробуйте ещё раз позже.';
}

header('Location: /admin/users.php');
exit;
