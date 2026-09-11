<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once '../config.php';

// Если пользователь уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Пожалуйста, заполните все поля';
        } else {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT id, email, password, full_name, role, has_subscription, is_verified, failed_login_attempts, locked_until, is_banned, banned_reason, two_factor_enabled FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Аккаунт временно заблокирован после серии неудачных попыток —
                // даже не проверяем пароль, пока блокировка не истекла.
                if ($user && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
                    $minutesLeft = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
                    $error = 'Слишком много неудачных попыток входа. Попробуйте снова через ' . $minutesLeft . ' мин.';
                } elseif ($user && password_verify($password, $user['password'])) {
                    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                        ->execute([$user['id']]);

                    // Пароль верный, но аккаунт заблокирован админом — дальше не пускаем.
                    // Проверяем это только после пароля, чтобы не палить статус аккаунта
                    // тому, кто пароль не знает.
                    if ($user['is_banned']) {
                        $error = 'Аккаунт заблокирован администратором.'
                            . (!empty($user['banned_reason']) ? ' Причина: ' . $user['banned_reason'] : '');
                    } elseif ($user['two_factor_enabled']) {
                        // Пароль верный — переходим ко второму фактору, полноценную
                        // сессию (user_id и т.д.) выставим только после кода.
                        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $expires = date('Y-m-d H:i:s', time() + 10 * 60);
                        $pdo->prepare("UPDATE users SET two_factor_code = ?, two_factor_code_expires = ? WHERE id = ?")
                            ->execute([$code, $expires, $user['id']]);

                        session_regenerate_id(true);
                        $_SESSION['pending_2fa_user_id'] = $user['id'];
                        unset($_SESSION['tfa_attempts']);

                        header('Location: /pages/verify_2fa.php');
                        exit;
                    } else {
                        session_regenerate_id(true); // новый ID сессии при смене уровня доступа
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['full_name'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['has_subscription'] = (int)$user['has_subscription'];
                        $_SESSION['is_verified'] = (int)$user['is_verified'];

                        header('Location: ' . rr_login_redirect_url($user['role']));
                        exit;
                    }
                } else {
                    // Считаем неудачные попытки только для существующих аккаунтов —
                    // иначе перебор несуществующих email тоже писал бы в базу.
                    if ($user) {
                        $attempts = $user['failed_login_attempts'] + 1;
                        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);
                            $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = ? WHERE id = ?")
                                ->execute([$lockedUntil, $user['id']]);
                            $error = 'Слишком много неудачных попыток входа. Попробуйте снова через ' . LOGIN_LOCKOUT_MINUTES . ' мин.';
                        } else {
                            $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
                                ->execute([$attempts, $user['id']]);
                            $error = 'Неверный email или пароль';
                        }
                    } else {
                        $error = 'Неверный email или пароль';
                    }
                }
            } catch (PDOException $e) {
                $error = 'Ошибка базы данных';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="register-form">
        <h2>Вход в RR</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required placeholder="ivan@example.com">
            </div>

            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" required placeholder="********">
            </div>

            <button type="submit" class="btn-submit">Войти</button>
        </form>

        <p style="text-align: center; margin-top: 12px; font-size: 14px;">
            <a href="/pages/forgot_password.php" style="color: #e94560;">Забыли пароль?</a>
        </p>
        <p style="text-align: center; margin-top: 8px; font-size: 14px; color: #888;">
            Нет аккаунта? <a href="/pages/register.php" style="color: #e94560;">Зарегистрироваться</a>
        </p>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>