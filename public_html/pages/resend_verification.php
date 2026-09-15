<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

if (!csrf_verify($_GET['csrf'] ?? '')) {
    $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    header('Location: ' . rr_login_redirect_url($_SESSION['user_role'] ?? ''));
    exit;
}

$pdo = getDbConnection();

if (!empty($_SESSION['is_verified'])) {
    $_SESSION['flash'] = 'Email уже подтверждён.';
} else {
    $_SESSION['verify_link'] = rr_issue_verify_link($pdo, $_SESSION['user_id']);
}

header('Location: ' . rr_login_redirect_url($_SESSION['user_role'] ?? ''));
exit;
