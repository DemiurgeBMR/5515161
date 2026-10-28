<?php
/**
 * Страница брони для гостя — по секретной ссылке из письма или после
 * отправки заявки. Статус, даты, сумма, оплата, как добраться и форма
 * «написать администратору» (продлить, перенести, отменить).
 */

require __DIR__ . '/../src/bootstrap.php';

header('Referrer-Policy: no-referrer'); // ссылка секретная — не отдаём её сайтам, на которые уходят со страницы
header('X-Robots-Tag: noindex, nofollow');

if (!db_ready()) {
    require __DIR__ . '/../src/partials/maintenance.php';
    exit;
}

$token = get('t', post('t'));
$b = booking_by_token($token);
if (!$b) {
    http_response_code(404);
}
$h = hotel();
$errors = [];

$canWrite = $b && in_array($b['status'], ['new', 'confirmed', 'checked_in'], true);
if ($b && is_post() && post('action') === 'message') {
    if (!$canWrite) {
        $errors['form'] = 'По этой брони сообщения больше не принимаются — позвоните нам.';
    } elseif (rate_limited('message', $b['token'], 5, 3600)) {
        $errors['form'] = 'Слишком много сообщений подряд — позвоните нам.';
    } else {
        try {
            $kind = post('kind');
            $newOut = post('new_check_out') ?: null;
            message_add($b, $kind, post('message'), $newOut);
            rate_hit('message', $b['token']);
            notify_admins(mail_admin_guest_message($b, $kind, post('message'), $kind === 'extend' ? $newOut : null), (string) $b['guest_email']);
            redirect('/booking.php?t=' . $b['token'] . '&sent=1#message');
        } catch (BookingError $e) {
            $errors[$e->field ?: 'form'] = $e->getMessage();
        }
    }
}

$statusText = $b ? [
    'new'         => 'Номер уже держим за вами. Администратор свяжется с вами в течение ' . $h['response_time'] . ' (' . $h['admin_hours'] . ') и подтвердит бронь.',
    'confirmed'   => 'Бронь подтверждена — ждём вас! Заселение с ' . $h['check_in'] . ', при себе нужен паспорт.',
    'checked_in'  => 'Хорошего отдыха! Если что-то нужно — позвоните администратору или напишите ниже.',
    'checked_out' => 'Спасибо, что остановились у нас. Будем рады видеть вас снова.',
    'cancelled'   => 'Бронь отменена.' . ($b['cancel_reason'] !== '' ? ' Причина: ' . $b['cancel_reason'] . '.' : '') . ' Если это ошибка — позвоните нам.',
    'no_show'     => 'Бронь закрыта: мы не дождались вас. Если планы изменились — позвоните, подберём новые даты.',
][$b['status']] ?? '' : '';
$messages = $b ? booking_messages($b['id']) : [];

$page = [
    'title'       => ($b ? 'Бронь ' . booking_number($b) : 'Бронь не найдена') . ' — ' . $h['name'],
    'description' => 'Ваша бронь в гостинице ' . $h['name'] . '.',
    'path'        => '/booking.php',
    'noindex'     => true,
];
require __DIR__ . '/../src/partials/head.php';
require __DIR__ . '/../src/partials/header.php';
?>
<main id="main" class="doc">
    <div class="container doc__inner">
        <a class="doc__back" href="/"><?= icon('arrow-right') ?>На главную</a>
<?php if (!$b): ?>
        <h1 class="display">Бронь не найдена</h1>
        <p class="lead">Ссылка неполная или устарела. Найти свои брони можно в разделе <a href="/account.php">«Мои брони»</a> — по почте, указанной в заявке. Или позвоните нам: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a>.</p>
<?php else: ?>
<?php if (get('new') === '1'): ?>
        <div class="notice notice--ok" role="status"><?= icon('check') ?><div><strong>Заявка принята.</strong> Сохраните эту страницу в закладки<?= $b['guest_email'] ? ' — ссылка на неё есть и в письме на ' . e($b['guest_email']) : '' ?>.</div></div>
<?php endif; ?>
<?php if (get('sent') === '1'): ?>
        <div class="notice notice--ok" role="status" id="message"><?= icon('check') ?><div><strong>Сообщение отправлено.</strong> Администратор ответит по телефону или в мессенджере.</div></div>
