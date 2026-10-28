<?php
/**
 * Письма: отправка и тексты.
 *
 * Как отправлять, задаётся в config/secrets.php (mail_transport):
 * 'smtp' — через почтовый ящик (надёжнее всего), 'mail' — функцией mail()
 * хостинга, 'log' — не отправлять, а складывать в storage/mail.log.
 *
 * Письмо — только уведомление: бронь уже сохранена в базе, поэтому если
 * почта не ушла, заявка всё равно видна в админке.
 */

function send_mail(string $to, string $subject, string $text, string $replyTo = ''): bool
{
    $s = hotel_secrets();
    $fromName = $s['mail_from_name'] !== '' ? $s['mail_from_name'] : (filled(hotel()['name']) ? hotel()['name'] : 'Гостиница');
    $text = str_replace("\r\n", "\n", $text);
    $subject = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject)); // перевод строки в теме — путь к подмене заголовков

    try {
        switch ($s['mail_transport']) {
            case 'smtp':
                return send_mail_smtp($to, $subject, $text, $replyTo, $fromName);
            case 'mail':
                return send_mail_php($to, $subject, $text, $replyTo, $fromName);
            default:
                return send_mail_log($to, $subject, $text, $replyTo);
        }
    } catch (Throwable $e) {
        error_log('hotel mail: не отправлено письмо «' . $subject . '»: ' . $e->getMessage());
        return false;
    }
}

function send_mail_log(string $to, string $subject, string $text, string $replyTo): bool
{
    $dir = HOTEL_ROOT . '/storage';
    $entry = '=== ' . now() . "\nКому: " . $to . ($replyTo !== '' ? "\nОтвет: " . $replyTo : '') . "\nТема: " . $subject . "\n\n" . $text . "\n\n";
    return file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
}

function send_mail_php(string $to, string $subject, string $text, string $replyTo, string $fromName): bool
{
    $from = hotel_secrets()['mail_from'];
    $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
    $params = '';
    if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>';
        $params = '-f' . $from;
    }
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, implode("\r\n", $headers), $params);
    if (!$ok) {
        error_log('hotel mail: mail() не отправил письмо');
    }
    return $ok;
}

function send_mail_smtp(string $to, string $subject, string $text, string $replyTo, string $fromName): bool
{
    require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
    require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';
    $s = hotel_secrets();
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP();
    $m->Host = $s['smtp_host'];
    $m->Port = (int) $s['smtp_port'];
    $m->SMTPAuth = $s['smtp_user'] !== '';
    $m->Username = $s['smtp_user'];
    $m->Password = $s['smtp_pass'];
    if ($s['smtp_secure'] === 'ssl') {
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($s['smtp_secure'] === 'tls') {
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
    $m->Timeout = 15;
    $m->CharSet = 'UTF-8';
    $m->setFrom($s['mail_from'] !== '' ? $s['mail_from'] : $s['smtp_user'], $fromName);
    $m->addAddress($to);
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $m->addReplyTo($replyTo);
    }
    $m->Subject = $subject;
    $m->Body = $text;
    $m->isHTML(false);
    return $m->send();
}

/** Кому слать уведомления: сотрудники с включёнными уведомлениями + адреса из настроек. */
function admin_recipients(): array
{
    $list = q_all('SELECT email FROM staff WHERE is_active = 1 AND notify = 1');
    $emails = array_column($list, 'email');
    foreach (preg_split('/[\s,;]+/', (string) hotel_secrets()['notify_extra']) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $emails[] = mb_strtolower($e);
        }
    }
    return array_values(array_unique($emails));
}

function notify_admins(array $mail, string $replyTo = ''): void
{
    [$subject, $text] = $mail;
    $to = admin_recipients();
    if (!$to) {
        error_log('hotel mail: некому отправить уведомление «' . $subject . '» — нет сотрудников с включёнными уведомлениями');
        return;
    }
    foreach ($to as $email) {
        send_mail($email, $subject, $text, $replyTo);
    }
}

// -------------------------------------------------------------------
// Тексты писем
// -------------------------------------------------------------------

function mail_hotel_name(): string
{
    return filled(hotel()['name']) ? hotel()['name'] : 'Гостиница';
}

function mail_signature(): string
{
    $h = hotel();
    return "\n—\n" . mail_hotel_name() . "\n" . full_address() . "\nТелефон: " . $h['phone'] . "\nЗаезд с " . $h['check_in'] . ', выезд до ' . $h['check_out'];
}

function mail_booking_details(array $b): string
{
    $lines = [
        'Бронь ' . booking_number($b),
        'Даты: ' . ru_date_range($b['check_in'], $b['check_out']) . ' (' . nights_label($b['nights']) . ')',
        'Номер: ' . $b['type_name'],
        'Гостей: ' . $b['guests_count'],
        'Стоимость: ' . ($b['total_price'] !== null ? money($b['total_price']) : 'рассчитает администратор'),
    ];
    if ($b['prepayment'] > 0) {
        $lines[] = 'Предоплата: ' . money($b['prepayment']) . ($b['paid'] >= $b['prepayment'] ? ' — получена' : '');
    }
    return implode("\n", $lines);
}

function mail_booking_received(array $b): array
{
    $h = hotel();
    $text = 'Здравствуйте' . ($b['guest_name'] ? ', ' . $b['guest_name'] : '') . "!\n\n"
        . "Мы получили вашу заявку на бронирование.\n\n"
        . mail_booking_details($b) . "\n\n"
        . 'Администратор проверит детали и свяжется с вами в течение ' . $h['response_time'] . ' (' . $h['admin_hours'] . ").\n"
        . ($b['prepayment'] > 0 ? 'Бронь станет подтверждённой после предоплаты ' . money($b['prepayment']) . ".\n" : '')
        . "\nВаша бронь, её статус и связь с администратором:\n" . booking_url($b) . "\n"
        . mail_signature();
    return ['Заявка ' . booking_number($b) . ' принята — ' . mail_hotel_name(), $text];
}

