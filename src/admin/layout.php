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

/** Счётчики для меню: новые заявки, сообщения гостей, двойные продажи, уборка. */
function admin_counters(): array
{
    if (is_housekeeper()) {
        return ['new' => 0, 'messages' => 0, 'conflicts' => 0, 'bath' => 0, 'cleaning' => cleaning_due_count()];
    }
    return [
        'new'       => (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'new'"),
        'messages'  => (int) q_val('SELECT COUNT(*) FROM booking_messages WHERE handled_at IS NULL'),
        'conflicts' => count(ical_conflicts()),
        'bath'      => bath_new_count(),
        'cleaning'  => cleaning_due_count(),
    ];
}

/** Числа на пунктах меню. */
function admin_badges(array $c): array
{
    return ['today' => $c['messages'] + $c['conflicts'], 'bookings' => $c['new'], 'bath' => $c['bath'], 'cleaning' => $c['cleaning']];
}

function admin_nav(): array
{
    if (is_housekeeper()) {
        return ['cleaning' => ['/admin/cleaning.php', 'Уборка', 'spray-can']];
    }
    $nav = [
        'today'    => ['/admin/', 'Сегодня', 'house'],
        'calendar' => ['/admin/calendar.php', 'Шахматка', 'calendar-days'],
        'cleaning' => ['/admin/cleaning.php', 'Уборка', 'spray-can'],
        'bookings' => ['/admin/bookings.php', 'Брони', 'bed-double'],
        'bath'     => ['/admin/bath.php', 'Баня', 'flame'],
        'guests'   => ['/admin/guests.php', 'Гости', 'users'],
        'rooms'    => ['/admin/rooms.php', 'Номера и фото', 'key-round'],
        'prices'   => ['/admin/prices.php', 'Цены', 'sparkles'],
        'reports'  => ['/admin/reports.php', 'Отчёты', 'file-text'],
        'channels' => ['/admin/channels.php', 'Площадки', 'navigation'],
    ];
    if (!bath_enabled()) {
        unset($nav['bath']);
    }
    if (is_owner()) {
        $nav['users'] = ['/admin/users.php', 'Сотрудники', 'shield-check'];
    }
    $nav['journal'] = ['/admin/journal.php', 'Журнал', 'clock'];
    return $nav;
}

function admin_header(string $title, string $active = '', string $bodyClass = ''): void
{
    $me = current_staff();
    $badges = admin_badges(admin_counters());
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
        <a class="adm-brand" href="<?= is_housekeeper($me) ? '/admin/cleaning.php' : '/admin/' ?>">
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
<?php if (!is_housekeeper($me)): ?>
            <a class="adm-side__site" href="/" target="_blank" rel="noopener">Открыть сайт</a>
<?php endif; ?>
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
<?php if (!is_housekeeper($me)): ?>
            <a class="btn btn--primary btn--sm" href="/admin/booking.php?new=1"><?= icon('calendar-days') ?><span class="adm-hide-sm">Новая бронь</span><span class="adm-show-sm">Бронь</span></a>
<?php endif; ?>
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
    'price' => 'Цены', 'settings' => 'Настройки', 'staff' => 'Сотрудник', 'cleaning' => 'Уборка', 'bath' => 'Баня',
];

/** Понятное название действия из журнала: «Оплата», «Комната закрыта»… */
function audit_action_label(string $entity, string $action): string
{
    $labels = [
        'booking' => [
            'created' => 'Создана', 'updated' => 'Изменена', 'status' => 'Статус', 'payment' => 'Оплата', 'refund' => 'Возврат',
            'payment_deleted' => 'Удалён платёж', 'payment_voided' => 'Аннулирована оплата', 'guest_message' => 'Сообщение гостя', 'message_handled' => 'Сообщение обработано',
            'mail' => 'Почта', 'reminder_sent' => 'Напоминание о заезде', 'deleted' => 'Удалена',
        ],
        'guest' => ['updated' => 'Изменены данные', 'anonymized' => 'Данные обезличены', 'waitlist_cleared' => 'Очищен лист ожидания'],
        'room' => ['created' => 'Добавлена', 'updated' => 'Изменена', 'deleted' => 'Удалена', 'closed' => 'Закрыта для продажи', 'opened' => 'Открыта для продажи', 'ical' => 'Ссылка площадки'],
        'room_type' => ['created' => 'Добавлен', 'updated' => 'Изменён', 'photos_added' => 'Добавлены фото'],
        'price' => ['base_price' => 'Цена за ночь', 'rule_added' => 'Добавлена сезонная цена', 'rule_deleted' => 'Удалена сезонная цена', 'discounts' => 'Скидки за срок', 'prepayment' => 'Предоплата'],
        'settings' => ['site_photo' => 'Фото на сайте', 'updated' => 'Изменены', 'export' => 'Выгрузка в Excel'],
        'bath' => [
            'created' => 'Запись', 'updated' => 'Изменена', 'status' => 'Статус', 'payment' => 'Оплата', 'refund' => 'Возврат',
            'payment_voided' => 'Аннулирована оплата', 'mail' => 'Почта', 'reminder_sent' => 'Напоминание', 'deleted' => 'Удалена',
            'blocked' => 'Время закрыто', 'unblocked' => 'Время открыто', 'state' => 'Статус бани', 'settings' => 'Настройки',
            'photos_added' => 'Добавлены фото', 'photo_deleted' => 'Удалено фото', 'waitlist' => 'Лист ожидания',
        ],
        'cleaning' => ['done' => 'Убрано', 'skipped' => 'Не нужна', 'reopened' => 'Снята отметка', 'added' => 'Добавлена', 'settings' => 'Настройки'],
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

/**
 * Оплаты в карточке брони или записи в баню: список (с аннулированными),
 * подсказка и форма «Записать оплату» со сдачей. Удалить оплату нельзя.
 * Форма шлёт action=payment_add / payment_void и id=$targetId.
 */
function admin_payments_html(array $payments, int $targetId, ?int $suggest, string $notePlaceholder): string
{
    ob_start();
    ?>
<?php if ($payments): ?>
            <table class="table">
                <tbody>
<?php foreach ($payments as $p): $void = $p['voided_at'] !== null; $change = payment_change_text($p); ?>
                    <tr class="pay-row<?= $void ? ' pay-row--void' : '' ?>">
                        <td><?= e(ru_date($p['paid_on'])) ?> · <?= (int) $p['amount'] < 0 ? 'возврат' : 'оплата' ?><small><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $change !== '' ? ', ' . e($change) : '' ?><?= $p['note'] !== '' ? ', ' . e($p['note']) : '' ?>
                            <br>записал(а) <?= e($p['staff_name'] ?? 'система') ?>, <?= e(ru_datetime($p['created_at'])) ?></small>
<?php if ($void): ?>
                            <small class="pay-row__void">Аннулировано <?= e(ru_datetime($p['voided_at'])) ?>, <?= e($p['voided_by_name'] ?? '') ?>: <?= e($p['void_reason']) ?></small>
<?php endif; ?>
                        </td>
                        <td class="num"><span class="pay-row__sum"><?= (int) $p['amount'] < 0 ? '−' : '' ?><?= e(money(abs((int) $p['amount']))) ?></span></td>
                        <td class="num">
<?php if (!$void && is_owner()): ?>
                            <details class="pay-void">
                                <summary class="link-button">Аннулировать</summary>
                                <form method="post" class="pay-void__form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $targetId ?>">
                                    <input type="hidden" name="action" value="payment_void">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                    <label class="visually-hidden" for="void-<?= (int) $p['id'] ?>">Причина</label>
                                    <input id="void-<?= (int) $p['id'] ?>" name="reason" required maxlength="255" placeholder="Почему запись ошибочная">
                                    <button class="btn btn--danger btn--sm" type="submit" data-confirm="Аннулировать запись? Она останется в истории зачёркнутой.">Аннулировать</button>
                                </form>
                            </details>
<?php endif; ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
            <p class="hint">Записанную оплату удалить нельзя. <?= is_owner() ? 'Ошибочную запись можно аннулировать с указанием причины — она останется в истории.' : 'Если записали по ошибке — сообщите владельцу: он аннулирует запись с указанием причины.' ?></p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $targetId ?>">
                <input type="hidden" name="action" value="payment_add">
                <div class="field"><label for="p-amount">Сумма, ₽</label><input id="p-amount" name="amount" inputmode="numeric" required value="<?= $suggest ?? '' ?>"></div>
                <div class="field"><label for="p-method">Способ</label>
                    <select id="p-method" name="method">
<?php foreach (PAYMENT_METHODS as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="p-date">Дата</label><input id="p-date" name="paid_on" type="date" value="<?= e(today()->format('Y-m-d')) ?>"></div>
                <div class="field"><label for="p-kind">Это</label>
                    <select id="p-kind" name="kind"><option value="payment">Оплата</option><option value="refund">Возврат гостю</option></select>
                </div>
                <div class="field" data-cash-only><label for="p-received">Гость дал наличными, ₽</label><input id="p-received" name="received" inputmode="numeric" placeholder="Если нужна сдача"></div>
                <div class="field" data-cash-only><label for="p-change">Сдача гостю</label><output id="p-change" class="pay-change" for="p-amount p-received" data-pay-change>—</output></div>
                <div class="field span-all"><label for="p-note">Комментарий</label><input id="p-note" name="note" maxlength="255" placeholder="<?= e($notePlaceholder) ?>"></div>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit"><?= icon('banknote') ?>Записать</button></div>
            </form>
<?php
    return ob_get_clean();
}
