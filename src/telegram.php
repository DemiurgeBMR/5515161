<?php
/**
 * Бот в Telegram: уведомления сотрудникам и кнопки прямо в чате.
 *
 *  • Администраторам и владельцу — новая заявка (кнопка «Подтвердить»),
 *    заявка в баню, сообщение гостя, лист ожидания, утренняя сводка дня.
 *  • Горничным — «пора убрать» с кнопкой «Убрано».
 *
 * Сотрудник подключает свой Telegram сам: Профиль → «Уведомления в
 * Telegram» → «Подключить» — открывает ссылку на бота (код отправится сам)
 * или пишет боту код. Кнопки работают от имени этого сотрудника: с теми же
 * правами и записью в журнал, как если бы он нажал их в админке.
 *
 * Персональные данные. Серверы Telegram — за рубежом, а передача туда
 * имён и телефонов гостей — трансграничная передача (нужно уведомление в
 * Роскомнадзор). Поэтому по умолчанию в сообщениях нет имени, телефона,
 * почты и комментария гостя — только номер брони, даты, номер комнаты и
 * сумма, а подробности — по кнопке в админке. Включить имена и телефоны:
 * config/secrets.php → telegram_personal_data = true (после уведомления РКН).
 *
 * События от Telegram приходят на /api/telegram.php (вебхук, нужен HTTPS)
 * или забираются опросом: php tools/telegram.php poll — для своего
 * компьютера; без вебхука их забирают и фоновые задачи (раз в 10–15 минут).
 */

const TG_UPDATE_TYPES = ['message', 'callback_query'];
const TG_LINK_TTL = 15 * 60;
const TG_LINK_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

function tg_token(): string
{
    return trim((string) hotel_secrets()['telegram_bot_token']);
}

/** Включён ли бот (config/secrets.php → telegram_bot_token). */
function tg_enabled(): bool
{
    return tg_token() !== '' || tg_transport() === 'log';
}

/** 'api' — настоящий Telegram; 'log' — для тестов: запросы пишутся в storage/telegram.log. */
function tg_transport(): string
{
    return getenv('HOTEL_TG_TRANSPORT') ?: 'api';
}

/** Можно ли отправлять в Telegram имена и телефоны гостей (см. комментарий в начале файла). */
function tg_personal(): bool
{
    return !empty(hotel_secrets()['telegram_personal_data']);
}

/**
 * Вызов Bot API: [ok, result]. Не бросает исключений — бот не должен ронять
 * сайт: ошибка пишется в журнал PHP и видна владельцу в профиле.
 */
function tg_api(string $method, array $params = [], int $timeout = 8): array
{
    if (tg_transport() === 'log') {
        file_put_contents(HOTEL_ROOT . '/storage/telegram.log', json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return [true, $method === 'getMe' ? ['id' => 1, 'first_name' => 'Тестовый бот', 'username' => 'test_hotel_bot'] : ($method === 'getUpdates' ? [] : true)];
    }
    if (tg_token() === '') {
        return [false, 'Не задан telegram_bot_token в config/secrets.php'];
    }
    $base = rtrim((string) (hotel_secrets()['telegram_api_base'] ?: 'https://api.telegram.org'), '/');
    $url = $base . '/bot' . tg_token() . '/' . $method;
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
    $proxy = (string) hotel_secrets()['telegram_proxy'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy); // например socks5h://логин:пароль@адрес:порт
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch); // curl_close() в PHP 8.5 устарела — соединение закрывается само
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $err = $raw === false ? 'нет связи' : '';
    }
    if ($raw === false || $raw === '') {
        tg_error('нет связи с Telegram: ' . $err . ($proxy === '' ? ' (если хостинг в России — попробуйте telegram_proxy)' : ''));
        return [false, $err];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        $desc = is_array($data) ? (string) ($data['description'] ?? 'ошибка') : mb_substr((string) $raw, 0, 200);
        tg_error($desc . ' (' . $method . ')');
        return [false, $desc];
    }
    return [true, $data['result'] ?? true];
}

