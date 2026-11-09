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

if ($b && is_post() && post('action') === 'time_request') {
    if (!$canWrite || rate_limited('time_request', $b['token'], 3, 3600)) {
        $errors['time_form'] = 'Не удалось отправить запрос. Попробуйте позже или позвоните администратору.';
    } else {
        try {
            booking_time_request_add($b, post('time_kind'), post('requested_time'), post('time_message'));
            rate_hit('time_request', $b['token']);
            redirect('/booking.php?t=' . $b['token'] . '&time_sent=1#time-request');
        } catch (BookingError $e) {
            $errors['time_form'] = $e->getMessage();
        }
    }
}
if ($b && is_post() && post('action') === 'time_response') {
    try {
        booking_time_request_respond((int) post('request_id'), $b['token'], post('answer') === 'accept');
        redirect('/booking.php?t=' . $b['token'] . '&time_answered=1#time-request');
    } catch (BookingError $e) {
        $errors['time_form'] = $e->getMessage();
    }
}
if ($b && is_post() && post('action') === 'business_request') {
    if (!$canWrite) {
        $errors['business_form'] = 'Изменения по этой брони больше не принимаются.';
    } else {
        try {
            booking_business_details_save((int) $b['id'], [
                'is_business_trip' => post('is_business_trip'),
                'employee_name' => post('employee_name'),
                'organization_name' => post('organization_name'),
                'organization_inn' => post('organization_inn'),
                'payer' => post('payer'),
                'requested_documents' => (isset($_POST['requested_documents']) && is_array($_POST['requested_documents'])) ? $_POST['requested_documents'] : [],
                'document_status' => 'requested',
            ]);
            redirect('/booking.php?t=' . $b['token'] . '&business_sent=1#business-trip');
        } catch (BookingError $e) {
            $errors['business_form'] = $e->getMessage();
        }
    }
}
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
            tg_notify_guest_message($b, $kind, post('message'), $kind === 'extend' ? $newOut : null);
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
    'analytics'   => false, // в адресе секретная ссылка — в Метрику её не отдаём
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
<?php if (get('time_sent') === '1' || get('time_answered') === '1'): ?>
        <div class="notice notice--ok" role="status"><div><strong><?= get('time_sent') === '1' ? 'Запрос отправлен.' : 'Ваш ответ сохранён.' ?></strong> Администратор свяжется с вами для подтверждения условий.</div></div>
<?php endif; ?>
<?php if (get('business_sent') === '1'): ?>
        <div class="notice notice--ok" role="status"><div><strong>Пожелания по документам сохранены.</strong> Администратор уточнит необходимые сведения и подготовит доступные документы.</div></div>
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
                <div><dt>Стоимость проживания</dt><dd><?= $b['total_price'] !== null ? e(money($b['total_price'])) : 'рассчитает администратор' ?></dd></div><?php if ((int) $b['accepted_time_fees'] > 0): ?><div><dt>Согласованные услуги по времени</dt><dd><?= e(money((int) $b['accepted_time_fees'])) ?></dd></div><?php endif; ?>
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
            <p class="muted">Планы изменились или нужно продлить проживание — напишите здесь, администратор свяжется с вами.</p>
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
                    <textarea id="m-text" name="message" rows="3" maxlength="1000" placeholder="Например: хочу продлить ещё на две ночи"><?= e(post('message')) ?></textarea>
<?php if (isset($errors['message'])): ?>
                    <p class="field__error"><?= e($errors['message']) ?></p>
<?php endif; ?>
                </div>
                <button class="btn btn--primary" type="submit">Отправить</button>
            </form>
        </section>
<?php endif; ?>

<?php
$timeRequests = booking_time_requests((int) $b['id']);
$businessDetails = booking_business_details((int) $b['id']) ?: [];
?>
        <section class="bp__section" id="time-request">
            <h2>Ранний заезд или досрочный выезд</h2>
            <p class="muted">Это запрос администратору, а не автоматическое изменение брони. Возможность, точное время и стоимость подтверждаются отдельно. Платные условия действуют только после вашего согласия.</p>
<?php if (isset($errors['time_form'])): ?><div class="form-error" role="alert"><?= e($errors['time_form']) ?></div><?php endif; ?>
<?php foreach ($timeRequests as $tr): ?>
            <div class="msg">
                <strong><?= e(BOOKING_TIME_REQUEST_KINDS[$tr['kind']] ?? $tr['kind']) ?> · <?= e(substr($tr['requested_time'], 0, 5)) ?></strong>
                <p class="muted"><?= e(BOOKING_TIME_REQUEST_STATUSES[$tr['status']] ?? $tr['status']) ?></p>
