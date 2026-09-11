<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для авторизованных
if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

$pdo = getDbConnection();

// Получаем текущие данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /pages/profile.php');
    exit;
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $two_factor_enabled = isset($_POST['two_factor_enabled']) ? 1 : 0;

    $errors = [];

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    }

    // Любое изменение на этой странице подтверждается текущим паролем —
    // это данные аккаунта (включая смену самого пароля и email), а не
    // разовая настройка вроде цвета аватара.
    if (empty($current_password) || !password_verify($current_password, $user['password'])) {
        $errors[] = 'Неверный текущий пароль.';
    }

    // Валидация
    if (empty($full_name)) {
        $errors[] = 'Имя обязательно для заполнения.';
    }
    if (empty($email)) {
        $errors[] = 'Email обязателен для заполнения.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Введите корректный email.';
    } else {
        // Проверка уникальности email (кроме текущего)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Этот email уже используется другим пользователем.';
        }
    }

    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Новый пароль должен быть не менее 6 символов.';
    }
    if (!empty($password) && $password !== $password_confirm) {
        $errors[] = 'Новые пароли не совпадают.';
    }

    if (empty($errors)) {
        try {
            $emailChanged = $email !== $user['email'];

            $params = [$full_name, $phone, $email, $two_factor_enabled];
            $sql = "UPDATE users SET full_name = ?, phone = ?, email = ?, two_factor_enabled = ?";

            if (!empty($password)) {
                $sql .= ", password = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            // Смена email — это по сути новый адрес, его снова нужно подтвердить.
            if ($emailChanged) {
                $sql .= ", is_verified = 0";
            }

            $sql .= " WHERE id = ?";
            $params[] = $user_id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $_SESSION['user_name'] = $full_name;
            if ($emailChanged) {
                $_SESSION['is_verified'] = 0;
            }

            $success = 'Данные успешно обновлены.';
            if ($emailChanged) {
                $_SESSION['verify_link'] = rr_issue_verify_link($pdo, $user_id);
                $success .= ' Email изменён — его нужно подтвердить заново, ссылка показана в шапке сайта.';
            }

            // Перезагружаем данные пользователя для отображения
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

        } catch (PDOException $e) {
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование профиля — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="ep-page">
        <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h1 class="ep-title">✏️ Настройки аккаунта</h1>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" class="ep-form">
            <?php echo csrf_field(); ?>

            <div class="ep-card">
                <h2 class="ep-card-title">👤 Личные данные</h2>
                <div class="form-group">
                    <label>Имя *</label>
                    <input type="text" name="full_name" required value="<?php echo htmlspecialchars($user['full_name']); ?>">
                </div>
                <div class="form-group">
                    <label>Телефон</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($user['email']); ?>">
                    <?php if ($user['is_verified']): ?>
                        <span class="ep-field-hint ep-field-hint-ok">✓ Подтверждён</span>
                    <?php else: ?>
                        <span class="ep-field-hint ep-field-hint-warn">
                            ✉️ Не подтверждён — ссылка есть в шапке сайта,
                            <a href="/pages/resend_verification.php?csrf=<?php echo urlencode(csrf_token()); ?>">получить новую</a>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ep-card">
                <h2 class="ep-card-title">🔑 Смена пароля</h2>
                <p class="ep-card-hint">Оставьте эти два поля пустыми, если не хотите менять пароль.</p>
                <div class="form-row">
                    <div class="form-group">
                        <label>Новый пароль</label>
                        <input type="password" name="password" placeholder="Минимум 6 символов">
                    </div>
                    <div class="form-group">
                        <label>Подтверждение</label>
                        <input type="password" name="password_confirm" placeholder="Повторите пароль">
                    </div>
                </div>
            </div>

            <div class="ep-card">
                <h2 class="ep-card-title">🔐 Безопасность входа</h2>
                <label class="ep-toggle">
                    <input type="checkbox" name="two_factor_enabled" <?php echo $user['two_factor_enabled'] ? 'checked' : ''; ?>>
                    <span class="ep-toggle-track"><span class="ep-toggle-thumb"></span></span>
                    <span class="ep-toggle-label">
                        Двухфакторная аутентификация
                        <small>При входе дополнительно потребуется код — пока показывается на экране, письма ещё не настроены.</small>
                    </span>
                </label>
            </div>

            <div class="ep-card ep-card-confirm">
                <h2 class="ep-card-title">✅ Подтверждение</h2>
                <div class="form-group">
                    <label>Текущий пароль *</label>
                    <input type="password" name="current_password" required placeholder="Введите текущий пароль, чтобы сохранить изменения">
                </div>
                <button type="submit" class="btn-submit">💾 Сохранить изменения</button>
            </div>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
