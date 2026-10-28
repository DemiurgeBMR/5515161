<?php
/**
 * Интеграционные тесты: настоящая база, настоящие функции сайта.
 *
 *     HOTEL_DB_HOST=127.0.0.1 HOTEL_DB_USER=root HOTEL_DB_PASS= php tests/run.php
 *
 * Создаёт отдельную базу hotel_test (или HOTEL_DB_NAME) с нуля, письма
 * пишет в storage/mail.log. Боевую базу не трогает.
 */

// Любое предупреждение PHP (в том числе об устаревшей функции в новой
// версии PHP) — провал теста: на сайте оно сломало бы ответ формы.
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    global $failed;
    if (!(error_reporting() & $no)) {
        return false; // подавлено через @
    }
    echo "  ✗ PHP: $msg ($file:$line)\n";
    $failed++;
    return true;
});

putenv('HOTEL_DB_NAME=' . (getenv('HOTEL_DB_NAME') ?: 'hotel_test'));
putenv('HOTEL_MAIL_TRANSPORT=log');
ob_start();

require __DIR__ . '/../src/bootstrap.php';

$failed = 0;
$passed = 0;

function check(bool $cond, string $what): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  ✓ $what\n";
    } else {
        $failed++;
        echo "  ✗ $what\n";
    }
}

function expect_error(callable $fn, string $what, ?string $contains = null): void
{
    try {
        $fn();
        check(false, $what . ' (ошибки не было)');
    } catch (BookingError $e) {
        check($contains === null || mb_stripos($e->getMessage(), $contains) !== false, $what . ' — «' . $e->getMessage() . '»');
    }
}

