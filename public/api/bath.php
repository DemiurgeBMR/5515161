<?php
/**
 * Баня: свободное время и запись.
 *
 *   GET  /api/bath.php?date=ГГГГ-ММ-ДД — с какого часа можно начать и на
 *        сколько часов (без имён и личных данных — только время).
 *   POST /api/bath.php — записаться. Отвечает JSON, если его просили (форма
 *        с JS); без JS перенаправляет на страницу записи или обратно с ошибкой.
 */

require __DIR__ . '/../../src/bootstrap.php';

const BATH_RATE_MAX    = 5;    // заявок
const BATH_RATE_WINDOW = 600;  // за 10 минут с одного IP
const BATH_MIN_FILL    = 3;    // секунд — быстрее человек форму не заполнит

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function bath_fail(string $error, int $status, array $fields = [], string $message = ''): void
{
    global $wantsJson;
    if ($wantsJson) {
        json_response(['ok' => false, 'error' => $error, 'fields' => (object) $fields, 'message' => $message], $status);
    }
    // Текст ошибки в адрес не кладём (его можно подделать в ссылке) — только код.
    redirect('/bath.php?error=' . ($error === 'taken' ? 'taken' : '1') . '#book');
}

if (!db_ready() || !bath_enabled()) {
    json_response(['ok' => false], 503);
}

if (!is_post()) {
    $date = get('date');
    if (!parse_date($date)) {
        json_response(['ok' => false, 'message' => 'Нужна дата'], 400);
    }
    if (!bath_is_open()) {
        json_response(['ok' => true, 'open' => false, 'slots' => []]);
    }
    $r = bath_slots($date);
    header('Cache-Control: no-store');
    json_response(['ok' => true, 'open' => true, 'date' => $date, 'working_day' => $r['working_day'], 'slots' => $r['slots'],
        'hour_price' => bath_hour_price($date)]);
}

$ts = (int) post('ts', '0');
if (post('website') !== '' || ($ts > 0 && time() - $ts < BATH_MIN_FILL)) {
    $wantsJson ? json_response(['ok' => true, 'booking' => null]) : redirect('/bath.php');
}
if (post('consent') !== '1') {
    bath_fail('validation', 422, ['consent' => 'Нужно согласие на обработку персональных данных']);
}
if (rate_limited('bath_ip', client_ip(), BATH_RATE_MAX, BATH_RATE_WINDOW)) {
    bath_fail('rate_limit', 429, [], 'Слишком много заявок подряд — позвоните нам.');
}

try {
    $b = bath_create([
        'date'         => post('date'),
        'time'         => post('time'),
        'hours'        => (int) post('hours'),
        'guests'       => (int) post('guests', '1'),
        'name'         => mb_substr(post('name'), 0, 80),
        'phone'        => mb_substr(post('phone'), 0, 25),
        'email'        => mb_substr(post('email'), 0, 190),
        'contact_pref' => post('contact_pref', 'call'),
        'hotel_guest'  => post('hotel_guest') === '1',
        'extras'       => bath_extras_from_input(is_array($_POST['extras'] ?? null) ? $_POST['extras'] : []),
        'comment'      => mb_substr(post('comment'), 0, 1000),
    ]);
} catch (BookingError $e) {
    $field = $e->field !== '' ? $e->field : 'time';
    bath_fail(str_contains($e->getMessage(), 'занято') ? 'taken' : 'validation', 422, [$field => $e->getMessage()], $e->getMessage());
} catch (Throwable $e) {
    error_log('hotel bath: ' . $e->getMessage());
    bath_fail('server', 500, [], 'Не получилось сохранить заявку.');
}

rate_hit('bath_ip', client_ip());

// Письма — после записи в базу: если почта не ушла, заявка всё равно в админке.
notify_admins(mail_admin_new_bath($b), (string) $b['guest_email']);
$emailSent = false;
if ($b['guest_email']) {
    [$subject, $text] = mail_bath_received($b);
    $emailSent = send_mail($b['guest_email'], $subject, $text);
}

if (!$wantsJson) {
    redirect('/bath_booking.php?t=' . $b['token'] . '&new=1');
}
json_response(['ok' => true, 'booking' => [
    'url'        => '/bath_booking.php?t=' . $b['token'] . '&new=1',
    'email_sent' => $emailSent,
]]);
