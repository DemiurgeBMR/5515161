<?php
/**
 * Приём заявок с сайта: бронирование и «сообщить об открытии бани».
 *
 * Бронь сразу записывается в базу с проверкой занятости — заявка не
 * потеряется, даже если почта не дошла. Администратор получает письмо,
 * гость — письмо со ссылкой на свою бронь (если указал почту).
 *
 * Отвечает JSON, если его просили (форма с JS); без JS перенаправляет
 * на страницу брони или обратно на главную с ошибкой.
 */

require __DIR__ . '/../../src/bootstrap.php';

const RATE_LIMIT_MAX    = 5;    // заявок
const RATE_LIMIT_WINDOW = 600;  // за 10 минут с одного IP
const MIN_FILL_SECONDS  = 3;    // быстрее человек форму не заполнит

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$type = post('type') === 'bath' ? 'bath' : 'booking';

function fail(string $error, int $status, array $fields = [], string $message = ''): void
{
    global $wantsJson, $type;
    if ($wantsJson) {
        json_response(['ok' => false, 'error' => $error, 'fields' => (object) $fields, 'message' => $message], $status);
    }
    $param = $type === 'bath' ? 'notify' : 'booking';
    redirect('/?' . $param . '=error' . ($message !== '' ? '&msg=' . rawurlencode($message) : '') . '#' . ($type === 'bath' ? 'bath' : 'booking'));
}

if (!is_post()) {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

// Боты заполняют скрытое поле и отправляют форму мгновенно. Отвечаем им
// «успехом», чтобы не подсказывать, что их раскусили.
$ts = (int) post('ts', '0');
if (post('website') !== '' || ($ts > 0 && time() - $ts < MIN_FILL_SECONDS)) {
    $wantsJson ? json_response(['ok' => true, 'booking' => null]) : redirect('/');
}

if (!db_ready()) {
    fail('server', 503, [], 'Сайт временно не принимает заявки — позвоните нам.');
}

if (post('consent') !== '1') {
    fail('validation', 422, ['consent' => 'Нужно согласие на обработку персональных данных']);
}

if ($type === 'bath') {
    [$phone, $digits] = normalize_phone(post('phone'));
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        fail('validation', 422, ['phone' => 'Проверьте номер телефона']);
    }
    if (rate_limited('waitlist_ip', client_ip(), RATE_LIMIT_MAX, RATE_LIMIT_WINDOW)) {
        fail('rate_limit', 429);
    }
    rate_hit('waitlist_ip', client_ip());
    q_insert('waitlist', ['topic' => 'bath', 'phone' => $phone, 'created_at' => now()]);
    notify_admins(mail_admin_waitlist($phone));
    tg_notify_waitlist($phone);
    $wantsJson ? json_response(['ok' => true]) : redirect(post('back') === 'bath' ? '/bath.php?notify=ok#notify' : '/?notify=ok#bath');
}

if (rate_limited('booking_ip', client_ip(), RATE_LIMIT_MAX, RATE_LIMIT_WINDOW)) {
    fail('rate_limit', 429, [], 'Слишком много заявок подряд — позвоните нам.');
}

try {
    $booking = booking_create([
        'check_in'     => post('check_in'),
        'check_out'    => post('check_out'),
        'room_type_id' => (int) post('room', '0'),
        'guests'       => (int) post('guests', '1'),
        'name'         => mb_substr(post('name'), 0, 80),
        'phone'        => mb_substr(post('phone'), 0, 25),
        'email'        => mb_substr(post('email'), 0, 190),
        'comment'      => mb_substr(post('comment'), 0, 1000),
        'contact_pref' => post('contact', 'call'),
    ]);
} catch (BookingError $e) {
    $field = $e->field !== '' ? $e->field : 'check_in';
    fail($field === 'check_in' && str_contains($e->getMessage(), 'свободных') ? 'no_rooms' : 'validation', 422, [$field => $e->getMessage()], $e->getMessage());
} catch (Throwable $e) {
    error_log('hotel booking: ' . $e->getMessage());
    fail('server', 500, [], 'Не получилось сохранить заявку.');
}

rate_hit('booking_ip', client_ip());

// Письма — после сохранения брони: если почта не ушла, заявка всё равно в админке.
notify_admins(mail_admin_new_booking($booking), (string) $booking['guest_email']);
tg_notify_new_booking($booking);
$emailSent = false;
if ($booking['guest_email']) {
    [$subject, $text] = mail_booking_received($booking);
    $emailSent = send_mail($booking['guest_email'], $subject, $text);
}

if (!$wantsJson) {
    redirect('/booking.php?t=' . $booking['token'] . '&new=1');
}
json_response(['ok' => true, 'booking' => [
    'number'     => booking_number($booking),
    'url'        => '/booking.php?t=' . $booking['token'],
    'type_name'  => $booking['type_name'],
    'dates'      => ru_date_range($booking['check_in'], $booking['check_out']) . ', ' . nights_label($booking['nights']),
    'total'      => $booking['total_price'] !== null ? money($booking['total_price']) : null,
    'email'      => $booking['guest_email'] ?: null,
    'email_sent' => $emailSent,
]]);