function d(int $days): string
{
    return today()->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function guest(array $extra = []): array
{
    static $n = 0;
    $n++;
    return $extra + ['name' => 'Гость ' . $n, 'phone' => '+7 978 000-00-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'email' => "guest$n@example.com", 'guests' => 1];
}

$c = db_config();
$php = escapeshellarg(PHP_BINARY);
$env = 'HOTEL_DB_NAME=' . escapeshellarg($c['name']) . ' HOTEL_MAIL_TRANSPORT=log';

echo "Подготовка базы {$c['name']}\n";
db_connect(false)->exec('DROP DATABASE IF EXISTS `' . $c['name'] . '`');
exec("$env $php " . escapeshellarg(__DIR__ . '/../tools/migrate.php') . ' 2>&1', $out, $code);
check($code === 0, 'migrate.php создаёт базу и таблицы: ' . implode(' / ', $out));
exec("$env $php " . escapeshellarg(__DIR__ . '/../tools/migrate.php') . ' 2>&1', $out2, $code2);
check($code2 === 0, 'повторный migrate.php ничего не ломает');
@unlink(HOTEL_ROOT . '/storage/mail.log');

$types = room_types();
check(count($types) === 3, 'три типа номеров из начальных данных');
$std = array_values($types)[0];
$large = array_values($types)[1];
$lux = array_values($types)[2];
check($std['slug'] === 'standard' && $std['base_price'] === 2500, 'первый тип — Стандарт за 2500');
check(count(rooms_list()) === 3, 'по одной комнате каждого типа');

echo "\nЦены\n";
$q = quote($std, d(10), d(13));
check($q['nights'] === 3 && $q['total'] === 7500, '3 ночи Стандарта = 7500');
$q = quote($std, d(10), d(17));
check($q['total'] === 15750 && $q['discount'] === 1750, '7 ночей — скидка 10%: 15750');
$q = quote($std, d(10), d(40));
check($q['total'] === null && $q['tier']['min_nights'] === 30, '30 ночей — цена по договорённости');
check(default_prepayment(quote($std, d(10), d(13))) === 2500, 'предоплата — первая ночь');
q_insert('price_rules', ['room_type_id' => null, 'date_from' => d(20), 'date_to' => d(21), 'percent' => 20, 'note' => 'праздники', 'created_at' => now()]);
q_insert('price_rules', ['room_type_id' => $std['id'], 'date_from' => d(21), 'date_to' => d(21), 'price' => 4000, 'note' => '', 'created_at' => now()]);
$p = night_prices($std, d(19), d(23));
check($p[d(19)] === 2500 && $p[d(20)] === 3000 && $p[d(21)] === 4000 && $p[d(22)] === 2500, 'сезонные цены: +20% и фиксированная цена сильнее процента');
check(night_prices($large, d(21), d(22))[d(21)] === 3600, 'правило «+20% для всех» действует на другой тип');
q('DELETE FROM price_rules');

echo "\nБронирование и занятость\n";
$b1 = booking_create(guest(['check_in' => d(1), 'check_out' => d(4), 'room_type_id' => $std['id']]));
check($b1['status'] === 'new' && $b1['room_id'] !== null && $b1['total_price'] === 7500, 'бронь с сайта: заявка, комната выбрана, сумма 7500');
check(strlen($b1['token']) === 40 && booking_by_token($b1['token'])['id'] === $b1['id'], 'секретная ссылка на бронь работает');
expect_error(fn () => booking_create(guest(['check_in' => d(2), 'check_out' => d(3), 'room_type_id' => $std['id']])), 'пересечение дат в единственном Стандарте не проходит', 'свободных');
$b2 = booking_create(guest(['check_in' => d(4), 'check_out' => d(6), 'room_type_id' => $std['id']]));
check($b2['room_id'] === $b1['room_id'], 'заезд в день выезда предыдущего гостя — можно (выезд 12:00, заезд 13:00)');
$b3 = booking_create(guest(['check_in' => d(2), 'check_out' => d(3), 'room_type_id' => 0]));
check($b3['room_type_id'] === $large['id'], '«подберите мне»: Стандарт занят — взяли следующий по цене (Большой)');
expect_error(fn () => booking_create(guest(['check_in' => d(8), 'check_out' => d(9), 'room_type_id' => $std['id'], 'guests' => 3])), 'в Стандарт нельзя троих', 'до 2');
expect_error(fn () => booking_create(guest(['check_in' => d(-2), 'check_out' => d(1), 'room_type_id' => $std['id']])), 'заезд в прошлом с сайта нельзя', 'прошла');
expect_error(fn () => booking_create(guest(['check_in' => d(5), 'check_out' => d(5), 'room_type_id' => $std['id']])), 'выезд в день заезда нельзя', 'позже');
expect_error(fn () => booking_create(guest(['check_in' => d(30), 'check_out' => d(31), 'room_type_id' => $std['id'], 'phone' => '123'])), 'короткий телефон не проходит', 'телефон');

$grid = availability_grid(d(0), d(7));
check($grid['free'][d(1)][$std['id']] === 0 && $grid['free'][d(6)][$std['id']] === 1, 'календарь: Стандарт занят на ночь d+1 и свободен на d+6');
check($grid['free'][d(2)][$large['id']] === 0 && $grid['free'][d(3)][$large['id']] === 1, 'календарь: Большой занят только на ночь d+2');

echo "\nСтатусы\n";
booking_set_status($b2['id'], 'cancelled', 'передумали');
$b4 = booking_create(guest(['check_in' => d(4), 'check_out' => d(6), 'room_type_id' => $std['id']]));
check((bool) $b4['id'], 'отменённая бронь освобождает комнату');
expect_error(fn () => booking_set_status($b2['id'], 'confirmed'), 'вернуть отменённую бронь на занятые даты нельзя', 'занята');
check(booking_get($b2['id'])['cancel_reason'] === 'передумали', 'причина отмены сохранена');

// Выезд раньше срока: бронь, начавшаяся в прошлом, выезжает сегодня.
$past = booking_create(guest(['check_in' => d(-3), 'check_out' => d(3), 'room_type_id' => $lux['id'], 'status' => 'checked_in']), true);
$after = booking_set_status($past['id'], 'checked_out');
check($after['check_out'] === d(0) && $after['total_price'] === 15000, 'выезд раньше: дата выезда = сегодня, сумма за 3 ночи');

echo "\nАдминка: правка брони и закрытия\n";
$upd = booking_update($b4['id'], ['check_in' => d(4), 'check_out' => d(7), 'room_id' => 0, 'name' => 'Новое Имя', 'phone' => '+79780000099', 'email' => '', 'guests' => 1, 'prepayment' => 0]);
check($upd['check_out'] === d(7) && $upd['total_price'] === 7500 && $upd['guest_name'] === 'Новое Имя', 'изменили даты — сумма пересчиталась, имя гостя обновилось');
$manual = booking_update($b4['id'], ['check_in' => d(4), 'check_out' => d(7), 'room_id' => 0, 'name' => 'Новое Имя', 'phone' => '', 'email' => '', 'price_manual' => 1, 'total_price' => 6000, 'prepayment' => 1000]);
check($manual['total_price'] === 6000 && $manual['price_manual'] === 1 && $manual['prepayment'] === 1000, 'своя цена и предоплата сохраняются');
expect_error(fn () => booking_update($b3['id'], ['check_in' => d(4), 'check_out' => d(5), 'room_id' => $b1['room_id'], 'name' => 'X']), 'перенос в занятую комнату не проходит', 'занята');
expect_error(fn () => closure_add($b1['room_id'], d(1), d(2), 'ремонт'), 'закрыть комнату на занятые даты нельзя', 'занята');
$cl = closure_add($b1['room_id'], d(10), d(12), 'ремонт');
expect_error(fn () => booking_create(guest(['check_in' => d(11), 'check_out' => d(12), 'room_type_id' => $std['id']])), 'закрытая комната не продаётся', 'свободных');
closure_delete($cl);
check((bool) booking_create(guest(['check_in' => d(11), 'check_out' => d(12), 'room_type_id' => $std['id']]))['id'], 'после открытия — продаётся');

// Цены выросли, а в брони поправили только заметку — сумма, которую назвали гостю, остаётся.
q('UPDATE room_types SET base_price = 3500 WHERE id = ?', [$large['id']]);
app_cache_clear();
$b3 = booking_get($b3['id']);
$kept = booking_update($b3['id'], ['check_in' => $b3['check_in'], 'check_out' => $b3['check_out'], 'room_id' => 0, 'name' => $b3['guest_name'],
    'phone' => $b3['guest_phone'], 'email' => $b3['guest_email'], 'guests' => 1, 'prepayment' => $b3['prepayment'], 'admin_note' => 'тихий номер']);
check($kept['total_price'] === 3000 && $kept['admin_note'] === 'тихий номер', 'новая цена не меняет сумму брони, если даты и номер те же');
q('UPDATE room_types SET base_price = 3000 WHERE id = ?', [$large['id']]);
app_cache_clear();

echo "\nОплаты и сообщения\n";
payment_add($b1['id'], 2500, 'sbp', d(0), 'предоплата');
payment_add($b1['id'], 5000, 'cash', d(0), '');
$b1 = booking_get($b1['id']);
check($b1['paid'] === 7500 && $b1['balance'] === 0, 'оплаты суммируются, долг 0');
$mid = message_add($b1, 'extend', '', d(8));
check(booking_get($b1['id'])['open_messages'] === 1, 'сообщение гостя «продлить» сохранено');
expect_error(fn () => message_add($b1, 'extend', '', d(2)), 'продлить на дату раньше выезда нельзя', 'позже');
message_mark_handled($mid);
check(booking_get($b1['id'])['open_messages'] === 0, 'сообщение отмечено обработанным');

echo "\nОдновременное бронирование последнего номера\n";
$start = microtime(true) + 1.5;
$procs = [];
for ($i = 0; $i < 6; $i++) {
    $procs[] = popen("$env $php " . escapeshellarg(__DIR__ . '/book_once.php') . " $start " . d(50) . ' ' . d(52) . ' ' . $lux['id'] . ' 2>&1', 'r');
}
$results = array_map(fn ($p) => trim(stream_get_contents($p)) . (pclose($p) ? '' : ''), $procs);
$ok = count(array_filter($results, fn ($r) => strpos($r, 'OK') === 0));
check($ok === 1, 'из 6 одновременных броней Люкса прошла ровно одна (' . implode(', ', $results) . ')');

echo "\nПлощадки (iCal)\n";
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:abc@avito\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', d(60)) . "\r\nDTEND;VALUE=DATE:" . str_replace('-', '', d(63)) . "\r\nSUMMARY:Booked\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:" . str_replace('-', '', d(70)) . "T140000Z\r\nDTEND:" . str_replace('-', '', d(71)) . "T100000Z\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:long-uid-\r\n folded\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', d(80)) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$ev = ical_parse($ics);
check(count($ev) === 2 && $ev[0]['from'] === d(60) && $ev[0]['to'] === d(63), 'разбор календаря: даты, отменённое событие пропущено');
check($ev[1]['uid'] === 'long-uid-folded' && $ev[1]['to'] === d(81), 'перенос длинных строк и событие без даты окончания');
$room = q_one('SELECT * FROM rooms WHERE id = ?', [$b1['room_id']]);
$out = ical_export($room);
check(strpos($out, 'DTSTART;VALUE=DATE:' . str_replace('-', '', d(1))) !== false && strpos($out, 'Гость') === false, 'экспорт: даты брони есть, имён гостей нет');

echo "\nВход гостя по коду\n";
@unlink(HOTEL_ROOT . '/storage/mail.log');
check(guest_send_code('guest1@example.com') === null, 'код отправлен');
preg_match('/Код для входа: (\d{6})/u', (string) @file_get_contents(HOTEL_ROOT . '/storage/mail.log'), $m);
check(isset($m[1]), 'письмо с кодом ушло (в лог)');
check(guest_send_code('nobody@example.com') === null && substr_count((string) file_get_contents(HOTEL_ROOT . '/storage/mail.log'), 'Код для входа') === 1, 'на чужую почту без броней код не уходит, но ответ тот же');
check(guest_verify_code('guest1@example.com', '000000') !== null || ($m[1] ?? '') === '000000', 'неверный код не пускает');
check(guest_verify_code('guest1@example.com', $m[1] ?? '') === null && current_guest_email() === 'guest1@example.com', 'верный код пускает в «Мои брони»');
check(guest_verify_code('guest1@example.com', $m[1] ?? '') !== null, 'код одноразовый');

echo "\nСотрудники\n";
$owner = staff_create('Owner@Example.com', 'Владелец', 'owner', 'secret-pass-1');
check($owner['email'] === 'owner@example.com', 'владелец создан, почта приведена к нижнему регистру');
check(is_string(staff_login('owner@example.com', 'wrong-password')), 'неверный пароль не пускает');
$s = staff_login('owner@example.com', 'secret-pass-1');
check(is_array($s) && current_staff()['id'] === $owner['id'], 'верный пароль пускает');
expect_error(fn () => staff_create('owner@example.com', 'Дубль', 'admin'), 'второй сотрудник с той же почтой — нельзя', 'уже есть');
for ($i = 0; $i < 8; $i++) {
    staff_login('owner@example.com', 'wrong');
}
check(staff_login('owner@example.com', 'secret-pass-1') === 'Слишком много попыток. Подождите 15 минут.', 'после 8 ошибок вход временно закрыт');
check(in_array('owner@example.com', admin_recipients(), true), 'владелец получает уведомления');

echo "\nПисьма\n";
@unlink(HOTEL_ROOT . '/storage/mail.log');
$b = booking_get($b1['id']);
foreach (['mail_booking_received', 'mail_booking_confirmed', 'mail_booking_cancelled', 'mail_reminder', 'mail_admin_new_booking'] as $fn) {
    [$subj, $text] = $fn($b);
    check($subj !== '' && strpos($text, booking_number($b)) !== false || strpos($text, ru_date($b['check_in'])) !== false, "$fn: тема «" . $subj . '»');
}
check(send_mail('a@example.com', 'Тест', 'Текст') && strpos(file_get_contents(HOTEL_ROOT . '/storage/mail.log'), 'Тема: Тест') !== false, 'отправка в лог работает');

echo "\nФоновые задачи\n";
$oldGuest = guest_upsert('Старая Заявка', '+79780001111', 'old@example.com');
q_insert('bookings', ['token' => bin2hex(random_bytes(20)), 'guest_id' => $oldGuest, 'room_type_id' => $std['id'], 'room_id' => $b1['room_id'],
    'check_in' => d(-60), 'check_out' => d(-58), 'status' => 'cancelled', 'created_at' => d(-70) . ' 10:00:00', 'updated_at' => d(-70) . ' 10:00:00']);
$n = cron_cleanup();
check(guest_get($oldGuest)['anonymized_at'] !== null && guest_get($oldGuest)['phone'] === '', 'данные несостоявшейся заявки старше 30 дней удалены');
check(guest_get($b1['guest_id'])['anonymized_at'] === null, 'данные действующих гостей не тронуты');

echo "\nЗащита карточек гостей\n";
$victim = booking_create(guest(['check_in' => d(90), 'check_out' => d(91), 'room_type_id' => $lux['id'], 'phone' => '+7 978 555-00-01', 'email' => '']), true);
$att = booking_create(guest(['check_in' => d(92), 'check_out' => d(93), 'room_type_id' => $lux['id'], 'phone' => '+7 978 555-00-01', 'email' => 'other@example.com']));
check($att['guest_id'] !== $victim['guest_id'] && guest_get($victim['guest_id'])['email'] === '', 'заявка с сайта с чужим телефоном не попадает в чужую карточку и не меняет её почту');
check($att['guest_email'] === 'other@example.com', 'письмо по такой заявке уйдёт на почту из заявки');
$same = booking_create(guest(['check_in' => d(94), 'check_out' => d(95), 'room_type_id' => $lux['id'], 'phone' => '+7 978 555-00-01', 'email' => 'other@example.com']));
check($same['guest_id'] === $att['guest_id'], 'тот же телефон и та же почта — та же карточка гостя');
$odd = booking_create(guest(['check_in' => d(96), 'check_out' => d(97), 'room_type_id' => $lux['id'], 'name' => "Иван\r\nBcc: x@example.com"]));
check($odd['guest_name'] === 'Иван Bcc: x@example.com', 'переводы строк из имени убираются (не попадут в заголовки письма)');
expect_error(fn () => booking_create(guest(['check_in' => today()->modify('+19 months')->format('Y-m-d'), 'check_out' => today()->modify('+19 months +2 days')->format('Y-m-d'), 'room_type_id' => $std['id']])), 'с сайта нельзя бронировать дальше 18 месяцев', 'далеко');

echo "\nУдаление данных по просьбе гостя\n";
$an = booking_create(guest(['check_in' => d(40), 'check_out' => d(42), 'room_type_id' => $lux['id'], 'comment' => 'приедем с собакой']));
message_add($an, 'other', 'Мой второй телефон +7 978 000-12-34', null);
q('UPDATE bookings SET admin_note = ? WHERE id = ?', ['звонить после 18', $an['id']]);
guest_anonymize($an['guest_id'], 'по просьбе гостя');
$an = booking_get($an['id']);
check($an['guest_name'] === 'Данные удалены' && $an['guest_phone'] === '' && $an['guest_email'] === '', 'имя, телефон и почта стёрты');
check($an['guest_comment'] === null && $an['admin_note'] === null, 'комментарий гостя и заметка администратора стёрты');
check((int) q_val('SELECT COUNT(*) FROM booking_messages WHERE booking_id = ? AND message IS NOT NULL', [$an['id']]) === 0, 'тексты сообщений гостя стёрты');
check((int) q_val("SELECT COUNT(*) FROM audit_log WHERE entity = 'booking' AND entity_id = ? AND action = 'guest_message' AND details IS NOT NULL", [$an['id']]) === 0, 'из журнала стёрты подробности сообщений');
check($an['total_price'] !== null && $an['check_in'] === d(40), 'сама бронь осталась для учёта');

echo "\nФото\n";
$img = imagecreatetruecolor(3000, 2000);
imagefill($img, 0, 0, imagecolorallocate($img, 200, 150, 100));
$tmp = tempnam(sys_get_temp_dir(), 'img');
imagejpeg($img, $tmp, 90);
$up = upload_photo(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)], 'test', false);
check($up['width'] === 1600 && $up['height'] === 1067 && is_file(HOTEL_ROOT . '/public/' . $up['path']), 'фото уменьшено до 1600 px и сохранено');
$small = upload_small($up['path']);
check($small !== null && getimagesize(HOTEL_ROOT . '/public/' . $small)[0] === 800, 'для телефонов сохранена копия 800 px');
check(str_contains(img_srcset($up['path'], '100vw'), '800w'), 'у фото на сайте есть srcset с лёгкой копией');
delete_upload($up['path']);
check(!is_file(HOTEL_ROOT . '/public/' . $up['path']) && !is_file(HOTEL_ROOT . '/public/' . $small), 'фото удаляется вместе с копией');
@rmdir(HOTEL_ROOT . '/public/uploads/test');
file_put_contents($tmp, '<?php echo "hack";');
expect_error(fn () => upload_photo(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 20], 'test', false), 'PHP-файл под видом фото не загружается', 'JPEG');
@unlink($tmp);

echo "\nИтого: $passed прошло, $failed не прошло\n";
ob_end_flush();
exit($failed ? 1 : 0);