<?php if ($tr['status'] === 'offered'): ?>
                <p>Администратор предлагает <?= e(substr($tr['offered_time'], 0, 5)) ?><?php if ((int) $tr['fee_amount'] > 0): ?> за <?= e(money((int) $tr['fee_amount'])) ?><?php else: ?> без дополнительной оплаты<?php endif; ?>.</p>
<?php if ($tr['admin_note']): ?><p><?= e($tr['admin_note']) ?></p><?php endif; ?>
                <form method="post" class="form-actions">
                    <input type="hidden" name="t" value="<?= e($b['token']) ?>"><input type="hidden" name="action" value="time_response"><input type="hidden" name="request_id" value="<?= (int) $tr['id'] ?>">
                    <button class="btn btn--primary btn--sm" name="answer" value="accept" type="submit">Согласен</button>
                    <button class="btn btn--outline btn--sm" name="answer" value="reject" type="submit">Не согласен</button>
                </form>
<?php endif; ?>
            </div>
<?php endforeach; ?>
<?php if ($canWrite && !array_filter($timeRequests, fn($r) => in_array($r['status'], ['pending','offered'], true))): ?>
            <form method="post" class="bp__form">
                <input type="hidden" name="t" value="<?= e($b['token']) ?>"><input type="hidden" name="action" value="time_request">
                <div class="field"><label for="time-kind">Что требуется</label><select id="time-kind" name="time_kind"><option value="early_checkin">Ранний заезд (до <?= e($h['check_in']) ?>)</option><option value="early_checkout">Досрочный выезд (раньше <?= e($h['check_out']) ?>)</option></select></div>
                <div class="field"><label for="requested-time">Желаемое время</label><input id="requested-time" type="time" name="requested_time" required></div>
                <div class="field"><label for="time-message">Комментарий (необязательно)</label><textarea id="time-message" name="time_message" rows="2" maxlength="1000"></textarea></div>
                <button class="btn btn--primary" type="submit">Отправить запрос</button>
            </form>
<?php endif; ?>
        </section>

        <section class="bp__section" id="business-trip">
            <h2>Документы для командировки</h2>
            <p class="muted">Если проживание оплачивает организация, сообщите это заранее. Администратор уточнит реквизиты и подготовит документы, которые нужны вашей бухгалтерии. Кассовый чек оформляется при расчёте и не заменяется счётом или актом.</p>
<?php if (isset($errors['business_form'])): ?><div class="form-error" role="alert"><?= e($errors['business_form']) ?></div><?php endif; ?>
<?php if ($canWrite): ?>
            <form method="post" class="bp__form">
                <input type="hidden" name="t" value="<?= e($b['token']) ?>"><input type="hidden" name="action" value="business_request">
                <label class="inline-check"><input type="checkbox" name="is_business_trip" value="1" checked> Проживание связано с командировкой</label>
                <div class="field"><label for="employee-name">ФИО проживающего сотрудника</label><input id="employee-name" name="employee_name" maxlength="190" value="<?= e($businessDetails['employee_name'] ?? $b['guest_name']) ?>"></div>
                <div class="field"><label for="organization-name">Организация</label><input id="organization-name" name="organization_name" maxlength="190" value="<?= e($businessDetails['organization_name'] ?? '') ?>"></div>
                <div class="field"><label for="organization-inn">ИНН организации (если есть)</label><input id="organization-inn" name="organization_inn" inputmode="numeric" maxlength="12" value="<?= e($businessDetails['organization_inn'] ?? '') ?>"></div>
                <div class="field"><label for="payer">Кто оплачивает</label><select id="payer" name="payer"><option value="guest"<?= ($businessDetails['payer'] ?? 'guest') === 'guest' ? ' selected' : '' ?>>Гость</option><option value="organization"<?= ($businessDetails['payer'] ?? '') === 'organization' ? ' selected' : '' ?>>Организация</option></select></div>
                <fieldset class="field"><legend>Какие документы нужны (можно отметить несколько)</legend>
                    <?php foreach (['contract'=>'Договор / подтверждение бронирования','invoice'=>'Счёт для организации','act'=>'Акт оказанных услуг','residence_certificate'=>'Справка о проживании','receipt'=>'Кассовый чек при оплате'] as $docKey=>$docLabel): ?>
                    <label class="inline-check"><input type="checkbox" name="requested_documents[]" value="<?= e($docKey) ?>"<?= in_array($docKey, explode(',', (string) ($businessDetails['requested_documents'] ?? '')), true) ? ' checked' : '' ?>> <?= e($docLabel) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="btn btn--primary" type="submit">Сохранить запрос документов</button>
            </form>
<?php endif; ?>
        </section>

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
