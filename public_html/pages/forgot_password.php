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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
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

                // ★★★ Временная заглушка вместо письма ★★★
                // На проекте пока не настроена отправка почты (локальная разработка).
                // Как только появится SMTP — здесь нужно отправить $resetLink пользователю
                // на email и убрать вывод ссылки на экран.
                $resetLink = SITE_URL . '/pages/reset_password.php?token=' . $token;
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
    <title>Восстановление пароля — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <a href="/pages/login.php" class="back-link">← Назад ко входу</a>
        <h2>🔑 Восстановление пароля</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($resetLink): ?>
            <div class="success">
                Отправка писем на сайте пока не настроена, поэтому ссылка для сброса пароля
                показана прямо здесь (только на этот раз):
            </div>
            <p style="margin: 15px 0; word-break: break-all;">
                <a href="<?php echo htmlspecialchars($resetLink); ?>"><?php echo htmlspecialchars($resetLink); ?></a>
            </p>
            <p style="color: #888; font-size: 13px;">
                Ссылка действует <?php echo PASSWORD_RESET_TTL_MINUTES; ?> минут и может быть использована один раз.
            </p>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
            <div class="success">
                Если такой email зарегистрирован, для него можно было бы получить ссылку для
                сброса пароля.
            </div>
        <?php else: ?>
            <p style="color: #888; margin-bottom: 15px;">
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
