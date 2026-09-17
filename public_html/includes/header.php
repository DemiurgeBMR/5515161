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
    <a href="#main-content" class="skip-link">Перейти к содержимому</a>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">RR</a>
            
            <nav class="nav">
                <a href="/pages/catalog.php">Локации</a>
                <a href="/pages/map.php">Карта</a>
                <a href="/pages/how_it_works.php">Как это работает</a>

<?php if (isset($_SESSION['user_id'])): ?>
    <?php
    $role = $_SESSION['user_role'] ?? null;
    if ($role === 'operator') {
        $profileLink = '/pages/operator_dashboard.php';
        $profileLabel = 'Дашборд';
        $profileIcon = 'layout-dashboard';
        $roleLabel = 'Арендатор';
    } elseif ($role === 'admin') {
        $profileLink = '/admin/index.php';
        $profileLabel = 'Админка';
        $profileIcon = 'shield';
        $roleLabel = 'Администратор';
    } else {
        $profileLink = '/pages/profile.php';
        $profileLabel = 'Профиль';
        $profileIcon = 'edit';
        $roleLabel = 'Владелец';
    }
    ?>
    <?php if ($role === 'owner'): ?>
        <a href="/pages/add_location.php" class="nav-link-spaced accent"><?php echo rr_icon('plus-circle'); ?> Добавить место</a>
    <?php endif; ?>
    <!-- Меню аккаунта: профиль/дашборд, подписка, выход — раньше были отдельными
         ссылками вподряд и не помещались в шапку на узких экранах. -->
    <div class="account-menu-wrap">
        <button type="button" id="accountMenuBtn" class="account-menu-btn" aria-haspopup="true" aria-expanded="false">
            <span class="account-menu-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Аккаунт'); ?></span>
            <?php echo rr_icon('chevron-down', 'account-menu-chevron'); ?>
        </button>
        <div id="accountMenuDropdown" class="account-dropdown">
            <div class="account-dd-header">
                <div class="account-dd-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Аккаунт'); ?></div>
                <div class="account-dd-role"><?php echo htmlspecialchars($roleLabel); ?></div>
            </div>
            <a href="<?php echo $profileLink; ?>" class="account-dd-item"><?php echo rr_icon($profileIcon); ?> <?php echo htmlspecialchars($profileLabel); ?></a>
            <?php if ($role !== 'admin'): ?>
                <a href="/pages/subscription.php" class="account-dd-item<?php echo currentUserHasSubscription() ? ' subscribed' : ''; ?>"><?php echo rr_icon('card'); ?> Подписка</a>
            <?php endif; ?>
            <div class="account-dd-divider"></div>
            <a href="/pages/logout.php" class="account-dd-item danger"><?php echo rr_icon('log-out'); ?> Выйти</a>
        </div>
    </div>
<?php else: ?>
    <!-- Гость -->
    <a href="/pages/login.php" class="nav-link-spaced">Вход</a>
    <a href="/pages/register.php" class="btn-nav">Регистрация</a>
<?php endif; ?>
<?php if (isset($_SESSION['user_id'])): ?>
    <!-- Уведомления: колокольчик открывает превью последних, полная лента — на pages/notifications.php.
         Рядом с переключателем темы — оба маленькие круглые кнопки-иконки в конце шапки. -->
    <div class="notification-bell-wrap">
        <button type="button" id="notificationBellBtn" class="notification-bell" aria-label="Уведомления">
            <?php echo rr_icon('bell'); ?>
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
<?php endif; ?>
                <button type="button" id="themeToggleBtn" class="theme-toggle-btn" title="Переключить тему" aria-label="Переключить светлую/тёмную тему"><?php echo rr_icon('moon'); ?></button>
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
            var ICON_SUN = '<svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
            var ICON_MOON = '<svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/></svg>';
            function updateIcon() {
                btn.innerHTML = isLight() ? ICON_SUN : ICON_MOON;
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
    <script>
        (function() {
            var btn = document.getElementById('accountMenuBtn');
            var dropdown = document.getElementById('accountMenuDropdown');
            if (!btn || !dropdown) return;
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var isOpen = dropdown.classList.toggle('open');
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                    dropdown.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    </script>
    <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? null) !== 'admin'): ?>
        <?php if (!empty($_SESSION['verify_link'])): ?>
            <!-- Почта пока не настроена — ссылка подтверждения показывается прямо
                 здесь один раз, сразу после регистрации или запроса новой ссылки. -->
            <div class="verify-banner verify-banner-link">
                <div class="container verify-banner-inner">
                    <span class="verify-banner-icon"><?php echo rr_icon('mail'); ?></span>
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
                    <span class="verify-banner-icon"><?php echo rr_icon('mail'); ?></span>
                    <span class="verify-banner-text">Email ещё не подтверждён.</span>
                    <a href="/pages/resend_verification.php?csrf=<?php echo urlencode(csrf_token()); ?>" class="verify-banner-link-action">Получить ссылку →</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (basename($_SERVER['SCRIPT_NAME']) !== 'how_it_works.php'): ?>
    <div class="howitworks-banner" id="howItWorksBanner">
        <div class="container howitworks-banner-inner">
            <span class="howitworks-banner-icon"><?php echo rr_icon('info-circle'); ?></span>
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
    <main id="main-content">