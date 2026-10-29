<?php
/**
 * Запись в баню для гостя — по секретной ссылке из письма или сразу после
 * заявки: статус, время, гости, услуги, сумма, как добраться.
 */

require __DIR__ . '/../src/bootstrap.php';

header('Referrer-Policy: no-referrer'); // ссылка секретная — не отдаём её сайтам, на которые уходят со страницы
header('X-Robots-Tag: noindex, nofollow');

if (!db_ready()) {
    require __DIR__ . '/../src/partials/maintenance.php';
    exit;
}

$b = bath_by_token(get('t'));
if (!$b || $b['is_block']) {
    $b = null;
    http_response_code(404);
}
$h = hotel();
$s = bath_settings();

$statusText = $b ? [
    'new'       => 'Время уже держим за вами. Администратор подтвердит запись в течение ' . $h['response_time'] . ' (' . $h['admin_hours'] . ').',
    'confirmed' => 'Запись подтверждена — ждём вас! Приходите за 5–10 минут до начала.',
    'done'      => 'Спасибо, что были у нас. Приходите ещё!',
    'cancelled' => 'Запись отменена.' . ($b['cancel_reason'] !== '' ? ' Причина: ' . $b['cancel_reason'] . '.' : '') . ' Если это ошибка — позвоните нам.',
    'no_show'   => 'Мы не дождались вас. Если хотите выбрать другое время — позвоните.',
][$b['status']] ?? '' : '';

$page = [
    'title'       => ($b ? bath_number($b) : 'Запись не найдена') . ' — ' . $h['name'],
    'description' => 'Ваша запись в баню.',
    'path'        => '/bath_booking.php',
    'noindex'     => true,
    'analytics'   => false, // в адресе секретная ссылка — в Метрику её не отдаём
];
require __DIR__ . '/../src/partials/head.php';
require __DIR__ . '/../src/partials/header.php';
?>
<main id="main" class="doc">
    <div class="container doc__inner">
        <a class="doc__back" href="/bath.php"><?= icon('arrow-right') ?>К бане</a>
<?php if (!$b): ?>
        <h1 class="display">Запись не найдена</h1>
        <p class="lead">Ссылка неполная или устарела. Позвоните нам — подскажем: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a>.</p>
<?php else: ?>
<?php if (get('new') === '1'): ?>
        <div class="notice notice--ok" role="status"><?= icon('check') ?><div><strong>Заявка принята.</strong> Сохраните эту страницу в закладки<?= $b['guest_email'] ? ' — ссылка на неё есть и в письме на ' . e($b['guest_email']) : '' ?>.</div></div>
<?php endif; ?>
        <article class="bp card">
            <header class="bp__head">
                <p class="eyebrow"><?= e($s['title']) ?> · запись №<?= $b['id'] ?></p>
                <h1 class="h2"><?= e(ru_date($b['date'])) ?>, <?= e($b['time_from']) ?>–<?= e($b['time_to']) ?></h1>
                <span class="status status--<?= e($b['status']) ?>"><?= e($b['status_label']) ?></span>
            </header>
            <p class="bp__lead"><?= e($statusText) ?></p>
            <dl class="bp__facts">
                <div><dt>Когда</dt><dd><?= e(ru_date($b['date'], true)) ?>, <?= e(RU_WEEKDAYS[(int) date('w', strtotime($b['date']))]) ?></dd></div>
                <div><dt>Время</dt><dd><?= e($b['time_from']) ?>–<?= e($b['time_to']) ?> (<?= e(bath_duration($b['minutes'])) ?>)</dd></div>
                <div><dt>Гостей</dt><dd><?= (int) $b['guests_count'] ?></dd></div>
<?php if ($b['extras_list']): ?>
                <div><dt>Дополнительно</dt><dd><?= e(implode(', ', array_map(fn ($x) => $x['name'] . ' × ' . $x['qty'], $b['extras_list']))) ?></dd></div>
<?php endif; ?>
                <div><dt>Стоимость</dt><dd><?= $b['total_price'] !== null ? e(money($b['total_price'])) : 'рассчитает администратор' ?><?= $b['hotel_guest'] ? ' — со скидкой гостю гостиницы' : '' ?></dd></div>
<?php if ($b['paid'] > 0): ?>
                <div><dt>Оплачено</dt><dd><?= e(money($b['paid'])) ?><?= $b['balance'] !== null && $b['balance'] > 0 ? ', осталось ' . e(money($b['balance'])) : '' ?></dd></div>
<?php endif; ?>
            </dl>
        </article>

        <section class="bp__section">
            <h2>Как нас найти</h2>
            <p><?= e(full_address()) ?><?= filled($h['address']['entrance']) ? '. ' . e($h['address']['entrance']) : '' ?></p>
            <p class="bp__buttons">
                <a class="btn btn--outline btn--sm" href="<?= e(route_href()) ?>" target="_blank" rel="noopener"><?= icon('navigation') ?>Маршрут</a>
                <a class="btn btn--outline btn--sm" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
<?php foreach (messenger_links() as $m): ?>
                <a class="btn btn--outline btn--sm" href="<?= e($m['href']) ?>" target="_blank" rel="noopener"><?= icon($m['icon']) ?><?= e($m['label']) ?></a>
<?php endforeach; ?>
            </p>
            <p class="muted">Нужно перенести или отменить — позвоните или напишите нам, администратор всё поправит.</p>
        </section>
<?php if ($s['rules']): ?>
        <section class="bp__section">
            <h2>Правила</h2>
            <ul class="bath-rules">
<?php foreach ($s['rules'] as $r): ?>
                <li><?= e($r) ?></li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>
<?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
