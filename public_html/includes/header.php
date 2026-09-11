<?php require_once __DIR__ . '/cron_check.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        // Ставим сохранённую тему ДО загрузки CSS, иначе при light-теме
        // страница на долю секунды мигнёт тёмной (по умолчанию) — это
        // должно быть первым, что выполняется в <head>.
        (function() {
            try {
                var saved = localStorage.getItem('rr_theme');
                if (saved === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch (e) {}
        })();
    </script>
    <title><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- FullCalendar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/ru.js"></script>
    <script>
    // Авторизован ли пользователь — единый поллер (assets/js/notifications.js)
    // сам решает, запускаться ли, а бейдж/тосты для гостя ему не нужны.
    window.userLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
    </script>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">RR</a>
            
            <nav class="nav">
                <a href="/pages/catalog.php">Локации</a>
                <a href="/pages/map.php">Карта</a>
                <a href="/pages/how_it_works.php">Как это работает</a>

<?php if (isset($_SESSION['user_id'])): ?>
    <!-- Уведомления: колокольчик открывает превью последних, полная лента — на pages/notifications.php -->
    <div class="notification-bell-wrap">
        <button type="button" id="notificationBellBtn" class="notification-bell" aria-label="Уведомления">
            🔔
            <span id="notificationBadge" class="notification-badge">0</span>
        </button>
        <div id="notificationDropdown" class="notif-dropdown">
            <div class="notif-dd-header">
                <span>Уведомления</span>
                <a href="#" id="notifDdMarkAll">Прочитать всё</a>
            </div>
            <div id="notificationDropdownList" class="notif-dd-list">
                <div class="notif-dd-empty">Загрузка…</div>
            </div>
            <a href="/pages/notifications.php" class="notif-dd-footer">Смотреть все →</a>
        </div>
    </div>

    <?php if (($_SESSION['user_role'] ?? null) === 'admin'): ?>
        <!-- Админ -->
        <a href="/admin/index.php" style="color: #ffd700; margin-right: 15px; text-decoration: none;">⚙️ Админка</a>
        <a href="/pages/logout.php" style="color: #ff6b6b; text-decoration: none;">Выйти</a>
    <?php else: ?>
        <!-- Обычный пользователь (оператор или собственник) -->
<?php
$profileLink = '/pages/profile.php';
if (($_SESSION['user_role'] ?? null) === 'operator') {
    $profileLink = '/pages/operator_dashboard.php';
} elseif (($_SESSION['user_role'] ?? null) === 'admin') {
    $profileLink = '/admin/index.php';
}
?>
<a href="<?php echo $profileLink; ?>" style="color: var(--text, #f2f2f5); text-decoration: none; margin-right: 15px;">
    👋 <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Пользователь'); ?>
</a>
        <?php if (($_SESSION['user_role'] ?? null) === 'owner'): ?>
            <a href="/pages/add_location.php" style="color: #e94560; margin-right: 15px; text-decoration: none;">➕ Добавить место</a>
        <?php endif; ?>
        <a href="/pages/subscription.php" style="color: <?php echo currentUserHasSubscription() ? '#2ecc71' : 'var(--text, #f2f2f5)'; ?>; margin-right: 15px; text-decoration: none;">💳 Подписка</a>
        <a href="/pages/logout.php" style="color: #ff6b6b; text-decoration: none;">Выйти</a>
    <?php endif; ?>
<?php else: ?>
    <!-- Гость -->
    <a href="/pages/login.php" style="color: var(--text, #f2f2f5); margin-right: 15px; text-decoration: none;">Вход</a>
    <a href="/pages/register.php" style="background: #e94560; padding: 8px 20px; border-radius: 6px; color: white; text-decoration: none;">Регистрация</a>
<?php endif; ?>
                <button type="button" id="themeToggleBtn" class="theme-toggle-btn" title="Переключить тему" aria-label="Переключить светлую/тёмную тему">🌙</button>
            </nav>
        </div>
    </header>
    <script>
        (function() {
            var KEY = 'rr_theme';
            var btn = document.getElementById('themeToggleBtn');
            if (!btn) return;
            function isLight() {
                return document.documentElement.getAttribute('data-theme') === 'light';
            }
            function updateIcon() {
                btn.textContent = isLight() ? '☀️' : '🌙';
            }
            updateIcon();
            btn.addEventListener('click', function() {
                var next = isLight() ? 'dark' : 'light';
                if (next === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
                try { localStorage.setItem(KEY, next); } catch (e) {}
                updateIcon();
            });
        })();
    </script>
    <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? null) !== 'admin'): ?>
        <?php if (!empty($_SESSION['verify_link'])): ?>
            <!-- Почта пока не настроена — ссылка подтверждения показывается прямо
                 здесь один раз, сразу после регистрации или запроса новой ссылки. -->
            <div class="verify-banner verify-banner-link">
                <div class="container verify-banner-inner">
                    <span class="verify-banner-icon">📧</span>
                    <span class="verify-banner-text">
                        Письма пока не отправляются — вот ссылка для подтверждения email:
                        <a href="<?php echo htmlspecialchars($_SESSION['verify_link']); ?>"><?php echo htmlspecialchars($_SESSION['verify_link']); ?></a>
                    </span>
                </div>
            </div>
            <?php unset($_SESSION['verify_link']); ?>
        <?php elseif (empty($_SESSION['is_verified'])): ?>
            <div class="verify-banner">
                <div class="container verify-banner-inner">
                    <span class="verify-banner-icon">📧</span>
                    <span class="verify-banner-text">Email ещё не подтверждён.</span>
                    <a href="/pages/resend_verification.php?csrf=<?php echo urlencode(csrf_token()); ?>" class="verify-banner-link-action">Получить ссылку →</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (basename($_SERVER['SCRIPT_NAME']) !== 'how_it_works.php'): ?>
    <div class="howitworks-banner" id="howItWorksBanner">
        <div class="container howitworks-banner-inner">
            <span class="howitworks-banner-icon">🎯</span>
            <span class="howitworks-banner-text">
                Новый на RR? Найдите точку или сдайте своё место в аренду — вся сделка проходит прямо на платформе.
            </span>
            <a href="/pages/how_it_works.php" class="howitworks-banner-link">Как это работает →</a>
            <button type="button" class="howitworks-banner-close" id="howItWorksBannerClose" aria-label="Закрыть">×</button>
        </div>
    </div>
    <script>
        (function() {
            var KEY = 'rr_hiw_banner_dismissed';
            var banner = document.getElementById('howItWorksBanner');
            if (!banner) return;
            try {
                if (localStorage.getItem(KEY)) {
                    banner.style.display = 'none';
                    return;
                }
            } catch (e) {}
            var closeBtn = document.getElementById('howItWorksBannerClose');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    banner.style.display = 'none';
                    try { localStorage.setItem(KEY, '1'); } catch (e) {}
                });
            }
        })();
    </script>
    <?php endif; ?>
    <main>