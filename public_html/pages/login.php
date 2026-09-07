<?php
session_start(); // ДОЛЖНО БЫТЬ ПЕРВОЙ СТРОКОЙ ПОСЛЕ ОТКРЫВАЮЩЕГО ТЕГА!
require_once '../config.php';

// Если пользователь уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Пожалуйста, заполните все поля';
    } else {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT id, email, password, full_name, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    
    if ($user['role'] === 'admin') {
        header('Location: /admin/index.php');
    } elseif ($user['role'] === 'operator') {
        header('Location: /pages/operator_dashboard.php');
    } else {
        header('Location: /pages/profile.php');
    }
    exit;
}
            else {
                $error = 'Неверный email или пароль';
            }
        } catch (PDOException $e) {
            $error = 'Ошибка базы данных';
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
        
        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: #888;">
            Нет аккаунта? <a href="/pages/register.php" style="color: #e94560;">Зарегистрироваться</a>
        </p>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>