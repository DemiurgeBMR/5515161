<?php
/**
 * Мобильная «оболочка» сайта: нижняя панель вкладок + шторка «Профиль/Ещё».
 *
 * Подключается из includes/footer.php на всех страницах. На экранах шире 768px
 * всё это скрыто (display:none из assets/css/layout/_mobile-shell.css), десктопная
 * версия не меняется. Поведение — assets/js/rr-mobile.js.
 *
 * Состав вкладок зависит от роли — правится в rr_mobile_nav_config() ниже:
 *   гость       — Главная · Каталог · Карта · Войти · Ещё
 *   оператор    — Каталог · Избранное · Заявки · Выезды · Профиль
 *   собственник — Места · Заявки · ➕ · Выезды · Профиль
 *   админ       — Модерация · Локации · Люди · Заказы · Ещё
 *
 * Страница, которой нижняя панель мешает (чат с полем ввода, карточка локации с
 * липкой кнопкой), ставит на свой <body> класс "m-no-tabbar".
 */

if (!function_exists('rr_mobile_nav_config')) {

    /**
     * @return array{tabs: array, menu: array, role_label: string}
     * Вкладка: key, href, icon, label, match (пути страниц, на которых она подсвечена),
     * badge (ключ счётчика: 'apps' — непрочитанные сообщения), kind ('link'|'add'|'sheet').
     * Пункт шторки: href, icon, label, [accent].
     */
    function rr_mobile_nav_config($role) {
        $sheetTab = ['key' => 'profile', 'href' => '#', 'icon' => 'user', 'label' => 'Профиль', 'match' => [], 'kind' => 'sheet'];
        $moreTab  = ['key' => 'more',    'href' => '#', 'icon' => 'menu', 'label' => 'Ещё',     'match' => [], 'kind' => 'sheet'];

        $site = [
            ['href' => '/pages/map.php',          'icon' => 'map',         'label' => 'Карта локаций'],
            ['href' => '/pages/how_it_works.php', 'icon' => 'help-circle', 'label' => 'Как это работает'],
        ];

        if ($role === 'operator') {
            return [
                'role_label' => 'Арендатор',
                'tabs' => [
                    ['key' => 'catalog',   'href' => '/pages/catalog.php',               'icon' => 'search',         'label' => 'Каталог',   'kind' => 'link',
                     'match' => ['pages/catalog.php', 'pages/map.php', 'pages/location.php', 'pages/send_application.php']],
                    ['key' => 'favorites', 'href' => '/pages/operator_favorites.php',    'icon' => 'heart',          'label' => 'Избранное', 'kind' => 'link',
                     'match' => ['pages/operator_favorites.php']],
                    ['key' => 'apps',      'href' => '/pages/operator_applications.php', 'icon' => 'message-circle', 'label' => 'Заявки',    'kind' => 'link', 'badge' => 'apps',
                     'match' => ['pages/operator_applications.php', 'pages/application_chat.php', 'pages/delete_application.php']],
                    ['key' => 'calendar',  'href' => '/pages/events_calendar.php',       'icon' => 'calendar',       'label' => 'Выезды',    'kind' => 'link',
                     'match' => ['pages/events_calendar.php']],
                    $sheetTab,
                ],
                'menu' => array_merge([
                    ['href' => '/pages/operator_dashboard.php', 'icon' => 'layout-dashboard', 'label' => 'Дашборд'],
                    ['href' => '/pages/operator_locations.php', 'icon' => 'map-pin',          'label' => 'Мои точки'],
                    ['href' => '/pages/documents.php',          'icon' => 'file-text',        'label' => 'Документы'],
                    ['href' => '/pages/subscription.php',       'icon' => 'card',             'label' => 'Подписка и баланс'],
                    ['href' => '/pages/edit_profile.php',       'icon' => 'settings',         'label' => 'Настройки'],
                ], $site),
            ];
        }

        if ($role === 'owner') {
            return [
                'role_label' => 'Собственник',
                'tabs' => [
                    ['key' => 'places',   'href' => '/pages/profile.php',            'icon' => 'building',       'label' => 'Места',    'kind' => 'link',
                     'match' => ['pages/profile.php', 'pages/edit_location.php', 'pages/location.php']],
                    ['key' => 'apps',     'href' => '/pages/owner_applications.php', 'icon' => 'message-circle', 'label' => 'Заявки',   'kind' => 'link', 'badge' => 'apps',
                     'match' => ['pages/owner_applications.php', 'pages/application_chat.php']],
                    ['key' => 'add',      'href' => '/pages/add_location.php',       'icon' => 'plus',           'label' => 'Добавить', 'kind' => 'add',
                     'match' => ['pages/add_location.php']],
                    ['key' => 'calendar', 'href' => '/pages/events_calendar.php',    'icon' => 'calendar',       'label' => 'Выезды',   'kind' => 'link',
                     'match' => ['pages/events_calendar.php']],
                    $sheetTab,
                ],
                'menu' => array_merge([
                    ['href' => '/pages/owner_operators.php', 'icon' => 'users',     'label' => 'Мои операторы'],
                    ['href' => '/pages/documents.php',       'icon' => 'file-text', 'label' => 'Документы'],
                    ['href' => '/pages/edit_profile.php',    'icon' => 'settings',  'label' => 'Настройки'],
                    ['href' => '/pages/catalog.php',         'icon' => 'search',    'label' => 'Каталог локаций'],
                ], $site),
            ];
        }

        if ($role === 'admin') {
            return [
                'role_label' => 'Администратор',
                'tabs' => [
                    ['key' => 'mod',    'href' => '/admin/index.php',          'icon' => 'shield',    'label' => 'Модерация', 'kind' => 'link',
                     'match' => ['admin/index.php', 'admin/preview_revision.php', 'admin/view_revisions.php']],
                    ['key' => 'locs',   'href' => '/admin/locations.php',      'icon' => 'map-pin',   'label' => 'Локации',   'kind' => 'link',
                     'match' => ['admin/locations.php']],
                    ['key' => 'users',  'href' => '/admin/users.php',          'icon' => 'users',     'label' => 'Люди',      'kind' => 'link',
                     'match' => ['admin/users.php']],
                    ['key' => 'orders', 'href' => '/admin/service_orders.php', 'icon' => 'file-text', 'label' => 'Заказы',    'kind' => 'link',
                     'match' => ['admin/service_orders.php']],
                    $moreTab,
                ],
                'menu' => [
                    ['href' => '/admin/geocode_backfill.php', 'icon' => 'globe',  'label' => 'Геокодирование'],
                    ['href' => '/admin/fix_main_photos.php',  'icon' => 'camera', 'label' => 'Починка фото'],
                    ['href' => '/pages/catalog.php',          'icon' => 'search', 'label' => 'Каталог локаций'],
                ],
            ];
        }

        // Гость
        return [
            'role_label' => '',
            'tabs' => [
                ['key' => 'home',    'href' => '/',                  'icon' => 'home',   'label' => 'Главная', 'kind' => 'link', 'match' => ['index.php']],
                ['key' => 'catalog', 'href' => '/pages/catalog.php', 'icon' => 'search', 'label' => 'Каталог', 'kind' => 'link',
                 'match' => ['pages/catalog.php', 'pages/location.php']],
                ['key' => 'map',     'href' => '/pages/map.php',     'icon' => 'map',    'label' => 'Карта',   'kind' => 'link', 'match' => ['pages/map.php']],
                ['key' => 'login',   'href' => '/pages/login.php',   'icon' => 'user',   'label' => 'Войти',   'kind' => 'link',
                 'match' => ['pages/login.php', 'pages/register.php', 'pages/forgot_password.php', 'pages/reset_password.php', 'pages/verify_2fa.php']],
                $moreTab,
            ],
            'menu' => [
                ['href' => '/pages/register.php',       'icon' => 'plus-circle', 'label' => 'Регистрация', 'accent' => true],
                ['href' => '/pages/how_it_works.php',   'icon' => 'help-circle', 'label' => 'Как это работает'],
                ['href' => '/pages/subscription.php',   'icon' => 'card',        'label' => 'Тарифы'],
                ['href' => '/pages/privacy_policy.php', 'icon' => 'lock',        'label' => 'Конфиденциальность'],
                ['href' => '/pages/terms.php',          'icon' => 'file-text',   'label' => 'Соглашение'],
            ],
        ];
    }

    /** Непрочитанные сообщения в чатах заявок — для бейджа вкладки «Заявки». Любая ошибка = 0 (страница важнее бейджа). */
    function rr_mobile_unread_messages($userId) {
        try {
            $stmt = getDbConnection()->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

$rrM_role    = $_SESSION['user_role'] ?? null;
$rrM_uid     = $_SESSION['user_id'] ?? null;
$rrM_cfg     = rr_mobile_nav_config($rrM_uid ? $rrM_role : null);
$rrM_script  = ltrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
$rrM_unread  = $rrM_uid ? rr_mobile_unread_messages($rrM_uid) : 0;
$rrM_name    = $_SESSION['user_name'] ?? '';
$rrM_credits = ($rrM_uid && $rrM_role === 'operator') ? rr_credits_summary(getDbConnection(), $rrM_uid) : null;

// Подсвеченная вкладка: та, у которой страница в match; если ни у одной — шторка «Профиль/Ещё»
// (все разделы кабинета, которых нет на панели, живут в ней).
$rrM_active = null;
foreach ($rrM_cfg['tabs'] as $t) {
    if (in_array($rrM_script, $t['match'] ?? [], true)) { $rrM_active = $t['key']; break; }
}
if ($rrM_active === null) {
    foreach ($rrM_cfg['tabs'] as $t) { if (($t['kind'] ?? '') === 'sheet') { $rrM_active = $t['key']; break; } }
}
?>
    <!-- Мобильная оболочка (≤768px): нижняя панель + шторка меню. Скрыто на широких экранах. -->
    <nav class="m-tabbar" id="mTabbar" aria-label="Основная навигация">
<?php foreach ($rrM_cfg['tabs'] as $t):
    $isSheet = ($t['kind'] ?? '') === 'sheet';
    $isActive = $rrM_active === $t['key'];
    $cls = 'm-tab' . ($isActive ? ' is-active' : '') . (($t['kind'] ?? '') === 'add' ? ' m-tab-add' : '');
    $badgeCount = (($t['badge'] ?? '') === 'apps') ? $rrM_unread : 0;
    $inner = '<span class="m-tab-ic">' . rr_icon($t['icon'])
        . (isset($t['badge']) ? '<i class="m-badge" data-m-badge="' . htmlspecialchars($t['badge']) . '"' . ($badgeCount > 0 ? '' : ' hidden') . '>' . ($badgeCount > 99 ? '99+' : $badgeCount) . '</i>' : '')
        . '</span><span class="m-tab-lb">' . htmlspecialchars($t['label']) . '</span>';
    if ($isSheet): ?>
        <button type="button" class="<?php echo $cls; ?>" data-m-sheet-open="mMenuSheet" aria-haspopup="dialog" aria-controls="mMenuSheet"><?php echo $inner; ?></button>
<?php else: ?>
        <a href="<?php echo htmlspecialchars($t['href']); ?>" class="<?php echo $cls; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>><?php echo $inner; ?></a>
<?php endif; endforeach; ?>
    </nav>

    <div class="m-sheet-backdrop" id="mSheetBackdrop" hidden></div>
    <div class="m-sheet m-menu-sheet" id="mMenuSheet" role="dialog" aria-modal="true" aria-labelledby="mMenuTitle" aria-hidden="true">
        <div class="m-sheet-handle" aria-hidden="true"></div>
        <div class="m-sheet-head">
            <?php if ($rrM_uid): ?>
                <span class="m-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($rrM_name !== '' ? $rrM_name : 'А', 0, 1))); ?></span>
                <span class="m-sheet-who">
                    <b id="mMenuTitle"><?php echo htmlspecialchars($rrM_name !== '' ? $rrM_name : 'Аккаунт'); ?></b>
                    <small><?php echo htmlspecialchars($rrM_cfg['role_label']); ?><?php if ($rrM_credits !== null): ?> · <?php echo (int) $rrM_credits['total_available']; ?> <?php echo rr_plural_ru($rrM_credits['total_available'], 'контакт', 'контакта', 'контактов'); ?><?php endif; ?></small>
                </span>
            <?php else: ?>
                <b id="mMenuTitle" class="m-sheet-title">Меню</b>
            <?php endif; ?>
            <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
        </div>
        <div class="m-sheet-body">
            <ul class="m-menu">
<?php if ($rrM_uid): ?>
                <li><a href="/pages/notifications.php" class="m-menu-item"><?php echo rr_icon('bell'); ?><span>Уведомления</span><i class="m-badge" data-m-badge="notif" hidden>0</i><?php echo rr_icon('chevron-right', 'm-menu-go'); ?></a></li>
<?php endif; ?>
<?php foreach ($rrM_cfg['menu'] as $it): ?>
                <li><a href="<?php echo htmlspecialchars($it['href']); ?>" class="m-menu-item<?php echo !empty($it['accent']) ? ' is-accent' : ''; ?>"><?php echo rr_icon($it['icon']); ?><span><?php echo htmlspecialchars($it['label']); ?></span><?php echo rr_icon('chevron-right', 'm-menu-go'); ?></a></li>
<?php endforeach; ?>
<?php if ($rrM_uid && rr_onboarding_applies($rrM_role)): ?>
                <li><a href="/pages/how_it_works.php" class="m-menu-item" id="rrTourRestartM" data-rr-tour-start><?php echo rr_icon('lightbulb'); ?><span>Обучение по сайту</span><?php echo rr_icon('chevron-right', 'm-menu-go'); ?></a></li>
<?php endif; ?>
                <li><button type="button" class="m-menu-item" id="mThemeToggle"><?php echo rr_icon('moon'); ?><span>Тёмная тема</span><span class="m-switch" aria-hidden="true"><i></i></span></button></li>
<?php if ($rrM_uid): ?>
                <li><a href="/pages/logout.php" class="m-menu-item is-danger"><?php echo rr_icon('log-out'); ?><span>Выйти</span></a></li>
<?php endif; ?>
            </ul>
        </div>
    </div>