function tg_error(string $message): void
{
    // Токен в адресе — в журнал его не пишем.
    $message = str_replace(tg_token() !== '' ? tg_token() : "\0", '***', $message);
    error_log('hotel telegram: ' . $message);
    if (function_exists('db_ready') && db_ready()) {
        set_setting('tg_last_error', date('d.m H:i') . ' — ' . mb_substr($message, 0, 250));
    }
}

/** Имя и адрес бота (getMe), запоминаются в настройках. */
function tg_bot_info(bool $refresh = false): ?array
{
    if (!tg_enabled()) {
        return null;
    }
    if (!$refresh && setting('tg_bot_username')) {
        return ['name' => (string) setting('tg_bot_name'), 'username' => (string) setting('tg_bot_username')];
    }
    [$ok, $me] = tg_api('getMe');
    if (!$ok || empty($me['username'])) {
        return null;
    }
    set_setting('tg_bot_name', (string) ($me['first_name'] ?? ''));
    set_setting('tg_bot_username', (string) $me['username']);
    return ['name' => (string) ($me['first_name'] ?? ''), 'username' => (string) $me['username']];
}

/** Ссылка на бота; с кодом — привязка сразу после «Запустить». */
function tg_bot_url(?string $code = null): ?string
{
    $bot = tg_bot_info();
    return $bot ? 'https://t.me/' . rawurlencode($bot['username']) . ($code ? '?start=' . rawurlencode($code) : '') : null;
}

// -------------------------------------------------------------------
// Отправка
// -------------------------------------------------------------------

/**
 * Кнопки: ряды из ['text' => …, 'payload' => …] (нажатие — событие боту)
 * или ['text' => …, 'url' => …] (ссылка; только https — другие Telegram не примет).
 */
function tg_keyboard(array $rows): ?array
{
    $rows = array_values(array_filter(array_map(fn ($row) => array_values(array_filter($row, fn ($b) => !isset($b['url']) || str_starts_with($b['url'], 'https://'))), $rows)));
    if (!$rows) {
        return null;
    }
    return ['inline_keyboard' => array_map(fn ($row) => array_map(fn ($b) => isset($b['url'])
        ? ['text' => $b['text'], 'url' => $b['url']]
        : ['text' => $b['text'], 'callback_data' => $b['payload']], $row), $rows)];
}

function tg_send(int $chatId, string $html, array $buttons = []): bool
{
    $params = ['chat_id' => $chatId, 'text' => mb_substr($html, 0, 4000), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
    if ($kb = tg_keyboard($buttons)) {
        $params['reply_markup'] = $kb;
    }
    return tg_api('sendMessage', $params)[0];
}

/**
 * Очередь сообщений: отправляются после ответа браузеру, чтобы гость не
 * ждал, пока бот достучится до Telegram. tg_flush() — отправить сейчас.
 */
function tg_queue(?array $message = null): array
{
    static $queue = [];
    static $registered = false;
    if ($message === null) {
        $out = $queue;
        $queue = [];
        return $out;
    }
    $queue[] = $message;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () {
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            tg_flush();
        });
    }
    return [];
}

function tg_flush(): int
{
    $n = 0;
    foreach (tg_queue() as [$chatId, $html, $buttons]) {
        $n += tg_send($chatId, $html, $buttons) ? 1 : 0;
    }
    return $n;
}

/** Сотрудники с подключённым Telegram и включёнными уведомлениями. */
function tg_staff(array $roles): array
{
    if (!tg_enabled()) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($roles), '?'));
    return q_all("SELECT * FROM staff WHERE is_active = 1 AND tg_notify = 1 AND tg_chat_id IS NOT NULL AND role IN ($in)", $roles);
}

function tg_to_roles(array $roles, string $html, array $buttons = []): int
{
    $list = tg_staff($roles);
    foreach ($list as $s) {
        tg_queue([(int) $s['tg_chat_id'], $html, $buttons]);
    }
    return count($list);
}

