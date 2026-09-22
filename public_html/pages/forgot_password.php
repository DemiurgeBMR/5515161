<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';
$resetLink = null;
$emailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } elseif (!rr_check_rate_limit(getDbConnection(), 'forgot_password:' . rr_client_ip(), 5, 300)) {
        // Без лимита форму можно было дёргать без остановки — перебирать
        // email'ы (узнавая по ответу, кто зарегистрирован) или засыпать
        // таблицу users токенами сброса.
        $error = 'Слишком много попыток. Попробуйте через несколько минут.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Введите корректный email.';
        } else {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_MINUTES * 60);
                $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                    ->execute([$token, $expires, $user['id']]);

                $link = SITE_URL . '/pages/reset_password.php?token=' . $token;

                $emailSent = rr_send_email(
                    $email,
                    'Восстановление пароля на ' . SITE_NAME,
                    '<p>Вы запросили сброс пароля на ' . htmlspecialchars(SITE_NAME) . '.</p>'
                        . '<p><a href="' . htmlspecialchars($link) . '">Установить новый пароль</a></p>'
                        . '<p>Ссылка действует ' . PASSWORD_RESET_TTL_MINUTES . ' минут и может быть использована один раз.</p>'
                        . '<p>Если вы не запрашивали сброс пароля — просто проигнорируйте это письмо.</p>'
                );

                // Пока SMTP не настроен на этом окружении — показываем ссылку прямо
                // на экране, чтобы разработка/тестирование не блокировались. Как
                // только rr_mail_configured() станет true, эта ветка перестанет
                // срабатывать сама по себе.
                if (!$emailSent) {
                    $resetLink = $link;
                }
            }
            // Если пользователь с таким email не найден — $resetLink остаётся null,
            // и ниже покажется тот же нейтральный текст, что и при реальной отправке
            // (не подтверждаем и не опровергаем существование email).
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <a href="/pages/login.php" class="back-link">← Назад ко входу</a>
        <h2><?php echo rr_icon('key'); ?> Восстановление пароля</h2>

        <?php if ($error): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($resetLink): ?>
            <div class="success" role="status">
                Отправка писем на этом сервере ещё не настроена (нет SMTP), поэтому ссылка
                для сброса пароля временно показана прямо здесь (только на этот раз):
            </div>
            <p class="auth-link-break">
                <a href="<?php echo htmlspecialchars($resetLink); ?>"><?php echo htmlspecialchars($resetLink); ?></a>
            </p>
            <p class="auth-note-small">
                Ссылка действует <?php echo PASSWORD_RESET_TTL_MINUTES; ?> минут и может быть использована один раз.
            </p>
        <?php elseif ($emailSent): ?>
            <div class="success" role="status">
                Если такой email зарегистрирован, на него отправлено письмо со ссылкой для
                сброса пароля. Проверьте почту (в том числе папку «Спам»).
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
            <div class="success" role="status">
                Если такой email зарегистрирован, для него можно было бы получить ссылку для
                сброса пароля.
            </div>
        <?php else: ?>
            <p class="auth-note">
                Укажите email, указанный при регистрации — мы поможем восстановить доступ к аккаунту.
            </p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required placeholder="ivan@example.com">
                </div>
                <button type="submit" class="btn-submit">Получить ссылку для сброса</button>
            </form>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