<?php endif; ?>

        <article class="bp card">
            <header class="bp__head">
                <p class="eyebrow">Бронь <?= e(booking_number($b)) ?></p>
                <h1 class="h2"><?= e(ru_date_range($b['check_in'], $b['check_out'])) ?></h1>
                <span class="status status--<?= e($b['status']) ?>"><?= e($b['status_label']) ?></span>
            </header>
            <p class="bp__lead"><?= e($statusText) ?></p>
            <dl class="bp__facts">
                <div><dt>Заезд</dt><dd><?= e(ru_date($b['check_in'], true)) ?>, с <?= e($h['check_in']) ?></dd></div>
                <div><dt>Выезд</dt><dd><?= e(ru_date($b['check_out'], true)) ?>, до <?= e($h['check_out']) ?></dd></div>
                <div><dt>Номер</dt><dd><?= e($b['type_name']) ?>, <?= e(nights_label($b['nights'])) ?></dd></div>
                <div><dt>Гостей</dt><dd><?= (int) $b['guests_count'] ?></dd></div>
                <div><dt>Стоимость</dt><dd><?= $b['total_price'] !== null ? e(money($b['total_price'])) : 'рассчитает администратор' ?></dd></div>
<?php if ($b['prepayment'] > 0 && !in_array($b['status'], ['cancelled', 'no_show'], true)): ?>
                <div><dt>Предоплата</dt><dd><?= e(money($b['prepayment'])) ?> — <?= $b['paid'] >= $b['prepayment'] ? '<span class="ok">получена</span>' : 'ждём, администратор подскажет, как оплатить' ?></dd></div>
<?php endif; ?>
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
        </section>

<?php if ($canWrite): ?>
        <section class="bp__section" id="write">
            <h2>Написать администратору</h2>
            <p class="muted">Сроки лечения сдвинулись или нужно продлить проживание — напишите здесь, администратор свяжется с вами.</p>
<?php if (isset($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
<?php endif; ?>
            <form method="post" action="/booking.php" class="bp__form" data-message-form>
                <input type="hidden" name="t" value="<?= e($b['token']) ?>">
                <input type="hidden" name="action" value="message">
                <div class="chips" role="radiogroup" aria-label="Что нужно сделать">
<?php foreach (MESSAGE_KINDS as $k => $label): ?>
                    <label class="chip"><input type="radio" name="kind" value="<?= e($k) ?>"<?= (post('kind', 'extend') === $k) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
                </div>
                <div class="field<?= isset($errors['new_check_out']) ? ' has-error' : '' ?>" data-extend-field>
                    <label for="m-out">Новая дата выезда</label>
                    <input id="m-out" type="date" name="new_check_out" min="<?= e(parse_date($b['check_out'])->modify('+1 day')->format('Y-m-d')) ?>" value="<?= e(post('new_check_out')) ?>">
<?php if (isset($errors['new_check_out'])): ?>
                    <p class="field__error"><?= e($errors['new_check_out']) ?></p>
<?php endif; ?>
                </div>
                <div class="field<?= isset($errors['message']) ? ' has-error' : '' ?>">
                    <label for="m-text">Сообщение</label>
                    <textarea id="m-text" name="message" rows="3" maxlength="1000" placeholder="Например: госпитализацию перенесли на неделю"><?= e(post('message')) ?></textarea>
<?php if (isset($errors['message'])): ?>
                    <p class="field__error"><?= e($errors['message']) ?></p>
<?php endif; ?>
                </div>
                <button class="btn btn--primary" type="submit">Отправить</button>
            </form>
        </section>
<?php endif; ?>

<?php if ($messages): ?>
        <section class="bp__section">
            <h2>Ваши сообщения</h2>
            <ul class="bp__messages">
<?php foreach ($messages as $m): ?>
                <li>
                    <strong><?= e(MESSAGE_KINDS[$m['kind']] ?? $m['kind']) ?><?= $m['new_check_out'] ? ' до ' . e(ru_date($m['new_check_out'])) : '' ?></strong>
                    <span class="muted"><?= e(ru_datetime($m['created_at'])) ?> · <?= $m['handled_at'] ? 'администратор ответил' : 'ждёт ответа' ?></span>
<?php if ($m['message']): ?>
                    <p><?= nl2br(e($m['message'])) ?></p>
<?php endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>

        <p class="muted bp__foot"><a href="/rules.php">Правила проживания</a> · <a href="/account.php">Все мои брони</a></p>
<?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
