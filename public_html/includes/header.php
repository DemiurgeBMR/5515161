<?php require_once __DIR__ . '/cron_check.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- FullCalendar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/ru.js"></script>
    <script>
    // Переменная, указывающая, авторизован ли пользователь
    window.userLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
    function updateNotificationCount() {
        if (!window.userLoggedIn) return;
        fetch('/api/get_notifications.php?action=count')
            .then(response => response.json())
            .then(data => {
                var badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (data.count > 0) {
                        badge.textContent = data.count;
                        badge.style.display = 'block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(err => console.error('Ошибка получения уведомлений', err));
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateNotificationCount();
        setInterval(updateNotificationCount, 30000);
    });
    </script>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">RR</a>
            
            <nav class="nav">
                <a href="/pages/catalog.php">Локации</a>
                <a href="/pages/map.php">Карта</a>

                <!-- ★★★ ПОИСК ★★★ -->
                <form action="/pages/search.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Поиск по ID или городу..." 
                           value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>">
                    <button type="submit">🔍</button>
                </form>
                
<?php if (isset($_SESSION['user_id'])): ?>
    <!-- Уведомления -->
    <a href="/pages/notifications.php" class="notification-bell" style="position:relative; color:white; text-decoration:none; margin-right:15px; font-size:20px;">
        🔔
        <span id="notificationBadge" style="position:absolute; top:-8px; right:-8px; background:#e94560; color:white; border-radius:50%; padding:0 6px; font-size:11px; line-height:18px; min-width:18px; text-align:center; display:none;">0</span>
    </a>

    <?php if ($_SESSION['user_role'] === 'admin'): ?>
        <!-- Админ -->
        <a href="/admin/index.php" style="color: #ffd700; margin-right: 15px; text-decoration: none;">⚙️ Админка</a>
        <a href="/pages/logout.php" style="color: #ff6b6b; text-decoration: none;">Выйти</a>
    <?php else: ?>
        <!-- Обычный пользователь (оператор или собственник) -->
<?php
$profileLink = '/pages/profile.php';
if ($_SESSION['user_role'] === 'operator') {
    $profileLink = '/pages/operator_dashboard.php';
} elseif ($_SESSION['user_role'] === 'admin') {
    $profileLink = '/admin/index.php';
}
?>
<a href="<?php echo $profileLink; ?>" style="color: white; text-decoration: none; margin-right: 15px;">
    👋 <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Пользователь'); ?>
</a>
        <?php if ($_SESSION['user_role'] === 'owner'): ?>
            <a href="/pages/add_location.php" style="color: #e94560; margin-right: 15px; text-decoration: none;">➕ Добавить место</a>
        <?php endif; ?>
        <a href="/pages/logout.php" style="color: #ff6b6b; text-decoration: none;">Выйти</a>
    <?php endif; ?>
<?php else: ?>
    <!-- Гость -->
    <a href="/pages/login.php" style="color: white; margin-right: 15px; text-decoration: none;">Вход</a>
    <a href="/pages/register.php" style="background: #e94560; padding: 8px 20px; border-radius: 6px; color: white; text-decoration: none;">Регистрация</a>
<?php endif; ?>
            </nav>
        </div>
    </header>
    <main>