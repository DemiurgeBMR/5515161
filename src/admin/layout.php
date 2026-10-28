<?php
/**
 * Каркас админки: меню, шапка, уведомления после действий.
 *
 * Каждая страница админки начинается так:
 *     require __DIR__ . '/../../src/bootstrap.php';
 *     require HOTEL_ROOT . '/src/admin/layout.php';
 *     $me = require_staff();
 * и выводит admin_header('Заголовок', 'раздел') … admin_footer().
 */

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/** Сообщение до следующей страницы (после POST → redirect). */
function flash(string $type, string $message): void
{
    session_boot();
    $_SESSION['flash'][] = [$type, $message];
}

function flashes_html(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $message]) {
        $out .= '<div class="notice notice--' . ($type === 'error' ? 'error' : 'ok') . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
            . icon($type === 'error' ? 'info' : 'check') . '<div>' . e($message) . '</div></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/** Счётчики для меню: новые заявки, сообщения гостей, двойные продажи. */
function admin_counters(): array
{
    return [
        'new'       => (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'new'"),
        'messages'  => (int) q_val('SELECT COUNT(*) FROM booking_messages WHERE handled_at IS NULL'),
        'conflicts' => count(ical_conflicts()),
    ];
}

function admin_nav(): array
{
    $nav = [
        'today'    => ['/admin/', 'Сегодня', 'house'],
        'calendar' => ['/admin/calendar.php', 'Шахматка', 'calendar-days'],
        'bookings' => ['/admin/bookings.php', 'Брони', 'bed-double'],
        'guests'   => ['/admin/guests.php', 'Гости', 'users'],
        'rooms'    => ['/admin/rooms.php', 'Номера и фото', 'key-round'],
        'prices'   => ['/admin/prices.php', 'Цены', 'sparkles'],
        'reports'  => ['/admin/reports.php', 'Отчёты', 'file-text'],
        'channels' => ['/admin/channels.php', 'Площадки', 'navigation'],
    ];
    if (is_owner()) {
        $nav['users'] = ['/admin/users.php', 'Сотрудники', 'shield-check'];
    }
    $nav['journal'] = ['/admin/journal.php', 'Журнал', 'clock'];
    return $nav;
}

function admin_header(string $title, string $active = '', string $bodyClass = ''): void
{
    $me = current_staff();
    $c = admin_counters();
    $badges = ['today' => $c['messages'] + $c['conflicts'], 'bookings' => $c['new']];
    cron_maybe_run();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title) ?> — админка <?= e(mail_hotel_name()) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2b221d">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="adm <?= e($bodyClass) ?>" data-version="<?= e(setting('data_version', '0')) ?>">
<?php readfile(HOTEL_ROOT . '/src/partials/icons.svg'); ?>
<a class="skip-link" href="#main">Перейти к содержимому</a>
<div class="adm-shell">
    <aside class="adm-side" id="adm-nav" data-adm-nav>
        <a class="adm-brand" href="/admin/">
            <span class="logo__name"><?= e(hotel()['name']) ?></span>
            <span class="adm-brand__sub">Администрирование</span>
        </a>
        <nav aria-label="Разделы админки">
            <ul class="adm-menu">
<?php foreach (admin_nav() as $key => [$href, $label, $ic]): ?>
                <li><a class="adm-menu__link<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                    <?= icon($ic) ?><span><?= e($label) ?></span>
<?php if (!empty($badges[$key])): ?>
                    <b class="adm-badge" data-badge="<?= e($key) ?>"><?= (int) $badges[$key] ?></b>
<?php endif; ?>
                </a></li>
<?php endforeach; ?>
            </ul>
        </nav>
        <div class="adm-side__foot">
            <a class="adm-me" href="/admin/profile.php"><?= icon('users') ?><span><?= e($me['name']) ?><small><?= e(STAFF_ROLES[$me['role']] ?? '') ?></small></span></a>
            <a class="adm-side__site" href="/" target="_blank" rel="noopener">Открыть сайт</a>
            <form method="post" action="/admin/logout.php">
                <?= csrf_field() ?>
                <button class="link-button" type="submit">Выйти</button>
            </form>
        </div>
    </aside>
    <div class="adm-main">
        <header class="adm-top">
            <button class="nav-toggle adm-top__toggle" type="button" aria-expanded="false" aria-controls="adm-nav" data-adm-toggle>
                <span class="nav-toggle__open"><?= icon('menu') ?></span>
                <span class="nav-toggle__close"><?= icon('x') ?></span>
                <span class="visually-hidden">Меню</span>
            </button>
            <span class="adm-top__title"><?= e($title) ?></span>
            <a class="btn btn--primary btn--sm" href="/admin/booking.php?new=1"><?= icon('calendar-days') ?><span class="adm-hide-sm">Новая бронь</span><span class="adm-show-sm">Бронь</span></a>
        </header>
        <div class="adm-refresh" data-adm-refresh hidden>
            <span>Появились новые данные — например, новая заявка.</span>
            <button class="btn btn--light btn--sm" type="button" data-adm-reload>Обновить</button>
        </div>
        <main id="main" class="adm-content">
            <?= flashes_html() ?>
<?php
}

function admin_footer(): void
{
    ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body>
</html>
<?php
}

const AUDIT_ENTITIES = [
    'booking' => 'Бронь', 'guest' => 'Гость', 'room' => 'Комната', 'room_type' => 'Тип номера',
    'price' => 'Цены', 'settings' => 'Настройки', 'staff' => 'Сотрудник',
];

/** Понятное название действия из журнала: «Оплата», «Комната закрыта»… */
function audit_action_label(string $entity, string $action): string
{
    $labels = [
        'booking' => [
            'created' => 'Создана', 'updated' => 'Изменена', 'status' => 'Статус', 'payment' => 'Оплата', 'refund' => 'Возврат',
            'payment_deleted' => 'Удалён платёж', 'guest_message' => 'Сообщение гостя', 'message_handled' => 'Сообщение обработано',
            'mail' => 'Почта', 'reminder_sent' => 'Напоминание о заезде', 'deleted' => 'Удалена',
        ],
        'guest' => ['updated' => 'Изменены данные', 'anonymized' => 'Данные обезличены', 'waitlist_cleared' => 'Очищен лист ожидания'],
        'room' => ['created' => 'Добавлена', 'updated' => 'Изменена', 'deleted' => 'Удалена', 'closed' => 'Закрыта для продажи', 'opened' => 'Открыта для продажи', 'ical' => 'Ссылка площадки'],
        'room_type' => ['created' => 'Добавлен', 'updated' => 'Изменён', 'photos_added' => 'Добавлены фото'],
        'price' => ['base_price' => 'Цена за ночь', 'rule_added' => 'Добавлена сезонная цена', 'rule_deleted' => 'Удалена сезонная цена', 'discounts' => 'Скидки за срок', 'prepayment' => 'Предоплата'],
        'settings' => ['site_photo' => 'Фото на сайте', 'updated' => 'Изменены', 'export' => 'Выгрузка в Excel'],
        'staff' => [
            'login' => 'Вход', 'created' => 'Добавлен', 'updated' => 'Изменён', 'password_reset' => 'Сброшен пароль',
            'password_changed' => 'Сменил пароль',
        ],
    ];
    return $labels[$entity][$action] ?? $action;
}

/** Плашка статуса брони. */
function status_badge(string $status): string
{
    return '<span class="status status--' . e($status) . '">' . e(BOOKING_STATUSES[$status] ?? $status) . '</span>';
}

/** Телефон гостя ссылкой «позвонить» (и WhatsApp рядом). */
function guest_contacts_html(?string $phone, ?string $email): string
{
    $out = [];
    if ($phone) {
        $tel = tel_href($phone);
        $out[] = $tel ? '<a href="' . e($tel) . '">' . e($phone) . '</a>' : e($phone);
        $wa = whatsapp_href('', $phone);
        if ($wa) {
            $out[] = '<a href="' . e($wa) . '" target="_blank" rel="noopener">WhatsApp</a>';
        }
    }
    if ($email) {
        $out[] = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>';
    }
    return $out ? implode(' · ', $out) : '<span class="muted">нет контактов</span>';
}

/** Поле даты для фильтров и форм: значение или пусто. */
function date_value(?string $d): string
{
    return $d && parse_date($d) ? $d : '';
}

/** Брони по условию — для списков на «Сегодня» и в карточке гостя. */
function bookings_where(string $where, array $params = [], string $order = 'b.check_in, b.id', int $limit = 100): array
{
    return array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (int) $limit, $params));
}

/** Строка брони в списке. */
function booking_row_html(array $b, string $extra = ''): string
{
    $flags = [];
    if ($b['open_messages'] > 0) {
        $flags[] = 'сообщение гостя';
    }
    if (in_array($b['status'], ['new', 'confirmed'], true) && $b['prepayment'] > 0 && $b['paid'] < $b['prepayment']) {
        $flags[] = 'нет предоплаты';
    }
    return '<a class="brow" href="/admin/booking.php?id=' . $b['id'] . '">'
        . '<span class="brow__name">' . e($b['guest_name'] ?? 'Гость') . ' <span class="muted">' . e(booking_number($b)) . '</span></span>'
        . status_badge($b['status'])
        . '<span class="brow__meta">' . e(ru_date_range($b['check_in'], $b['check_out'])) . ' · ' . e(nights_label($b['nights'])) . ' · '
        . e($b['room_name'] ?? $b['type_name']) . ($extra !== '' ? ' · ' . e($extra) : '')
        . ($flags ? ' · <span class="brow__flag">' . e(implode(', ', $flags)) . '</span>' : '') . '</span>'
        . '</a>';
}
