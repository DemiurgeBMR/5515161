<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();
$token = trim($_GET['token'] ?? '');

$stmt = $pdo->prepare("SELECT id FROM users WHERE verify_token = ? AND verify_token_expires > NOW()");
$stmt->execute([$token]);
$user = $token !== '' ? $stmt->fetch() : false;

if ($user) {
    $pdo->prepare("UPDATE users SET is_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ?")
        ->execute([$user['id']]);
    if (($_SESSION['user_id'] ?? null) == $user['id']) {
        $_SESSION['is_verified'] = 1;
    }
    $success = true;
} else {
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подтверждение email — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <h2>📧 Подтверждение email</h2>

        <?php if ($success): ?>
            <div class="success">Email подтверждён, спасибо!</div>
            <p style="margin-top: 15px;">
                <a href="<?php echo isset($_SESSION['user_id']) ? '/pages/profile.php' : '/pages/login.php'; ?>">
                    <?php echo isset($_SESSION['user_id']) ? 'Вернуться в профиль →' : 'Войти →'; ?>
                </a>
            </p>
        <?php else: ?>
            <div class="error">Ссылка недействительна, уже использована или срок её действия истёк.</div>
            <?php if (isset($_SESSION['user_id'])): ?>
                <p style="margin-top: 15px;"><a href="/pages/profile.php">Запросить новую ссылку в профиле →</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