/** Ссылка на страницу админки — кнопкой, если сайт на https. */
function tg_admin_button(string $text, string $path): array
{
    return ['text' => $text, 'url' => base_url() . $path];
}

/** Строка о госте: имя и телефон — только если разрешено (см. tg_personal). */
function tg_guest_line(?string $name, ?string $phone): string
{
    return tg_personal()
        ? 'Гость: ' . e((string) $name) . ($phone ? ', ' . e($phone) : '') . "\n"
        : '';
}

// -------------------------------------------------------------------
// Уведомления
// -------------------------------------------------------------------

function tg_notify_new_booking(array $b): void
{
    $html = '<b>Новая заявка ' . e(booking_number($b)) . '</b>' . "\n"
        . e(ru_date_range($b['check_in'], $b['check_out'])) . ', ' . e(nights_label($b['nights'])) . ' · ' . e($b['type_name']) . "\n"
        . tg_guest_line($b['guest_name'], $b['guest_phone'])
        . 'Гостей: ' . (int) $b['guests_count'] . ' · ' . ($b['total_price'] !== null ? e(money($b['total_price'])) : 'сумма по договорённости') . "\n"
        . 'Связаться: ' . e(mb_strtolower(CONTACT_PREFS[$b['contact_pref']] ?? $b['contact_pref']))
        . (tg_personal() && $b['guest_comment'] ? "\n«" . e(mb_strimwidth($b['guest_comment'], 0, 300, '…')) . '»' : '')
        . (tg_personal() ? '' : "\n<i>Имя, телефон и комментарий — в админке.</i>");
    tg_to_roles(['owner', 'admin'], $html, [
        [['text' => 'Подтвердить' . ($b['guest_email'] ? ' и написать гостю' : ''), 'payload' => 'b:ok:' . $b['id']]],
        [tg_admin_button('Открыть в админке', '/admin/booking.php?id=' . $b['id'])],
    ]);
}

function tg_notify_guest_message(array $b, string $kind, string $message, ?string $newCheckOut): void
{
    $html = '<b>Гость просит: ' . e(mb_strtolower(MESSAGE_KINDS[$kind] ?? $kind)) . '</b>' . ($newCheckOut ? ' — до ' . e(ru_date($newCheckOut)) : '') . "\n"
        . e(booking_number($b)) . ', ' . e(ru_date_range($b['check_in'], $b['check_out'])) . "\n"
        . tg_guest_line($b['guest_name'], $b['guest_phone'])
        . (tg_personal() && $message !== '' ? '«' . e(mb_strimwidth($message, 0, 500, '…')) . '»' : (tg_personal() ? '' : '<i>Текст сообщения — в админке.</i>'));
    tg_to_roles(['owner', 'admin'], $html, [[tg_admin_button('Открыть бронь', '/admin/booking.php?id=' . $b['id'] . '#messages')]]);
}

function tg_notify_new_bath(array $x): void
{
    $html = '<b>Баня: новая заявка</b>' . "\n"
        . e(bath_when($x)) . ', ' . (int) $x['guests_count'] . ' ' . plural((int) $x['guests_count'], 'гость', 'гостя', 'гостей') . "\n"
        . tg_guest_line($x['guest_name'], $x['guest_phone'])
        . ($x['total_price'] !== null ? e(money($x['total_price'])) : '') . ($x['hotel_guest'] ? ' · говорит, что живёт в гостинице' : '')
        . ($x['extras_list'] ? "\n" . e(implode(', ', array_map(fn ($i) => $i['name'] . ' × ' . $i['qty'], $x['extras_list']))) : '');
    tg_to_roles(['owner', 'admin'], $html, [
        [['text' => 'Подтвердить' . ($x['guest_email'] ? ' и написать гостю' : ''), 'payload' => 'x:ok:' . $x['id']]],
        [tg_admin_button('Открыть в админке', '/admin/bath_booking.php?id=' . $x['id'])],
    ]);
}

