<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$error = '';

// Не больше 5 попыток ввода кода на одну "ожидающую" сессию — иначе можно
// было бы перебирать все 6-значные коды прямо здесь.
$attempts = $_SESSION['tfa_attempts'] ?? 0;
if ($attempts >= 5) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['tfa_attempts']);
    header('Location: /pages/login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, full_name, role, is_verified, two_factor_code, two_factor_code_expires FROM users WHERE id = ?");
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['tfa_attempts']);
    header('Location: /pages/login.php');
    exit;
}

$codeExpired = empty($user['two_factor_code']) || strtotime($user['two_factor_code_expires']) <= time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } elseif (isset($_POST['resend'])) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 10 * 60);
        $pdo->prepare("UPDATE users SET two_factor_code = ?, two_factor_code_expires = ? WHERE id = ?")
            ->execute([$code, $expires, $user['id']]);

        $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmtEmail->execute([$user['id']]);
        rr_send_email(
            $stmtEmail->fetchColumn(),
            'Код подтверждения входа — ' . SITE_NAME,
            '<p>Код для входа на ' . htmlspecialchars(SITE_NAME) . ': <strong style="font-size:20px">' . htmlspecialchars($code) . '</strong></p>'
                . '<p>Код действует 10 минут. Если вы не пытались войти в аккаунт — просто проигнорируйте это письмо.</p>'
        );

        $user['two_factor_code'] = $code;
        $user['two_factor_code_expires'] = $expires;
        $codeExpired = false;
    } else {
        $enteredCode = trim($_POST['code'] ?? '');

        if ($codeExpired) {
            $error = 'Код устарел, запросите новый.';
        } elseif (!hash_equals($user['two_factor_code'], $enteredCode)) {
            $_SESSION['tfa_attempts'] = $attempts + 1;
            $error = 'Неверный код.';
        } else {
            $pdo->prepare("UPDATE users SET two_factor_code = NULL, two_factor_code_expires = NULL WHERE id = ?")
                ->execute([$user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['is_verified'] = (int) $user['is_verified'];
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['tfa_attempts']);

            header('Location: ' . rr_login_redirect_url($user['role']));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение входа — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <h2><?php echo rr_icon('lock'); ?> Подтверждение входа</h2>
        <p class="auth-note">
            На аккаунте включена двухфакторная аутентификация — введите код, чтобы завершить вход.
        </p>

        <?php if ($error): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!$codeExpired && !rr_mail_configured()): ?>
            <div class="success" role="status">
                Отправка писем на сайте пока не настроена, поэтому код показан прямо здесь
                (временно, до подключения почты): <strong class="auth-code-display"><?php echo htmlspecialchars($user['two_factor_code']); ?></strong>
            </div>
        <?php elseif (!$codeExpired): ?>
            <div class="success" role="status">
                Код отправлен на вашу почту. Проверьте письмо (в том числе папку «Спам»).
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Код из письма</label>
                <input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" autofocus>
            </div>
            <button type="submit" class="btn-submit">Подтвердить</button>
        </form>

        <form method="POST" class="auth-secondary-form">
            <?php echo csrf_field(); ?>
            <button type="submit" name="resend" value="1" class="btn-action secondary block">Прислать новый код</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
