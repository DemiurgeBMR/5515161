<?php
/**
 * Карточка брони: создание, правка, статусы, оплаты, письма гостю,
 * сообщения гостя и история изменений.
 *
 *   /admin/booking.php?new=1[&room=ID&check_in=ГГГГ-ММ-ДД] — новая бронь
 *   /admin/booking.php?id=N — карточка
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$id = (int) (post('id') ?: get('id'));
$isNew = !$id;
$b = $id ? booking_get($id) : null;
if ($id && !$b) {
    http_response_code(404);
    admin_header('Бронь не найдена', 'bookings');
    echo '<div class="panel"><p>Брони №' . $id . ' нет. <a href="/admin/bookings.php">К списку броней</a></p></div>';
    admin_footer();
    exit;
}

/** Поля формы брони из POST — общие для создания и правки. */
function booking_form_input(): array
{
    return [
        'name' => mb_substr(post('name'), 0, 100), 'phone' => mb_substr(post('phone'), 0, 25), 'email' => mb_substr(post('email'), 0, 190),
        'check_in' => post('check_in'), 'check_out' => post('check_out'),
        'room_type_id' => (int) post('room_type_id'), 'room_id' => (int) post('room_id'),
        'guests' => (int) post('guests', '1'), 'source' => post('source'), 'contact_pref' => post('contact_pref'),
        'price_manual' => post('price_manual') === '1', 'total_price' => post('total_price'),
        'prepayment' => post('prepayment'), 'comment' => post('comment'), 'admin_note' => post('admin_note'),
    ];
}

function mail_guest(array $b, string $kind): bool
{
    if (!$b['guest_email']) {
        return false;
    }
    $mail = ['received' => 'mail_booking_received', 'confirmed' => 'mail_booking_confirmed', 'cancelled' => 'mail_booking_cancelled'][$kind] ?? null;
    if (!$mail) {
        return false;
    }
    [$subject, $text] = $mail($b);
    $ok = send_mail($b['guest_email'], $subject, $text);
    audit('booking', $b['id'], 'mail', ($ok ? 'отправлено письмо: ' : 'НЕ отправлено письмо: ') . $subject);
    return $ok;
}

$errors = [];
$input = null;
if (is_post()) {
    csrf_check();
    $action = post('action');
    $back = '/admin/booking.php?id=' . $id;
    try {
        switch ($action) {
            case 'create':
                $d = booking_form_input();
                $d['status'] = post('status', 'confirmed');
                $d['total_price'] = $d['price_manual'] ? $d['total_price'] : null;
                $d['prepayment'] = $d['prepayment'] !== '' ? $d['prepayment'] : null;
                $nb = booking_create($d, true);
                $sent = post('notify') === '1' && mail_guest($nb, $nb['status'] === 'new' ? 'received' : 'confirmed');
                flash('ok', 'Бронь ' . booking_number($nb) . ' заведена.' . ($sent ? ' Гостю отправлено письмо.' : ''));
                redirect('/admin/booking.php?id=' . $nb['id']);
                break;
            case 'update':
                booking_update($id, booking_form_input());
                flash('ok', 'Изменения сохранены.');
                redirect($back);
                break;
            case 'status':
                $to = post('to');
                $nb = booking_set_status($id, $to, post('reason'));
                $sent = false;
                if (post('notify') === '1' && in_array($to, ['confirmed', 'cancelled'], true)) {
                    $sent = mail_guest($nb, $to);
                }
                flash('ok', 'Статус: ' . BOOKING_STATUSES[$to] . '.' . ($sent ? ' Гостю отправлено письмо.' : ''));
                redirect($back);
                break;
            case 'payment_add':
                $amount = (int) preg_replace('/\D+/', '', post('amount'));
                payment_add($id, post('kind') === 'refund' ? -$amount : $amount, post('method'), post('paid_on'), post('note'));
                flash('ok', (post('kind') === 'refund' ? 'Возврат ' : 'Оплата ') . money($amount) . ' записана.');
                redirect($back . '#money');
                break;
            case 'payment_delete':
                payment_delete((int) post('payment_id'));
                flash('ok', 'Платёж удалён.');
                redirect($back . '#money');
                break;
            case 'message_done':
                message_mark_handled((int) post('message_id'));
                flash('ok', 'Сообщение отмечено как обработанное.');
                redirect($back . '#messages');
                break;
            case 'send':
                $ok = mail_guest($b, post('kind'));
                flash($ok ? 'ok' : 'error', $ok ? 'Письмо отправлено на ' . $b['guest_email'] . '.' : 'Письмо не отправилось — проверьте настройки почты.');
                redirect($back);
                break;
            case 'delete':
                if (!is_owner()) {
                    throw new BookingError('Удалять брони может только владелец');
                }
                q('DELETE FROM bookings WHERE id = ?', [$id]);
                audit('booking', $id, 'deleted', ru_date_range($b['check_in'], $b['check_out']) . ', ' . $b['type_name'] . ($b['guest_id'] ? ', гость №' . $b['guest_id'] : ''));
                touch_data_version();
                flash('ok', 'Бронь ' . booking_number($b) . ' удалена.');
                redirect('/admin/bookings.php');
                break;
        }
    } catch (BookingError $e) {
        if (in_array($action, ['create', 'update'], true)) {
            $errors[$e->field !== '' ? $e->field : 'form'] = $e->getMessage();
            $input = booking_form_input() + ['status' => post('status'), 'notify' => post('notify')];
        } else {
            flash('error', $e->getMessage());
            redirect($back);
        }
    }
}