function tg_notify_waitlist(string $phone): void
{
    tg_to_roles(['owner', 'admin'], '<b>Баня: ещё один человек просит сообщить об открытии</b>' . (tg_personal() ? "\n" . e($phone) : "\n<i>Телефон — в админке: Баня → Ждут открытия.</i>"));
}

/** Горничным — каждая уборка отдельным сообщением с кнопкой «Убрано». */
function tg_notify_cleaning(array $due): void
{
    foreach ($due as $c) {
        $html = '<b>Пора убрать: ' . e($c['place']) . '</b>' . "\n" . e(cleaning_when($c))
            . ($c['urgent'] ? "\n" . ($c['is_bath'] ? 'Следующий сеанс в ' . e($c['arrival']['time']) : 'Сегодня заезд с ' . e(hotel()['check_in'])) . ' — успеть до него' : '')
            . ($c['note'] !== '' ? "\n" . e($c['note']) : '');
        tg_to_roles(['housekeeper'], $html, [[['text' => '✓ Убрано', 'payload' => 'c:done:' . $c['id']]]]);
    }
}

/** Сводка дня для администратора: заезды, выезды, баня, заявки, уборка. */
function tg_summary_html(): string
{
    $today = today()->format('Y-m-d');
    $rows = fn (string $where, array $p) => array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE ' . $where . ' ORDER BY b.check_in, b.id', $p));
    $arrivals = $rows("b.status IN ('new', 'confirmed') AND b.check_in = ?", [$today]);
    $departures = $rows("b.status = 'checked_in' AND b.check_out = ?", [$today]);
    $new = (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'new'");
    $who = fn (array $list) => implode(', ', array_map(fn ($b) => (tg_personal() ? ($b['guest_name'] ?? 'Гость') . ' — ' : '') . ($b['room_name'] ?? $b['type_name']), $list));
    $lines = ['<b>' . e(mb_convert_case(RU_WEEKDAYS[(int) date('w')], MB_CASE_TITLE)) . ', ' . e(ru_date($today)) . '</b>'];
    $lines[] = 'Заезды: ' . count($arrivals) . ($arrivals ? ' (' . e($who($arrivals)) . ')' : '');
    $lines[] = 'Выезды: ' . count($departures) . ($departures ? ' (' . e($who($departures)) . ')' : '');
    $lines[] = 'Проживают: ' . (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'checked_in'");
    if ($new) {
        $lines[] = '<b>Новые заявки ждут ответа: ' . $new . '</b>';
    }
    if (bath_enabled()) {
        $baths = array_filter(bath_list($today, today()->modify('+1 day')->format('Y-m-d')), fn ($x) => !$x['is_block']);
        $lines[] = 'Баня: ' . ($baths ? e(implode(', ', array_map(fn ($x) => $x['time_from'] . '–' . $x['time_to'] . (tg_personal() ? ' ' . ($x['guest_name'] ?? '') : ''), $baths))) : 'сеансов нет');
    }
    if ($toClean = cleaning_due_count()) {
        $lines[] = 'Ждут уборки: ' . $toClean;
    }
    return implode("\n", $lines);
}

/** Утренняя сводка — раз в день, после 8 утра (из фоновых задач). */
function tg_morning_summary(): int
{
    $today = today()->format('Y-m-d');
    if ((int) date('G') < 8 || setting('tg_summary_date') === $today) {
        return 0;
    }
    set_setting('tg_summary_date', $today);
    return tg_to_roles(['owner', 'admin'], tg_summary_html(), [[tg_admin_button('Открыть «Сегодня»', '/admin/')]]);
}

// -------------------------------------------------------------------
// Привязка сотрудника
// -------------------------------------------------------------------

/** Новый одноразовый код для привязки Telegram (действует 15 минут). */
function tg_link_code_create(int $staffId): string
{
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= TG_LINK_ALPHABET[random_int(0, strlen(TG_LINK_ALPHABET) - 1)];
    }
    q_update('staff', $staffId, ['tg_link_code' => $code, 'tg_link_expires' => date('Y-m-d H:i:s', time() + TG_LINK_TTL)]);
    return $code;
}

function tg_unlink(int $staffId): void
{
    q_update('staff', $staffId, ['tg_user_id' => null, 'tg_chat_id' => null, 'tg_name' => '', 'tg_linked_at' => null, 'tg_link_code' => null, 'tg_link_expires' => null]);
    audit('staff', $staffId, 'tg_unlinked');
}

function tg_staff_by_user(int $userId): ?array
{
    return $userId ? q_one('SELECT * FROM staff WHERE tg_user_id = ? AND is_active = 1', [$userId]) : null;
}

/**
 * Код от сотрудника → привязать его Telegram. Не больше 5 неверных кодов в
 * час с одного аккаунта: чужой не подберёт код и не получит уведомления.
 */
function tg_try_link(array $user, int $chatId, string $code): ?array
{
    $uid = (int) ($user['id'] ?? 0);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (!$uid || !$chatId) {
        return null;
    }
    if (rate_limited('tg_link', (string) $uid, 5, 3600)) {
        tg_send($chatId, 'Слишком много попыток. Подождите час или попросите владельца проверить ваш профиль.');
        return null;
    }
    $s = $code !== '' ? q_one('SELECT * FROM staff WHERE tg_link_code = ? AND tg_link_expires > ? AND is_active = 1', [$code, now()]) : null;
    if (!$s) {
        rate_hit('tg_link', (string) $uid);
        tg_send($chatId, 'Код не подошёл или устарел. Получите новый в админке: <b>Профиль → Уведомления в Telegram</b>.');
        return null;
    }
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    // Один Telegram — один сотрудник: если этот аккаунт был привязан к другому, отвязываем там.
    q("UPDATE staff SET tg_user_id = NULL, tg_chat_id = NULL, tg_name = '', tg_linked_at = NULL WHERE tg_user_id = ? AND id <> ?", [$uid, $s['id']]);
    q_update('staff', (int) $s['id'], [
        'tg_user_id' => $uid, 'tg_chat_id' => $chatId, 'tg_name' => mb_substr($name, 0, 100),
        'tg_link_code' => null, 'tg_link_expires' => null, 'tg_linked_at' => now(), 'tg_notify' => 1,
    ]);
    rate_clear('tg_link', (string) $uid);
    audit('staff', (int) $s['id'], 'tg_linked', $name !== '' ? $name : null, 'system');
    $what = $s['role'] === 'housekeeper'
        ? 'когда пора убрать номер или баню — с кнопкой «Убрано». Список уборок на сейчас — команда /clean.'
        : 'новые заявки (с кнопкой «Подтвердить»), заявки в баню, сообщения гостей и утренняя сводка дня. Сводка в любой момент — команда /today.';
    tg_send($chatId, 'Готово, ' . e($s['name']) . '! Telegram подключён к админке «' . e(mail_hotel_name()) . '».' . "\n\nСюда будут приходить " . $what);
    return $s;
}

// -------------------------------------------------------------------
// События от Telegram
// -------------------------------------------------------------------

/** Проверка вебхука: Telegram присылает секрет в заголовке X-Telegram-Bot-Api-Secret-Token. */
function tg_webhook_authorized(?string $header): bool
{
    $secret = (string) hotel_secrets()['telegram_webhook_secret'];
    return $secret !== '' && is_string($header) && hash_equals($secret, $header);
}

function tg_handle_update(array $u): void
{
    if (isset($u['callback_query'])) {
        tg_handle_callback($u['callback_query']);
        return;
    }
    $m = $u['message'] ?? null;
    if (!$m || ($m['chat']['type'] ?? '') !== 'private' || !empty($m['from']['is_bot'])) {
        return; // бот работает только в личной переписке
    }
    $chatId = (int) $m['chat']['id'];
    $from = $m['from'] ?? [];
    $text = trim((string) ($m['text'] ?? ''));
    $staff = tg_staff_by_user((int) ($from['id'] ?? 0));
    $cmd = mb_strtolower(preg_replace('/@\S+$/', '', strtok($text, ' ') ?: ''));
    $arg = trim((string) mb_substr($text, mb_strlen(strtok($text, ' ') ?: '')));

    if ($cmd === '/start' && $arg !== '') {
        tg_try_link($from, $chatId, $arg);
    } elseif (preg_match('/^[A-Za-z0-9]{8}$/', $text)) {
        tg_try_link($from, $chatId, $text);
    } elseif ($staff && in_array($cmd, ['/today', 'сегодня', 'сводка'], true) && $staff['role'] !== 'housekeeper') {
        tg_send($chatId, tg_summary_html(), [[tg_admin_button('Открыть «Сегодня»', '/admin/')]]);
    } elseif ($staff && in_array($cmd, ['/clean', 'уборка'], true)) {
        tg_send_cleaning_list($chatId);
    } else {
        tg_send($chatId, tg_help_text($staff));
    }
}

function tg_help_text(?array $staff): string
{
    if (!$staff) {
        return 'Здравствуйте! Это бот для сотрудников «' . e(mail_hotel_name()) . '».' . "\n\n"
            . 'Чтобы получать уведомления, откройте админку → <b>Профиль → Уведомления в Telegram</b>, нажмите «Подключить» и отправьте сюда код из 8 символов.';
    }
    return 'Здравствуйте, ' . e($staff['name']) . '! Команды:' . "\n"
        . ($staff['role'] !== 'housekeeper' ? '/today — сводка дня' . "\n" : '')
        . '/clean — что ждёт уборки сейчас' . "\n\n"
        . 'Уведомления выключаются в админке: Профиль → Уведомления в Telegram.';
}

function tg_send_cleaning_list(int $chatId): void
{
    $due = array_values(array_filter(cleaning_list(today()->format('Y-m-d')), fn ($c) => $c['is_due']));
    if (!$due) {
        tg_send($chatId, 'Сейчас убирать нечего.');
        return;
    }
    $rows = array_map(fn ($c) => [['text' => '✓ ' . $c['place'], 'payload' => 'c:done:' . $c['id']]], array_slice($due, 0, 10));
    tg_send($chatId, '<b>Ждут уборки: ' . count($due) . '</b>' . "\n" . e(implode("\n", array_map(fn ($c) => $c['place'] . ' — ' . mb_strtolower(cleaning_when($c)), $due)))
        . "\n\nУбрали — нажмите на кнопку.", $rows);
}

/**
 * Ответ на нажатие: всплывающая подсказка и, если передан новый текст,
 * правка самого сообщения — кнопки убираются, чтобы не нажать дважды.
 */
function tg_answer(array $cb, string $notification, ?string $newHtml = null): void
{
    tg_api('answerCallbackQuery', ['callback_query_id' => (string) $cb['id'], 'text' => mb_substr($notification, 0, 190)]);
    $msg = $cb['message'] ?? null;
    if ($newHtml !== null && $msg) {
        tg_api('editMessageText', ['chat_id' => (int) $msg['chat']['id'], 'message_id' => (int) $msg['message_id'],
            'text' => mb_substr($newHtml, 0, 4000), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
    }
}

/** Нажали кнопку: действуем от имени привязанного сотрудника, с его правами. */
function tg_handle_callback(array $cb): void
{
    if (empty($cb['id'])) {
        return;
    }
    $staff = tg_staff_by_user((int) ($cb['from']['id'] ?? 0));
    if (!$staff) {
        tg_answer($cb, 'Сначала подключите Telegram в админке: Профиль → Уведомления в Telegram');
        return;
    }
    $orig = e(trim((string) ($cb['message']['text'] ?? '')));
    [$kind, $action, $id] = explode(':', (string) ($cb['data'] ?? '')) + ['', '', '0'];
    $id = (int) $id;
    act_as_staff($staff);
    try {
        if (in_array($kind, ['b', 'x'], true) && $staff['role'] === 'housekeeper') {
            throw new BookingError('Подтверждать брони может администратор');
        }
        if ($kind === 'b' && $action === 'ok') {
            $b = booking_get($id);
            if (!$b) {
                throw new BookingError('Бронь не найдена');
            }
            if ($b['status'] !== 'new') {
                tg_answer($cb, 'Уже: ' . mb_strtolower($b['status_label']), $orig . "\n\n<i>Статус: " . e(mb_strtolower($b['status_label'])) . '</i>');
                return;
            }
            $b = booking_set_status($id, 'confirmed');
            $sent = tg_mail_guest($b['guest_email'] ?? '', 'booking', $b, mail_booking_confirmed($b));
            tg_answer($cb, 'Бронь ' . booking_number($b) . ' подтверждена' . ($sent ? ', гостю ушло письмо' : ''),
                $orig . "\n\n<b>✓ Подтверждена</b> — " . e($staff['name']) . ($sent ? ', гостю ушло письмо' : ''));
        } elseif ($kind === 'x' && $action === 'ok') {
            $x = bath_get($id);
            if (!$x) {
                throw new BookingError('Запись не найдена');
            }
            if ($x['status'] !== 'new') {
                tg_answer($cb, 'Уже: ' . mb_strtolower($x['status_label']), $orig . "\n\n<i>Статус: " . e(mb_strtolower($x['status_label'])) . '</i>');
                return;
            }
            $x = bath_set_status($id, 'confirmed');
            $sent = tg_mail_guest($x['guest_email'] ?? '', 'bath', $x, mail_bath_confirmed($x));
            tg_answer($cb, 'Запись в баню подтверждена' . ($sent ? ', гостю ушло письмо' : ''),
                $orig . "\n\n<b>✓ Подтверждена</b> — " . e($staff['name']) . ($sent ? ', гостю ушло письмо' : ''));
        } elseif ($kind === 'c' && $action === 'done') {
            cleaning_mark($id, 'done');
            tg_answer($cb, 'Отмечено: убрано. Спасибо!', $orig . "\n\n<b>✓ Убрано</b> — " . e($staff['name']) . ', ' . date('H:i'));
        } else {
            tg_answer($cb, 'Эта кнопка устарела — откройте админку');
        }
    } catch (BookingError $e) {
        tg_answer($cb, $e->getMessage());
    } finally {
        act_as_staff(null);
    }
}

/** Письмо гостю после подтверждения кнопкой — как при подтверждении в админке. */
function tg_mail_guest(string $email, string $entity, array $item, array $mail): bool
{
    if ($email === '') {
        return false;
    }
    [$subject, $text] = $mail;
    $ok = send_mail($email, $subject, $text);
    audit($entity, (int) $item['id'], 'mail', ($ok ? 'отправлено письмо: ' : 'НЕ отправлено письмо: ') . $subject);
    return $ok;
}

/**
 * Забрать события без вебхука (getUpdates). $timeout — сколько секунд ждать
 * новых событий (0 — только то, что уже есть). Возвращает число обработанных
 * событий или -1, если Telegram не ответил.
 */
function tg_poll(int $timeout = 0): int
{
    $params = ['timeout' => $timeout, 'limit' => 100, 'allowed_updates' => TG_UPDATE_TYPES];
    if (setting('tg_offset')) {
        $params['offset'] = (int) setting('tg_offset');
    }
    [$ok, $updates] = tg_api('getUpdates', $params, $timeout + 10);
    if (!$ok) {
        return -1;
    }
    $n = 0;
    foreach (is_array($updates) ? $updates : [] as $u) {
        // Сначала сдвигаем отметку: упавшее событие не будет приходить снова и снова.
        set_setting('tg_offset', (string) ((int) $u['update_id'] + 1));
        try {
            tg_handle_update($u);
        } catch (Throwable $e) {
            error_log('hotel telegram update: ' . $e->getMessage());
        }
        $n++;
    }
    tg_flush();
    return $n;
}
