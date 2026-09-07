<?php
session_start();
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

    $errors = [];

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
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
        $errors[] = 'Пароль должен быть не менее 6 символов.';
    }
    if (!empty($password) && $password !== $password_confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    if (empty($errors)) {
        try {
            // Формируем запрос на обновление
            $params = [$full_name, $phone, $email];

            // Если пароль заполнен – обновляем и его
            if (!empty($password)) {
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET full_name = ?, phone = ?, email = ?, password = ? WHERE id = ?";
                $params[] = $user_id;
            } else {
                $sql = "UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?";
                $params[] = $user_id;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Обновляем имя в сессии
            $_SESSION['user_name'] = $full_name;

            $success = 'Данные успешно обновлены.';

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
    <title>Редактирование профиля — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h2>✏️ Редактирование профиля</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
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
            </div>
            <div class="form-group">
                <label>Новый пароль (оставьте пустым, если не хотите менять)</label>
                <input type="password" name="password" placeholder="Минимум 6 символов">
            </div>
            <div class="form-group">
                <label>Подтверждение пароля</label>
                <input type="password" name="password_confirm" placeholder="Повторите пароль">
            </div>
            <button type="submit" class="btn-submit">💾 Сохранить изменения</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>