<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Избранное — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div style="max-width: 900px; margin: 40px auto; padding: 0 20px;">
        <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
        <h2>❤️ Избранные локации</h2>
        <p style="color: #888;">Функция в разработке. Здесь будут отображаться локации, которые вы добавили в избранное.</p>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>