<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$pdo = getDbConnection();
$error = '';
$success = false;

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');

$stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
$stmt->execute([$token]);
$user = $token !== '' ? $stmt->fetch() : false;
$tokenValid = (bool) $user;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } else {
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if ($passwordError = rr_validate_password_strength($password)) {
            $error = $passwordError;
        } elseif ($password !== $password_confirm) {
            $error = 'Пароли не совпадают.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("
                UPDATE users
                SET password = ?, reset_token = NULL, reset_token_expires = NULL,
                    failed_login_attempts = 0, locked_until = NULL
                WHERE id = ?
            ")->execute([$hashed, $user['id']]);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый пароль — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <h2><?php echo rr_icon('key'); ?> Новый пароль</h2>

        <?php if (!$tokenValid): ?>
            <div class="error" role="alert">Ссылка недействительна или срок её действия истёк.</div>
            <p class="auth-link-paragraph"><a href="/pages/forgot_password.php">Запросить новую ссылку →</a></p>
        <?php elseif ($success): ?>
            <div class="success" role="status">Пароль успешно изменён.</div>
            <p class="auth-link-paragraph"><a href="/pages/login.php">Войти с новым паролем →</a></p>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label>Новый пароль</label>
                    <input type="password" name="password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" pattern="^(?=.*[A-Za-zА-Яа-яЁё])(?=.*[0-9])(?=.*[^A-Za-zА-Яа-яЁё0-9]).{<?php echo PASSWORD_MIN_LENGTH; ?>,}$" title="<?php echo htmlspecialchars(PASSWORD_HINT); ?>" placeholder="<?php echo htmlspecialchars(PASSWORD_HINT); ?>">
                    <small class="form-hint"><?php echo htmlspecialchars(PASSWORD_HINT); ?></small>
                </div>
                <div class="form-group">
                    <label>Подтверждение пароля</label>
                    <input type="password" name="password_confirm" required placeholder="Повторите пароль">
                </div>
                <button type="submit" class="btn-submit">Сохранить новый пароль</button>
            </form>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