function mail_booking_confirmed(array $b): array
{
    $h = hotel();
    $text = 'Здравствуйте' . ($b['guest_name'] ? ', ' . $b['guest_name'] : '') . "!\n\n"
        . "Ваша бронь подтверждена. Ждём вас!\n\n"
        . mail_booking_details($b) . "\n\n"
        . 'Адрес: ' . full_address() . "\n"
        . (filled($h['address']['entrance']) ? $h['address']['entrance'] . "\n" : '')
        . 'Маршрут: ' . route_href() . "\n\n"
        . "При заселении нужен паспорт каждого взрослого гостя.\n"
        . 'Если планы изменятся — напишите нам через страницу брони:' . "\n" . booking_url($b) . "\n"
        . mail_signature();
    return ['Бронь ' . booking_number($b) . ' подтверждена — ' . mail_hotel_name(), $text];
}

function mail_booking_cancelled(array $b): array
{
    $text = 'Здравствуйте' . ($b['guest_name'] ? ', ' . $b['guest_name'] : '') . "!\n\n"
        . 'Бронь ' . booking_number($b) . ' на ' . ru_date_range($b['check_in'], $b['check_out']) . " отменена.\n"
        . ($b['cancel_reason'] !== '' ? 'Причина: ' . $b['cancel_reason'] . "\n" : '')
        . "\nЕсли это ошибка или вы хотите выбрать другие даты — позвоните нам.\n"
        . mail_signature();
    return ['Бронь ' . booking_number($b) . ' отменена — ' . mail_hotel_name(), $text];
}

function mail_reminder(array $b): array
{
    $h = hotel();
    $text = 'Здравствуйте' . ($b['guest_name'] ? ', ' . $b['guest_name'] : '') . "!\n\n"
        . 'Ждём вас ' . ru_date($b['check_in']) . '. Заселение с ' . $h['check_in'] . ".\n"
        . "Если приедете раньше — оставьте багаж у нас, а если номер будет готов, заселим сразу.\n\n"
        . 'Адрес: ' . full_address() . "\n"
        . (filled($h['address']['entrance']) ? $h['address']['entrance'] . "\n" : '')
        . 'Маршрут: ' . route_href() . "\n\n"
        . "Не забудьте паспорт. Если что-то изменилось — позвоните или напишите через страницу брони:\n"
        . booking_url($b) . "\n"
        . mail_signature();
    return ['Ждём вас ' . ru_date($b['check_in']) . ' — ' . mail_hotel_name(), $text];
}

function mail_login_code(string $code): array
{
    $text = "Ваш код для входа в «Мои брони»: " . $code . "\n\n"
        . "Код действует 15 минут. Если вы не запрашивали код — просто удалите это письмо.\n"
        . mail_signature();
    return ['Код для входа: ' . $code, $text];
}

function admin_booking_url(int $id): string
{
    return base_url() . '/admin/booking.php?id=' . $id;
}

function mail_admin_new_booking(array $b): array
{
    $contact = CONTACT_PREFS[$b['contact_pref']] ?? $b['contact_pref'];
    $text = "Новая заявка с сайта.\n\n"
        . 'Гость: ' . $b['guest_name'] . "\n"
        . 'Телефон: ' . ($b['guest_phone'] !== '' ? $b['guest_phone'] : '—') . "\n"
        . ($b['guest_email'] ? 'Почта: ' . $b['guest_email'] . "\n" : '')
        . 'Связаться: ' . $contact . "\n\n"
        . mail_booking_details($b) . "\n"
        . 'Комната: ' . ($b['room_name'] ?? '—') . "\n"
        . ($b['guest_comment'] ? "\nКомментарий: " . $b['guest_comment'] . "\n" : '')
        . "\nОткрыть в админке: " . admin_booking_url($b['id']) . "\n";
    return ['Новая заявка ' . booking_number($b) . ': ' . ru_date_range($b['check_in'], $b['check_out']) . ', ' . $b['type_name'] . ', ' . $b['guest_name'], $text];
}

function mail_admin_guest_message(array $b, string $kind, string $message, ?string $newCheckOut): array
{
    $text = 'Гость написал по брони ' . booking_number($b) . ".\n\n"
        . 'Что нужно: ' . (MESSAGE_KINDS[$kind] ?? $kind) . ($newCheckOut ? ' — до ' . ru_date($newCheckOut) : '') . "\n"
        . ($message !== '' ? 'Сообщение: ' . $message . "\n" : '')
        . "\nГость: " . $b['guest_name'] . ', ' . ($b['guest_phone'] !== '' ? $b['guest_phone'] : '—') . "\n"
        . 'Сейчас: ' . ru_date_range($b['check_in'], $b['check_out']) . ', ' . $b['type_name'] . "\n"
        . "\nОткрыть в админке: " . admin_booking_url($b['id']) . "\n";
    return ['Гость просит: ' . mb_strtolower(MESSAGE_KINDS[$kind] ?? $kind) . ' (' . booking_number($b) . ')', $text];
}

function mail_admin_waitlist(string $phone): array
{
    return ['Баня и хамам: сообщить об открытии', "Гость просит сообщить об открытии бани и хамама.\n\nТелефон: " . $phone . "\n\nВсе такие просьбы — в админке: Гости → Ждут открытия бани.\n"];
}
