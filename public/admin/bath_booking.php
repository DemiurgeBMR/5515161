<?php
/**
 * Карточка записи в баню: время, гости, услуги, сумма, статусы, оплаты
 * (удалить нельзя — как у броней номеров), письма гостю, история.
 * Для закрытого времени — что закрыто и кнопка «Открыть».
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$id = (int) (post('id') ?: get('id'));
$b = bath_get($id);
if (!$b) {
    http_response_code(404);
    admin_header('Запись не найдена', 'bath');
    echo '<div class="panel"><p>Записи в баню №' . $id . ' нет. <a href="/admin/bath.php">К расписанию</a></p></div>';
    admin_footer();
    exit;
}

function bath_mail_guest(array $b, string $kind): bool
{
    if (!$b['guest_email']) {
        return false;
    }
    $fn = ['received' => 'mail_bath_received', 'confirmed' => 'mail_bath_confirmed', 'cancelled' => 'mail_bath_cancelled'][$kind] ?? null;
    if (!$fn) {
        return false;
    }
    [$subject, $text] = $fn($b);
    $ok = send_mail($b['guest_email'], $subject, $text);
    audit('bath', $b['id'], 'mail', ($ok ? 'отправлено письмо: ' : 'НЕ отправлено письмо: ') . $subject);
    return $ok;
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    $back = '/admin/bath_booking.php?id=' . $id;
    try {
        switch ($action) {
            case 'update':
                bath_update($id, [
                    'date' => post('date'), 'time' => post('time'), 'hours' => (int) post('hours'), 'guests' => (int) post('guests'),
                    'name' => mb_substr(post('name'), 0, 80), 'phone' => mb_substr(post('phone'), 0, 25), 'email' => mb_substr(post('email'), 0, 190),
                    'contact_pref' => post('contact_pref'), 'hotel_guest' => post('hotel_guest') === '1',
                    'extras' => bath_extras_from_input(is_array($_POST['extras'] ?? null) ? $_POST['extras'] : []),
                    'price_manual' => post('price_manual') === '1', 'total_price' => post('total_price'),
                    'comment' => post('comment'), 'admin_note' => post('admin_note'),
                ]);
                flash('ok', 'Изменения сохранены.');
                break;
            case 'status':
                $to = post('to');
                $nb = bath_set_status($id, $to, post('reason'));
                $sent = post('notify') === '1' && in_array($to, ['confirmed', 'cancelled'], true) && bath_mail_guest($nb, $to);
                flash('ok', 'Статус: ' . BATH_STATUSES[$to] . '.' . ($sent ? ' Гостю отправлено письмо.' : ''));
                break;
            case 'send':
                $ok = bath_mail_guest($b, post('kind'));
                flash($ok ? 'ok' : 'error', $ok ? 'Письмо отправлено на ' . $b['guest_email'] . '.' : 'Письмо не отправилось — проверьте настройки почты.');
                break;
            case 'payment_add':
                $amount = (int) preg_replace('/\D+/', '', post('amount'));
                $received = (int) preg_replace('/\D+/', '', post('received'));
                $pid = payment_record('bath', $id, post('kind') === 'refund' ? -$amount : $amount, post('method'), post('paid_on'), post('note'), $received ?: null);
                $change = (int) q_val('SELECT change_given FROM payments WHERE id = ?', [$pid]);
                flash('ok', (post('kind') === 'refund' ? 'Возврат ' : 'Оплата ') . money($amount) . ' записана.' . ($change > 0 ? ' Сдача гостю: ' . money($change) . '.' : ''));
                $back .= '#money';
                break;
            case 'payment_void':
                payment_void((int) post('payment_id'), post('reason'));
                flash('ok', 'Запись аннулирована — она осталась в истории зачёркнутой и не входит в суммы.');
                $back .= '#money';
                break;
            case 'unblock':
                bath_unblock($id);
                flash('ok', 'Время снова открыто для записи.');
                redirect('/admin/bath.php?date=' . $b['date']);
                break;
            case 'delete':
                if (!is_owner()) {
                    throw new BookingError('Удалять записи может только владелец');
                }
                if (q_val('SELECT COUNT(*) FROM payments WHERE bath_booking_id = ?', [$id])) {
                    throw new BookingError('По записи есть оплаты — её нельзя удалить, только отменить');
                }
                q('DELETE FROM bath_bookings WHERE id = ?', [$id]);
                audit('bath', $id, 'deleted', ru_date($b['date']) . ', ' . $b['time_from'] . '–' . $b['time_to']);
                touch_data_version();
                cleaning_sync(substr($b['ends_at'], 0, 10));
                flash('ok', bath_number($b) . ' удалена.');
                redirect('/admin/bath.php?date=' . $b['date']);
                break;
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$s = bath_settings();

if ($b['is_block']) {
    admin_header('Закрытое время', 'bath');
    ?>
<div class="adm-head">
    <div>
        <h1>Время закрыто</h1>
        <p class="adm-head__sub"><a href="/admin/bath.php?date=<?= e($b['date']) ?>">← Расписание</a></p>
    </div>
</div>
<section class="panel">
    <p><strong><?= e(ru_date($b['date'], true)) ?>, <?= e($b['time_from']) ?>–<?= e($b['time_to']) ?></strong></p>
    <p><?= e($b['admin_note'] ?: 'Без причины') ?></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="unblock">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('lock') ?>Открыть это время для записи</button></form>
</section>
<?php
    admin_footer();
    exit;
}

$payments = payments_of('bath', $id);
$history = q_all("SELECT a.*, s.name AS staff_name FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.entity = 'bath' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50", [$id]);
$quote = bath_quote($b['date'], max(1, $b['hours']), $b['guests_count'], (bool) $b['hotel_guest'], $b['extras_list']);
$qtyByName = [];
foreach ($b['extras_list'] as $x) {
    $qtyByName[$x['name']] = $x['qty'];
}
$guestUrl = bath_url($b);
$waText = 'Здравствуйте! Ваша запись в баню «' . mail_hotel_name() . '»: ' . bath_when($b) . '. Детали: ' . $guestUrl;
$wa = whatsapp_href($waText, $b['guest_phone'] ?? '');

admin_header(bath_number($b), 'bath');
?>
<div class="adm-head">
    <div>
        <h1><?= e(bath_number($b)) ?> <span class="status status--<?= e($b['status']) ?>"><?= e($b['status_label']) ?></span></h1>
        <p class="adm-head__sub"><a href="/admin/bath.php?date=<?= e($b['date']) ?>">← Расписание</a> · <?= e(bath_when($b)) ?> · <?= e(BOOKING_SOURCES[$b['source']] ?? $b['source']) ?>, <?= e(ru_datetime($b['created_at'])) ?></p>
    </div>
</div>

<section class="panel">
    <h2 class="panel__title">Статус</h2>
    <div class="bk-status-actions">
<?php
$transitions = [
    'new'       => [['confirmed', 'Подтвердить', 'btn--primary'], ['cancelled', 'Отменить…', 'btn--danger']],
    'confirmed' => [['done', 'Состоялась', 'btn--primary'], ['no_show', 'Не пришли', 'btn--outline'], ['cancelled', 'Отменить…', 'btn--danger']],
    'done'      => [['confirmed', 'Вернуть «подтверждена»', 'btn--outline']],
    'cancelled' => [['new', 'Восстановить как заявку', 'btn--outline']],
    'no_show'   => [['new', 'Восстановить как заявку', 'btn--outline']],
][$b['status']] ?? [];
foreach ($transitions as [$to, $label, $btn]):
    if ($to === 'cancelled'): ?>
        <button class="btn <?= $btn ?> btn--sm" type="button" data-open="#cancel-form"><?= e($label) ?></button>
<?php else: ?>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="to" value="<?= e($to) ?>">
<?php if ($to === 'confirmed' && $b['status'] === 'new' && $b['guest_email']): ?>
            <input type="hidden" name="notify" value="1">
<?php endif; ?>
            <button class="btn <?= $btn ?> btn--sm" type="submit"<?= $to === 'done' && $b['balance'] > 0 ? ' data-confirm="Гость не доплатил ' . e(money($b['balance'])) . '. Всё равно отметить «состоялась»?"' : '' ?>><?= e($label) ?><?= $to === 'confirmed' && $b['status'] === 'new' && $b['guest_email'] ? ' и написать гостю' : '' ?></button>
        </form>
<?php endif; endforeach; ?>
    </div>
    <form method="post" class="cancel-form" id="cancel-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="to" value="cancelled">
        <div class="field"><label for="c-reason">Причина отмены — её увидит гость</label><input id="c-reason" name="reason" maxlength="255" placeholder="Например: гость передумал"></div>
<?php if ($b['guest_email']): ?>
        <label class="inline-check"><input type="checkbox" name="notify" value="1" checked> Сообщить гостю письмом</label>
<?php endif; ?>
        <div class="form-actions"><button class="btn btn--danger btn--sm" type="submit">Отменить запись</button></div>
    </form>
</section>

<div class="main-side">
    <section class="panel">
        <h2 class="panel__title">Запись</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="update">
            <div class="field"><label for="e-date">Дата</label><input id="e-date" type="date" name="date" required value="<?= e($b['date']) ?>"></div>
            <div class="field"><label for="e-time">Начало</label><select id="e-time" name="time">
<?php for ($m = 0; $m < 24 * 60; $m += 30): $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
                <option<?= $t === $b['time_from'] ? ' selected' : '' ?>><?= $t ?></option>
<?php endfor; ?>
            </select></div>
            <div class="field"><label for="e-hours">Часов</label><input id="e-hours" type="number" name="hours" min="1" max="12" value="<?= max(1, $b['hours']) ?>"></div>
            <div class="field"><label for="e-guests">Гостей</label><input id="e-guests" type="number" name="guests" min="1" max="50" value="<?= $b['guests_count'] ?>"></div>
            <div class="field span-all"><label for="e-name">Имя</label><input id="e-name" name="name" required maxlength="80" value="<?= e($b['guest_name'] ?? '') ?>"></div>
            <div class="field"><label for="e-phone">Телефон</label><input id="e-phone" type="tel" name="phone" maxlength="25" value="<?= e($b['guest_phone'] ?? '') ?>"></div>
            <div class="field"><label for="e-email">Почта</label><input id="e-email" type="email" name="email" maxlength="190" value="<?= e($b['guest_email'] ?? '') ?>"></div>
            <div class="field"><label for="e-contact">Связаться</label><select id="e-contact" name="contact_pref">
<?php foreach (CONTACT_PREFS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $b['contact_pref'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
            </select></div>
<?php foreach ($s['extras'] as $i => $x): ?>
            <div class="field"><label for="e-x<?= $i ?>"><?= e($x['name']) ?>, шт.</label><input id="e-x<?= $i ?>" type="number" name="extras[<?= $i ?>]" min="0" max="20" value="<?= (int) ($qtyByName[$x['name']] ?? 0) ?>"></div>
<?php endforeach; ?>
            <div class="field span-all">
                <label class="inline-check"><input type="checkbox" name="hotel_guest" value="1"<?= $b['hotel_guest'] ? ' checked' : '' ?>> Живёт в гостинице — скидка <?= $s['hotel_discount'] ?>%</label>
                <label class="inline-check"><input type="checkbox" name="price_manual" value="1"<?= $b['price_manual'] ? ' checked' : '' ?>> Своя сумма</label>
            </div>
            <div class="field"><label for="e-total">Сумма, ₽</label><input id="e-total" name="total_price" inputmode="numeric" value="<?= $b['total_price'] ?? '' ?>"><p class="field-hint">Меняется, только если отмечено «Своя сумма».</p></div>
            <div class="field span-all"><label for="e-comment">Комментарий гостя</label><textarea id="e-comment" name="comment" rows="2" maxlength="1000"><?= e($b['guest_comment'] ?? '') ?></textarea></div>
            <div class="field span-all"><label for="e-note">Заметка для сотрудников</label><textarea id="e-note" name="admin_note" rows="2" maxlength="2000"><?= e($b['admin_note'] ?? '') ?></textarea></div>
            <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
        </form>
<?php if (array_diff(array_column($b['extras_list'], 'name'), array_column($s['extras'], 'name'))): ?>
        <p class="hint">В записи есть услуги, которых больше нет в списке: <?= e(implode(', ', array_diff(array_column($b['extras_list'], 'name'), array_column($s['extras'], 'name')))) ?>. При сохранении они уберутся.</p>
<?php endif; ?>
    </section>

    <div class="blist">
        <section class="panel" id="money">
            <h2 class="panel__title">Оплата</h2>
            <div>
                <div class="money-line"><span>Сумма<?= $b['price_manual'] ? ' (своя)' : '' ?></span><b><?= $b['total_price'] !== null ? e(money($b['total_price'])) : '—' ?></b></div>
<?php if (!$b['price_manual']): ?>
                <div class="money-line money-line--small"><span><?= max(1, $b['hours']) ?> ч × <?= e(money($quote['hour_price'])) ?><?= $quote['extra_guests'] ? ', +' . $quote['extra_guests'] . ' гост.' : '' ?><?= $quote['discount'] ? ', скидка ' . $quote['discount_percent'] . '%' : '' ?><?= $quote['extras_sum'] ? ', услуги ' . e(money($quote['extras_sum'])) : '' ?></span><b></b></div>
<?php endif; ?>
                <div class="money-line"><span>Оплачено</span><b><?= e(money($b['paid'])) ?></b></div>
<?php if ($b['balance'] !== null): ?>
                <div class="money-line <?= $b['balance'] > 0 ? 'money-line--due' : 'money-line--ok' ?>"><span><?= $b['balance'] > 0 ? 'Осталось оплатить' : ($b['balance'] < 0 ? 'Переплата' : 'Оплачено полностью') ?></span><b><?= e(money(abs($b['balance']))) ?></b></div>
<?php endif; ?>
            </div>
            <?= admin_payments_html($payments, $id, $b['balance'] > 0 ? $b['balance'] : null, 'Например: веники оплатили отдельно') ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Ссылка на запись для гостя</h2>
            <div class="copy-row">
                <input id="guest-link" value="<?= e($guestUrl) ?>" readonly>
                <button class="btn btn--outline btn--sm" type="button" data-copy="#guest-link"><?= icon('copy') ?>Копировать</button>
            </div>
            <div class="form-actions">
<?php if ($wa): ?>
                <a class="btn btn--outline btn--sm" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>Отправить в WhatsApp</a>
<?php endif; ?>
<?php if ($b['guest_phone']): ?>
                <a class="btn btn--outline btn--sm" href="<?= e(tel_href($b['guest_phone'])) ?>"><?= icon('phone') ?>Позвонить</a>
<?php endif; ?>
<?php if ($b['guest_email']): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="kind" value="<?= $b['status'] === 'cancelled' ? 'cancelled' : ($b['status'] === 'new' ? 'received' : 'confirmed') ?>">
                    <button class="btn btn--outline btn--sm" type="submit"><?= icon('mail') ?>Отправить письмо ещё раз</button>
                </form>
<?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <h2 class="panel__title">История</h2>
            <ul class="history">
<?php foreach ($history as $ev): ?>
                <li><time><?= e(ru_datetime($ev['created_at'])) ?> · <?= e($ev['staff_name'] ?? ($ev['actor'] === 'guest' ? 'гость с сайта' : 'система')) ?></time><span><?= e(audit_action_label('bath', $ev['action'])) ?><?= $ev['details'] ? ': ' . e($ev['details']) : '' ?></span></li>
<?php endforeach; ?>
            </ul>
        </section>

<?php if (is_owner() && !$payments): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn--danger btn--sm" type="submit" data-confirm="Удалить запись совсем? Обычно достаточно отменить."><?= icon('trash-2') ?>Удалить запись</button>
        </form>
<?php endif; ?>
    </div>
</div>
<?php admin_footer(); ?>
