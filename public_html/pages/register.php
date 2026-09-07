<?php
session_start(); // ДОЛЖНО БЫТЬ ПЕРВОЙ СТРОКОЙ ПОСЛЕ ОТКРЫВАЮЩЕГО ТЕГА!
require_once '../config.php';

// Если пользователь уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'operator';
    if (!in_array($role, ['owner', 'operator'], true)) {
        $role = 'operator'; // роль admin никогда не выдаётся через форму регистрации
    }

    // Простая валидация
    if (empty($email) || empty($password) || empty($full_name)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email адрес';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } else {
        try {
            $pdo = getDbConnection();
            
            // Проверяем, не занят ли email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Этот email уже зарегистрирован';
            } else {
                // Хешируем пароль
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Добавляем пользователя
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password, full_name, phone, role) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$email, $hashed_password, $full_name, $phone, $role]);
                
                // Автоматически авторизуем
                $user_id = $pdo->lastInsertId();
                session_regenerate_id(true); // новая сессия для только что созданного пользователя
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_role'] = $role;

                header('Location: /pages/profile.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="register-form">
        <h2>Регистрация в RR</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Ваша роль</label>
                <div class="role-selector">
                    <label>
                        <input type="radio" name="role" value="operator" checked>
                        🤝 Оператор (ищу место)
                    </label>
                    <label>
                        <input type="radio" name="role" value="owner">
                        🏢 Собственник (сдаю место)
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Полное имя *</label>
                <input type="text" name="full_name" required placeholder="Иван Иванов">
            </div>
            
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required placeholder="ivan@example.com">
            </div>
            
            <div class="form-group">
                <label>Телефон</label>
                <input type="tel" name="phone" placeholder="+7 999 123-45-67">
            </div>
            
            <div class="form-group">
                <label>Пароль * (мин. 6 символов)</label>
                <input type="password" name="password" required minlength="6" placeholder="********">
            </div>
            
            <button type="submit" class="btn-submit">Зарегистрироваться</button>
        </form>
        
        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: #888;">
            Уже есть аккаунт? <a href="/pages/login.php" style="color: #e94560;">Войти</a>
        </p>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>