// Значения формы
$types = room_types(false);
if ($input) {
    $f = $input;
} elseif ($b) {
    $f = [
        'name' => $b['guest_name'] ?? '', 'phone' => $b['guest_phone'] ?? '', 'email' => $b['guest_email'] ?? '',
        'check_in' => $b['check_in'], 'check_out' => $b['check_out'], 'room_type_id' => $b['room_type_id'], 'room_id' => $b['room_id'],
        'guests' => $b['guests_count'], 'source' => $b['source'], 'contact_pref' => $b['contact_pref'],
        'price_manual' => (bool) $b['price_manual'], 'total_price' => $b['total_price'], 'prepayment' => $b['prepayment'],
        'comment' => $b['guest_comment'], 'admin_note' => $b['admin_note'],
    ];
} else {
    $room = get('room') ? q_one('SELECT * FROM rooms WHERE id = ?', [(int) get('room')]) : null;
    $ci = date_value(get('check_in')) ?: today()->format('Y-m-d');
    $known = get('guest') ? guest_get((int) get('guest')) : null; // «Новая бронь для гостя» из карточки гостя
    $f = [
        'name' => $known['name'] ?? '', 'phone' => $known['phone'] ?? '', 'email' => $known['email'] ?? '', 'check_in' => $ci, 'check_out' => parse_date($ci)->modify('+1 day')->format('Y-m-d'),
        'room_type_id' => $room ? (int) $room['room_type_id'] : (int) array_key_first($types), 'room_id' => $room ? (int) $room['id'] : 0,
        'guests' => 1, 'source' => 'phone', 'contact_pref' => 'call', 'price_manual' => false, 'total_price' => '', 'prepayment' => '',
        'comment' => '', 'admin_note' => '', 'status' => 'confirmed', 'notify' => '1',
    ];
}
$fv = fn (string $k) => e((string) ($f[$k] ?? ''));
$err = fn (string $k) => isset($errors[$k]) ? '<p class="field__error">' . e($errors[$k]) . '</p>' : '';
$cls = fn (string $k) => 'field' . (isset($errors[$k]) ? ' has-error' : '');

// Комнаты: какие свободны на даты из формы
$rooms = rooms_list(false);
$freeIds = [];
if (parse_date((string) $f['check_in']) && parse_date((string) $f['check_out']) && $f['check_out'] > $f['check_in']) {
    $freeIds = array_keys(free_rooms($f['check_in'], $f['check_out'], $b['id'] ?? null));
}
// Тот же телефон у других карточек — скорее всего, гость уже бывал у нас.
$sameGuests = [];
if ($b && $b['guest_id']) {
    $digits = (string) q_val('SELECT phone_digits FROM guests WHERE id = ?', [$b['guest_id']]);
    if ($digits !== '') {
        $sameGuests = q_all('SELECT g.id, g.name, COUNT(b.id) AS n FROM guests g LEFT JOIN bookings b ON b.guest_id = g.id
                             WHERE g.phone_digits = ? AND g.id <> ? AND g.anonymized_at IS NULL GROUP BY g.id ORDER BY g.id DESC LIMIT 3', [$digits, $b['guest_id']]);
    }
}
$quoteNow = null;
if ($b && isset($types[$b['room_type_id']])) {
    $quoteNow = quote($types[$b['room_type_id']], $b['check_in'], $b['check_out']);
}

admin_header($b ? 'Бронь ' . booking_number($b) : 'Новая бронь', 'bookings');
?>
<?php if ($b): ?>
<div class="adm-head">
    <div>
        <div class="bk-head">
            <h1><?= e($b['guest_name'] ?? 'Гость') ?></h1>
            <?= status_badge($b['status']) ?>
        </div>
        <p class="adm-head__sub">Бронь <?= e(booking_number($b)) ?> · <?= e(ru_date_range($b['check_in'], $b['check_out'])) ?>, <?= e(nights_label($b['nights'])) ?> · <?= e($b['room_name'] ?? '—') ?> (<?= e($b['type_name']) ?>) · <?= e(BOOKING_SOURCES[$b['source']] ?? $b['source']) ?>, <?= e(ru_datetime($b['created_at'])) ?></p>
        <p class="adm-head__sub"><?= guest_contacts_html($b['guest_phone'] ?? '', $b['guest_email'] ?? '') ?> · связаться: <?= e(mb_strtolower(CONTACT_PREFS[$b['contact_pref']] ?? $b['contact_pref'])) ?><?php if ($b['guest_id']): ?> · <a href="/admin/guest.php?id=<?= (int) $b['guest_id'] ?>">карточка гостя</a><?php endif; ?></p>
<?php if ($sameGuests): ?>
        <p class="adm-head__sub">Этот телефон есть и в других карточках:
<?php foreach ($sameGuests as $i => $sg): ?>
            <?= $i ? ', ' : '' ?><a href="/admin/guest.php?id=<?= (int) $sg['id'] ?>"><?= e($sg['name']) ?></a> (<?= (int) $sg['n'] ?> <?= plural((int) $sg['n'], 'бронь', 'брони', 'броней') ?>)
<?php endforeach; ?>
        </p>
<?php endif; ?>
    </div>
</div>

<section class="panel">
    <h2 class="panel__title">Статус</h2>
    <div class="bk-status-actions">
<?php
$transitions = [
    'new'         => [['confirmed', 'Подтвердить', 'btn--primary'], ['checked_in', 'Заселить', 'btn--outline'], ['cancelled', 'Отменить…', 'btn--danger']],
    'confirmed'   => [['checked_in', 'Заселить', 'btn--primary'], ['no_show', 'Не приехал', 'btn--outline'], ['cancelled', 'Отменить…', 'btn--danger']],
    'checked_in'  => [['checked_out', 'Выселить', 'btn--primary']],
    'checked_out' => [['checked_in', 'Вернуть «проживает»', 'btn--outline']],
    'cancelled'   => [['new', 'Восстановить как заявку', 'btn--outline']],
    'no_show'     => [['new', 'Восстановить как заявку', 'btn--outline']],
][$b['status']] ?? [];
foreach ($transitions as [$to, $label, $btn]):
    if ($to === 'cancelled'): ?>
        <button class="btn <?= $btn ?> btn--sm" type="button" data-open="#cancel-form"><?= e($label) ?></button>
<?php else: ?>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="to" value="<?= e($to) ?>">
<?php if ($to === 'confirmed' && $b['guest_email']): ?>
            <input type="hidden" name="notify" value="1">
<?php endif; ?>
            <button class="btn <?= $btn ?> btn--sm" type="submit"<?= $to === 'checked_out' && $b['balance'] > 0 ? ' data-confirm="Гость не доплатил ' . e(money($b['balance'])) . '. Всё равно выселить?"' : '' ?>><?= e($label) ?><?= $to === 'confirmed' && $b['guest_email'] ? ' и написать гостю' : '' ?></button>
        </form>
<?php endif; endforeach; ?>
    </div>
    <form method="post" class="cancel-form" id="cancel-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $b['id'] ?>">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="to" value="cancelled">
        <div class="field"><label for="c-reason">Причина отмены — её увидит гость</label><input id="c-reason" name="reason" maxlength="255" placeholder="Например: гость передумал"></div>
<?php if ($b['guest_email']): ?>
        <label class="inline-check"><input type="checkbox" name="notify" value="1" checked> Сообщить гостю письмом</label>
<?php endif; ?>
        <div class="form-actions"><button class="btn btn--danger btn--sm" type="submit">Отменить бронь</button></div>
    </form>
<?php if ($b['status'] === 'cancelled' && $b['cancel_reason'] !== ''): ?>
    <p class="hint">Причина отмены: <?= e($b['cancel_reason']) ?></p>
<?php endif; ?>
</section>
<?php else: ?>
<div class="adm-head">
    <div>
        <h1>Новая бронь</h1>
        <p class="adm-head__sub">Для звонков и гостей, пришедших сами. Если гость уже бывал у нас, найдём его по телефону.</p>
    </div>
</div>
<?php endif; ?>

<div class="main-side">
    <section class="panel">
        <h2 class="panel__title"><?= $b ? 'Данные брони' : 'Гость, даты и номер' ?></h2>
<?php if (isset($errors['form'])): ?>
        <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
<?php endif; ?>
        <form method="post" class="form-grid" data-booking-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $b ? 'update' : 'create' ?>">
<?php if ($b): ?>
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
<?php endif; ?>
            <div class="<?= $cls('name') ?>"><label for="b-name">Имя гостя</label><input id="b-name" name="name" required maxlength="100" value="<?= $fv('name') ?>"><?= $err('name') ?></div>
            <div class="<?= $cls('phone') ?>"><label for="b-phone">Телефон</label><input id="b-phone" name="phone" type="tel" maxlength="25" value="<?= $fv('phone') ?>"><?= $err('phone') ?></div>
            <div class="<?= $cls('email') ?>"><label for="b-email">Почта</label><input id="b-email" name="email" type="email" maxlength="190" value="<?= $fv('email') ?>"><?= $err('email') ?></div>
            <div class="field"><label for="b-guests">Гостей</label><input id="b-guests" name="guests" type="number" min="1" max="20" value="<?= $fv('guests') ?>"></div>
            <div class="<?= $cls('check_in') ?>"><label for="b-in">Заезд</label><input id="b-in" name="check_in" type="date" required value="<?= $fv('check_in') ?>"><?= $err('check_in') ?></div>
            <div class="<?= $cls('check_out') ?>"><label for="b-out">Выезд <span class="muted" data-nights></span></label><input id="b-out" name="check_out" type="date" required value="<?= $fv('check_out') ?>"><?= $err('check_out') ?></div>
            <div class="<?= $cls('room_type_id') ?>"><label for="b-type">Тип номера</label>
                <select id="b-type" name="room_type_id">
<?php foreach ($types as $t): ?>
                    <option value="<?= $t['id'] ?>"<?= (int) $f['room_type_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?><?= $t['is_active'] ? '' : ' (скрыт)' ?></option>
<?php endforeach; ?>
                </select><?= $err('room_type_id') ?>
            </div>
            <div class="<?= $cls('room_id') ?>"><label for="b-room">Комната</label>
                <select id="b-room" name="room_id">
                    <option value="0">Любая свободная этого типа</option>
<?php foreach ($rooms as $r): $rid = (int) $r['id']; ?>
                    <option value="<?= $rid ?>" data-type="<?= (int) $r['room_type_id'] ?>"<?= (int) $f['room_id'] === $rid ? ' selected' : '' ?>><?= e($r['name']) ?><?= !$r['is_active'] ? ' (не продаётся)' : (!in_array($rid, $freeIds, true) ? ' — занята на эти даты' : '') ?></option>
<?php endforeach; ?>
                </select><?= $err('room_id') ?>
            </div>
            <div class="field"><label for="b-source">Откуда бронь</label>
                <select id="b-source" name="source">
<?php foreach (BOOKING_SOURCES as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="b-contact">Как связаться</label>
                <select id="b-contact" name="contact_pref">
<?php foreach (CONTACT_PREFS as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $f['contact_pref'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                </select>
            </div>
<?php if (!$b): ?>
            <div class="field"><label for="b-status">Статус</label>
                <select id="b-status" name="status">
<?php foreach (['new' => 'Заявка', 'confirmed' => 'Подтверждена', 'checked_in' => 'Сразу заселить', 'checked_out' => 'Прошлое проживание'] as $k => $label): ?>
                    <option value="<?= $k ?>"<?= ($f['status'] ?? 'confirmed') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                </select>
            </div>
<?php endif; ?>
            <div class="field span-all">
                <label class="inline-check"><input type="checkbox" name="price_manual" value="1"<?= !empty($f['price_manual']) ? ' checked' : '' ?>> Своя сумма вместо расчёта по ценам</label>
            </div>
            <div class="<?= $cls('total_price') ?>"><label for="b-total">Сумма за проживание, ₽</label><input id="b-total" name="total_price" inputmode="numeric" value="<?= $fv('total_price') ?>" placeholder="посчитается сама"></div>
            <div class="field"><label for="b-pre">Предоплата, ₽</label><input id="b-pre" name="prepayment" inputmode="numeric" value="<?= $fv('prepayment') ?>" placeholder="<?= e(prepayment_mode_label()) ?>"></div>
            <div class="field span-all"><label for="b-comment">Комментарий гостя</label><textarea id="b-comment" name="comment" rows="2" maxlength="1000"><?= $fv('comment') ?></textarea></div>
            <div class="field span-all"><label for="b-note">Заметка для сотрудников <span class="muted">гость её не видит</span></label><textarea id="b-note" name="admin_note" rows="2" maxlength="2000"><?= $fv('admin_note') ?></textarea></div>
<?php if (!$b): ?>
            <div class="field span-all"><label class="inline-check"><input type="checkbox" name="notify" value="1"<?= !empty($f['notify']) ? ' checked' : '' ?>> Отправить гостю письмо со ссылкой на бронь (если указана почта)</label></div>
<?php endif; ?>
            <div class="form-actions span-all">
                <button class="btn btn--primary" type="submit"><?= $b ? 'Сохранить изменения' : 'Завести бронь' ?></button>
<?php if ($b && $quoteNow && $b['price_manual']): ?>
                <span class="hint">По ценам вышло бы <?= $quoteNow['total'] !== null ? e(money($quoteNow['total'])) : 'по договорённости' ?></span>
<?php endif; ?>
            </div>
        </form>
    </section>

<?php if ($b):
    $payments = booking_payments($b['id']);
    $messages = booking_messages($b['id']);
    $history = q_all("SELECT a.*, s.name AS staff_name FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.entity = 'booking' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50", [$b['id']]);
    $guestUrl = booking_url($b);
    $waText = 'Здравствуйте! Ваша бронь ' . booking_number($b) . ' в «' . mail_hotel_name() . '» на ' . ru_date_range($b['check_in'], $b['check_out']) . '. Статус и детали: ' . $guestUrl;
    $wa = whatsapp_href($waText, $b['guest_phone'] ?? '');
?>
    <div class="blist">
        <section class="panel" id="money">
            <h2 class="panel__title">Оплата</h2>
            <div>
                <div class="money-line"><span>Сумма</span><b><?= $b['total_price'] !== null ? e(money($b['total_price'])) : 'по договорённости' ?></b></div>
                <div class="money-line"><span>Предоплата</span><b><?= e(money($b['prepayment'])) ?></b></div>
                <div class="money-line"><span>Оплачено</span><b><?= e(money($b['paid'])) ?></b></div>
<?php if ($b['balance'] !== null): ?>
                <div class="money-line <?= $b['balance'] > 0 ? 'money-line--due' : 'money-line--ok' ?>"><span><?= $b['balance'] > 0 ? 'Осталось оплатить' : ($b['balance'] < 0 ? 'Переплата' : 'Оплачено полностью') ?></span><b><?= e(money(abs($b['balance']))) ?></b></div>
<?php endif; ?>
            </div>
<?php if ($payments): ?>
            <table class="table">
                <tbody>
<?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e(ru_date($p['paid_on'])) ?><small><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['note'] !== '' ? ', ' . e($p['note']) : '' ?></small></td>
                        <td class="num"><?= (int) $p['amount'] < 0 ? '−' : '' ?><?= e(money(abs((int) $p['amount']))) ?></td>
                        <td class="num">
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="action" value="payment_delete">
                                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                <button class="icon-button" type="submit" aria-label="Удалить платёж" data-confirm="Удалить этот платёж?"><?= icon('trash-2') ?></button>
                            </form>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                <input type="hidden" name="action" value="payment_add">
                <div class="field"><label for="p-amount">Сумма, ₽</label><input id="p-amount" name="amount" inputmode="numeric" required value="<?= $b['paid'] < $b['prepayment'] ? $b['prepayment'] - $b['paid'] : ($b['balance'] > 0 ? $b['balance'] : '') ?>"></div>
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
                <div class="field span-all"><label for="p-note">Комментарий</label><input id="p-note" name="note" maxlength="255" placeholder="Например: предоплата по СБП"></div>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit"><?= icon('banknote') ?>Записать</button></div>
            </form>
        </section>

        <section class="panel">
            <h2 class="panel__title">Ссылка на бронь для гостя</h2>
            <p class="hint">По ней гость видит статус и сумму и может написать вам. Отправьте её в мессенджер, если гость не оставил почту.</p>
            <div class="copy-row">
                <input id="guest-link" value="<?= e($guestUrl) ?>" readonly>
                <button class="btn btn--outline btn--sm" type="button" data-copy="#guest-link"><?= icon('copy') ?>Копировать</button>
            </div>
            <div class="form-actions">
<?php if ($wa): ?>
                <a class="btn btn--outline btn--sm" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>Отправить в WhatsApp</a>
<?php endif; ?>
<?php if ($b['guest_email']): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="kind" value="<?= in_array($b['status'], ['confirmed', 'checked_in'], true) ? 'confirmed' : ($b['status'] === 'cancelled' ? 'cancelled' : 'received') ?>">
                    <button class="btn btn--outline btn--sm" type="submit"><?= icon('mail') ?>Письмо гостю ещё раз</button>
                </form>
<?php endif; ?>
            </div>
        </section>

<?php if ($messages): ?>
        <section class="panel" id="messages">
            <h2 class="panel__title">Сообщения гостя</h2>
<?php foreach ($messages as $m): ?>
            <div class="msg<?= $m['handled_at'] ? ' is-done' : '' ?>">
                <strong><?= e(MESSAGE_KINDS[$m['kind']] ?? $m['kind']) ?><?= $m['new_check_out'] ? ' до ' . e(ru_date($m['new_check_out'])) : '' ?></strong>
                <div class="hint"><?= e(ru_datetime($m['created_at'])) ?><?= $m['handled_at'] ? ' · обработано' . ($m['staff_name'] ? ' (' . e($m['staff_name']) . ')' : '') : '' ?></div>
<?php if ($m['message']): ?>
                <p><?= nl2br(e($m['message'])) ?></p>
<?php endif; ?>
<?php if (!$m['handled_at']): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="action" value="message_done">
                    <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn--outline btn--sm" type="submit"><?= icon('check') ?>Обработано</button>
                </form>
<?php endif; ?>
            </div>
<?php endforeach; ?>
<?php if (array_filter($messages, fn ($m) => !$m['handled_at'] && $m['kind'] === 'extend')): ?>
            <p class="hint">Чтобы продлить — поменяйте дату выезда в «Данных брони» слева: система проверит, свободна ли комната.</p>
<?php endif; ?>
        </section>
<?php endif; ?>

        <section class="panel">
            <h2 class="panel__title">История</h2>
            <ul class="history">
<?php foreach ($history as $ev): ?>
                <li><time><?= e(ru_datetime($ev['created_at'])) ?> · <?= e($ev['staff_name'] ?? ($ev['actor'] === 'guest' ? 'гость' : 'система')) ?></time><span><?= e(audit_action_label('booking', $ev['action'])) ?><?= $ev['details'] ? ': ' . e($ev['details']) : '' ?></span></li>
<?php endforeach; ?>
            </ul>
        </section>

<?php if (is_owner()): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn--danger btn--sm" type="submit" data-confirm="Удалить бронь совсем, вместе с платежами? Обычно достаточно отменить."><?= icon('trash-2') ?>Удалить бронь</button>
        </form>
<?php endif; ?>
    </div>
<?php else: ?>
    <section class="panel">
        <h2 class="panel__title">Подсказка</h2>
        <p class="hint">Сумма считается по ценам и скидкам из раздела «Цены». Нужна другая — отметьте «Своя сумма».</p>
        <p class="hint">Комнату можно не выбирать — система займёт первую свободную нужного типа. Если на эти даты всё занято, она скажет об этом.</p>
    </section>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
