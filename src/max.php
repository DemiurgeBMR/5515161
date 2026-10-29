<?php
/**
 * Бот в мессенджере MAX: уведомления сотрудникам и кнопки прямо в чате.
 *
 *  • Администраторам и владельцу — новая заявка (кнопка «Подтвердить»),
 *    заявка в баню, сообщение гостя, лист ожидания, утренняя сводка дня.
 *  • Горничным — «пора убрать номер» с кнопкой «Убрано».
 *
 * Сотрудник подключает свой MAX сам: Профиль → MAX → «Подключить» —
 * получает одноразовый код и отправляет его боту (или открывает ссылку).
 * Кнопки работают от имени этого сотрудника: с теми же правами и записью
 * в журнал, как если бы он нажал их в админке.
 *
 * События от MAX приходят на /api/max.php (вебхук, нужен HTTPS) или
 * забираются опросом: php tools/max.php poll — для своего компьютера;
 * без вебхука их забирают и фоновые задачи (раз в 10–15 минут).
 *
 * Сервера MAX — в России, поэтому администраторам приходят имя и телефон
 * гостя. Горничным — только номер и время, как и в их кабинете.
 */

const MAX_API = 'https://platform-api2.max.ru';
const MAX_UPDATE_TYPES = ['message_created', 'message_callback', 'bot_started'];
const MAX_LINK_TTL = 15 * 60;
const MAX_LINK_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/** Задан ли токен бота (config/secrets.php → max_bot_token). */
function max_enabled(): bool
{
    return trim((string) hotel_secrets()['max_bot_token']) !== '' || max_transport() === 'log';
}

/** 'api' — настоящий MAX; 'log' — для тестов: запросы пишутся в storage/max.log. */
function max_transport(): string
{
    return getenv('HOTEL_MAX_TRANSPORT') ?: 'api';
}

/**
 * Запрос к MAX Bot API: [код ответа, данные]. Не бросает исключений —
 * бот не должен ронять сайт: ошибка пишется в журнал PHP и видна в профиле.
 */
function max_api(string $method, string $path, array $query = [], ?array $body = null, int $timeout = 8): array
{
    if (max_transport() === 'log') {
        file_put_contents(HOTEL_ROOT . '/storage/max.log', json_encode(['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return [200, $path === 'me' ? ['user_id' => 1, 'name' => 'Тестовый бот', 'username' => 'test_hotel_bot'] : ['success' => true, 'updates' => [], 'marker' => null]];
    }
    $token = trim((string) hotel_secrets()['max_bot_token']);
    if ($token === '') {
        return [0, ['message' => 'Не задан max_bot_token в config/secrets.php']];
    }
    $url = MAX_API . '/' . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: ' . $token, 'Accept: application/json'];
    $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch); // curl_close() в PHP 8.5 устарела — соединение закрывается само
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => (string) $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        $err = $raw === false ? 'нет связи' : '';
    }
    if ($raw === false || $code === 0) {
        max_error('нет связи с MAX: ' . $err);
        return [0, ['message' => $err]];
    }
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : [];
    if ($code >= 400) {
        max_error($code . ' ' . ($data['message'] ?? $data['code'] ?? mb_substr((string) $raw, 0, 200)) . ' (' . $method . ' /' . $path . ')');
    }
    return [$code, $data];
}

function max_error(string $message): void
{
    error_log('hotel max: ' . $message);
    if (function_exists('db_ready') && db_ready()) {
        set_setting('max_last_error', date('d.m H:i') . ' — ' . mb_substr($message, 0, 250));
    }
}

/** Имя и адрес бота (из GET /me), запоминаются в настройках. */
function max_bot_info(bool $refresh = false): ?array
{
    if (!max_enabled()) {
        return null;
    }
    if (!$refresh && setting('max_bot_username')) {
        return ['name' => (string) setting('max_bot_name'), 'username' => (string) setting('max_bot_username')];
    }
    [$code, $data] = max_api('GET', 'me');
    if ($code !== 200 || empty($data['username'])) {
        return null;
    }
    set_setting('max_bot_name', (string) ($data['first_name'] ?? $data['name'] ?? ''));
    set_setting('max_bot_username', (string) $data['username']);
    return ['name' => (string) setting('max_bot_name'), 'username' => (string) $data['username']];
}

/** Ссылка, по которой сотрудник открывает бота (с кодом — привязка сразу). */
function max_bot_url(?string $code = null): ?string
{
    $bot = max_bot_info();
    return $bot ? 'https://max.ru/' . rawurlencode($bot['username']) . ($code ? '?start=' . rawurlencode($code) : '') : null;
}

// -------------------------------------------------------------------
// Отправка
// -------------------------------------------------------------------

/**
 * Кнопки: ряды из ['text' => …, 'payload' => …] (нажатие — событие боту)
 * или ['text' => …, 'url' => …] (ссылка; только https — MAX другие не примет).
 */
function max_keyboard(array $rows): array
{
    $rows = array_values(array_filter(array_map(fn ($row) => array_values(array_filter($row, fn ($b) => !isset($b['url']) || str_starts_with($b['url'], 'https://'))), $rows)));
    return ['type' => 'inline_keyboard', 'payload' => ['buttons' => array_map(fn ($row) => array_map(fn ($b) => isset($b['url'])
        ? ['type' => 'link', 'text' => $b['text'], 'url' => $b['url']]
        : ['type' => 'callback', 'text' => $b['text'], 'payload' => $b['payload']], $row), $rows)]];
}

/** Тело сообщения: текст с простой разметкой (<b>, <i>) и кнопки. */
function max_body(string $html, array $buttons = []): array
{
    $body = ['text' => mb_substr($html, 0, 3900), 'format' => 'html'];
    $kb = $buttons ? max_keyboard($buttons) : null;
    $body['attachments'] = $kb && $kb['payload']['buttons'] ? [$kb] : [];
    return $body;
}

function max_send(int $chatId, string $html, array $buttons = []): bool
{
    [$code] = max_api('POST', 'messages', ['chat_id' => $chatId], max_body($html, $buttons));
    return $code === 200;
}

/**
 * Очередь сообщений: отправляются после ответа браузеру, чтобы гость не
 * ждал, пока бот достучится до MAX. max_flush() — отправить сейчас.
 */
function max_queue(?array $message = null): array
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
            max_flush();
        });
    }
    return [];
}

function max_flush(): int
{
    $n = 0;
    foreach (max_queue() as [$chatId, $html, $buttons]) {
        $n += max_send($chatId, $html, $buttons) ? 1 : 0;
    }
    return $n;
}

/** Сотрудники с подключённым MAX и включёнными уведомлениями. */
function max_staff(array $roles): array
{
    if (!max_enabled()) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($roles), '?'));
    return q_all("SELECT * FROM staff WHERE is_active = 1 AND max_notify = 1 AND max_chat_id IS NOT NULL AND role IN ($in)", $roles);
}

function max_to_roles(array $roles, string $html, array $buttons = []): int
{
    $list = max_staff($roles);
    foreach ($list as $s) {
        max_queue([(int) $s['max_chat_id'], $html, $buttons]);
    }
    return count($list);
}

/** Ссылка на страницу админки — кнопкой, если сайт на https. */
function max_admin_button(string $text, string $path): array
{
    return ['text' => $text, 'url' => base_url() . $path];
}

// -------------------------------------------------------------------
// Уведомления
// -------------------------------------------------------------------

function max_notify_new_booking(array $b): void
{
    $html = '<b>Новая заявка ' . e(booking_number($b)) . '</b>' . "\n"
        . e(ru_date_range($b['check_in'], $b['check_out'])) . ', ' . e(nights_label($b['nights'])) . ' · ' . e($b['type_name']) . "\n"
        . 'Гость: ' . e($b['guest_name']) . ($b['guest_phone'] ? ', ' . e($b['guest_phone']) : '') . "\n"
        . 'Гостей: ' . (int) $b['guests_count'] . ' · ' . ($b['total_price'] !== null ? e(money($b['total_price'])) : 'сумма по договорённости') . "\n"
        . 'Связаться: ' . e(mb_strtolower(CONTACT_PREFS[$b['contact_pref']] ?? $b['contact_pref']))
        . ($b['guest_comment'] ? "\n«" . e(mb_strimwidth($b['guest_comment'], 0, 300, '…')) . '»' : '');
    max_to_roles(['owner', 'admin'], $html, [
        [['text' => 'Подтвердить' . ($b['guest_email'] ? ' и написать гостю' : ''), 'payload' => 'b:ok:' . $b['id']]],
        [max_admin_button('Открыть в админке', '/admin/booking.php?id=' . $b['id'])],
    ]);
}

function max_notify_guest_message(array $b, string $kind, string $message, ?string $newCheckOut): void
{
    $html = '<b>Гость просит: ' . e(mb_strtolower(MESSAGE_KINDS[$kind] ?? $kind)) . '</b>' . ($newCheckOut ? ' — до ' . e(ru_date($newCheckOut)) : '') . "\n"
        . e(booking_number($b)) . ', ' . e(ru_date_range($b['check_in'], $b['check_out'])) . "\n"
        . e($b['guest_name']) . ($b['guest_phone'] ? ', ' . e($b['guest_phone']) : '')
        . ($message !== '' ? "\n«" . e(mb_strimwidth($message, 0, 500, '…')) . '»' : '');
    max_to_roles(['owner', 'admin'], $html, [[max_admin_button('Открыть бронь', '/admin/booking.php?id=' . $b['id'] . '#messages')]]);
}

function max_notify_new_bath(array $x): void
{
    $html = '<b>Баня: новая заявка</b>' . "\n"
        . e(bath_when($x)) . ', ' . (int) $x['guests_count'] . ' ' . plural((int) $x['guests_count'], 'гость', 'гостя', 'гостей') . "\n"
        . e($x['guest_name']) . ($x['guest_phone'] ? ', ' . e($x['guest_phone']) : '') . "\n"
        . ($x['total_price'] !== null ? e(money($x['total_price'])) : '') . ($x['hotel_guest'] ? ' · говорит, что живёт в гостинице' : '')
        . ($x['extras_list'] ? "\n" . e(implode(', ', array_map(fn ($e) => $e['name'] . ' × ' . $e['qty'], $x['extras_list']))) : '')
        . ($x['guest_comment'] ? "\n«" . e(mb_strimwidth($x['guest_comment'], 0, 300, '…')) . '»' : '');
    max_to_roles(['owner', 'admin'], $html, [
        [['text' => 'Подтвердить' . ($x['guest_email'] ? ' и написать гостю' : ''), 'payload' => 'x:ok:' . $x['id']]],
        [max_admin_button('Открыть в админке', '/admin/bath_booking.php?id=' . $x['id'])],
    ]);
}

function max_notify_waitlist(string $phone): void
{
    max_to_roles(['owner', 'admin'], '<b>Баня: просят сообщить об открытии</b>' . "\n" . e($phone));
}

/** Горничным — каждая уборка отдельным сообщением с кнопкой «Убрано». */
function max_notify_cleaning(array $due): void
{
    foreach ($due as $c) {
        $html = '<b>Пора убрать: ' . e($c['place']) . '</b>' . "\n" . e(cleaning_when($c))
            . ($c['urgent'] ? "\n" . ($c['is_bath'] ? 'Следующий сеанс в ' . e($c['arrival']['time']) : 'Сегодня заезд с ' . e(hotel()['check_in'])) . ' — успеть до него' : '')
            . ($c['note'] !== '' ? "\n" . e($c['note']) : '');
        max_to_roles(['housekeeper'], $html, [[['text' => '✓ Убрано', 'payload' => 'c:done:' . $c['id']]]]);
    }
}

/** Сводка дня для администратора: заезды, выезды, баня, заявки, уборка. */
function max_summary_html(): string
{
    $today = today()->format('Y-m-d');
    $rows = fn (string $where, array $p) => array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE ' . $where . ' ORDER BY b.check_in, b.id', $p));
    $arrivals = $rows("b.status IN ('new', 'confirmed') AND b.check_in = ?", [$today]);
    $departures = $rows("b.status = 'checked_in' AND b.check_out = ?", [$today]);
    $new = (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'new'");
    $who = fn (array $list) => implode(', ', array_map(fn ($b) => ($b['guest_name'] ?? 'Гость') . ' (' . ($b['room_name'] ?? $b['type_name']) . ')', $list));
    $lines = ['<b>' . e(mb_convert_case(RU_WEEKDAYS[(int) date('w')], MB_CASE_TITLE)) . ', ' . e(ru_date($today)) . '</b>'];
    $lines[] = 'Заезды: ' . count($arrivals) . ($arrivals ? ' — ' . e($who($arrivals)) : '');
    $lines[] = 'Выезды: ' . count($departures) . ($departures ? ' — ' . e($who($departures)) : '');
    $lines[] = 'Проживают: ' . (int) q_val("SELECT COUNT(*) FROM bookings WHERE status = 'checked_in'");
    if ($new) {
        $lines[] = '<b>Новые заявки ждут ответа: ' . $new . '</b>';
    }
    if (bath_enabled()) {
        $baths = array_filter(bath_list($today, today()->modify('+1 day')->format('Y-m-d')), fn ($x) => !$x['is_block']);
        $lines[] = 'Баня: ' . ($baths ? implode(', ', array_map(fn ($x) => e($x['time_from'] . '–' . $x['time_to'] . ' ' . ($x['guest_name'] ?? '')), $baths)) : 'сеансов нет');
    }
    $toClean = cleaning_due_count();
    if ($toClean) {
        $lines[] = 'Ждут уборки: ' . $toClean;
    }
    return implode("\n", $lines);
}

/** Утренняя сводка — раз в день, после 8 утра (из фоновых задач). */
function max_morning_summary(): int
{
    $today = today()->format('Y-m-d');
    if ((int) date('G') < 8 || setting('max_summary_date') === $today) {
        return 0;
    }
    set_setting('max_summary_date', $today);
    return max_to_roles(['owner', 'admin'], max_summary_html(), [[max_admin_button('Открыть «Сегодня»', '/admin/')]]);
}

// -------------------------------------------------------------------
// Привязка сотрудника
// -------------------------------------------------------------------

/** Новый одноразовый код для привязки MAX (действует 15 минут). */
function max_link_code_create(int $staffId): string
{
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= MAX_LINK_ALPHABET[random_int(0, strlen(MAX_LINK_ALPHABET) - 1)];
    }
    q_update('staff', $staffId, ['max_link_code' => $code, 'max_link_expires' => date('Y-m-d H:i:s', time() + MAX_LINK_TTL)]);
    return $code;
}

function max_unlink(int $staffId): void
{
    q_update('staff', $staffId, ['max_user_id' => null, 'max_chat_id' => null, 'max_name' => '', 'max_linked_at' => null, 'max_link_code' => null, 'max_link_expires' => null]);
    audit('staff', $staffId, 'max_unlinked');
}

function max_staff_by_user(int $userId): ?array
{
    return $userId ? q_one('SELECT * FROM staff WHERE max_user_id = ? AND is_active = 1', [$userId]) : null;
}

/**
 * Код от сотрудника → привязать его MAX. Не больше 5 неверных кодов в час
 * с одного аккаунта MAX: чужой не подберёт код и не получит уведомления.
 */
function max_try_link(array $user, int $chatId, string $code): ?array
{
    $uid = (int) ($user['user_id'] ?? 0);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (!$uid || !$chatId) {
        return null;
    }
    if (rate_limited('max_link', (string) $uid, 5, 3600)) {
        max_send($chatId, 'Слишком много попыток. Подождите час или попросите владельца проверить ваш профиль.');
        return null;
    }
    $s = $code !== '' ? q_one('SELECT * FROM staff WHERE max_link_code = ? AND max_link_expires > ? AND is_active = 1', [$code, now()]) : null;
    if (!$s) {
        rate_hit('max_link', (string) $uid);
        max_send($chatId, 'Код не подошёл или устарел. Получите новый в админке: <b>Профиль → Уведомления в MAX</b>.');
        return null;
    }
    $name = trim(($user['first_name'] ?? $user['name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    // Один MAX — один сотрудник: если этот аккаунт был привязан к другому, отвязываем там.
    q("UPDATE staff SET max_user_id = NULL, max_chat_id = NULL, max_name = '', max_linked_at = NULL WHERE max_user_id = ? AND id <> ?", [$uid, $s['id']]);
    q_update('staff', (int) $s['id'], [
        'max_user_id' => $uid, 'max_chat_id' => $chatId, 'max_name' => mb_substr($name, 0, 100),
        'max_link_code' => null, 'max_link_expires' => null, 'max_linked_at' => now(), 'max_notify' => 1,
    ]);
    rate_clear('max_link', (string) $uid);
    audit('staff', (int) $s['id'], 'max_linked', $name !== '' ? $name : null, 'system');
    $what = $s['role'] === 'housekeeper'
        ? 'когда пора убрать номер или баню — с кнопкой «Убрано». Список уборок на сейчас — команда <b>уборка</b>.'
        : 'новые заявки (с кнопкой «Подтвердить»), заявки в баню, сообщения гостей и утренняя сводка дня. Сводка в любой момент — команда <b>сегодня</b>.';
    max_send($chatId, 'Готово, ' . e($s['name']) . '! MAX подключён к админке «' . e(mail_hotel_name()) . '».' . "\n\nСюда будут приходить " . $what);
    return $s;
}

// -------------------------------------------------------------------
// События от MAX
// -------------------------------------------------------------------

/** Проверка вебхука: MAX присылает секрет в заголовке X-Max-Bot-Api-Secret. */
function max_webhook_authorized(?string $header): bool
{
    $secret = (string) hotel_secrets()['max_webhook_secret'];
    return $secret !== '' && is_string($header) && hash_equals($secret, $header);
}

function max_handle_update(array $u): void
{
    switch ($u['update_type'] ?? '') {
        case 'bot_started':
            $chatId = (int) ($u['chat_id'] ?? 0);
            $payload = (string) ($u['payload'] ?? '');
            if ($payload !== '') {
                max_try_link($u['user'] ?? [], $chatId, $payload);
            } else {
                max_send($chatId, max_help_text(max_staff_by_user((int) ($u['user']['user_id'] ?? 0))));
            }
            break;
        case 'message_created':
            $m = $u['message'] ?? [];
            $sender = $m['sender'] ?? [];
            $chatId = (int) ($m['recipient']['chat_id'] ?? 0);
            if (!empty($sender['is_bot']) || ($m['recipient']['chat_type'] ?? 'dialog') !== 'dialog' || !$chatId) {
                return; // бот работает только в личной переписке
            }
            $text = trim((string) ($m['body']['text'] ?? ''));
            $staff = max_staff_by_user((int) ($sender['user_id'] ?? 0));
            $cmd = mb_strtolower(ltrim($text, '/'));
            if (preg_match('/^[A-Za-z0-9]{8}$/', $text)) {
                max_try_link($sender, $chatId, $text);
            } elseif ($staff && in_array($cmd, ['сегодня', 'today', 'сводка'], true) && $staff['role'] !== 'housekeeper') {
                max_send($chatId, max_summary_html(), [[max_admin_button('Открыть «Сегодня»', '/admin/')]]);
            } elseif ($staff && in_array($cmd, ['уборка', 'clean', 'cleaning'], true)) {
                max_send_cleaning_list($chatId);
            } else {
                max_send($chatId, max_help_text($staff));
            }
            break;
        case 'message_callback':
            max_handle_callback($u['callback'] ?? [], $u['message'] ?? null);
            break;
    }
}

function max_help_text(?array $staff): string
{
    if (!$staff) {
        return 'Здравствуйте! Это бот для сотрудников «' . e(mail_hotel_name()) . '».' . "\n\n"
            . 'Чтобы получать уведомления, откройте админку → <b>Профиль → Уведомления в MAX</b>, нажмите «Подключить» и отправьте сюда код из 8 символов.';
    }
    return 'Здравствуйте, ' . e($staff['name']) . '! Команды:' . "\n"
        . ($staff['role'] !== 'housekeeper' ? '<b>сегодня</b> — сводка дня' . "\n" : '')
        . '<b>уборка</b> — что ждёт уборки сейчас' . "\n\n"
        . 'Уведомления выключаются в админке: Профиль → Уведомления в MAX.';
}

function max_send_cleaning_list(int $chatId): void
{
    $due = array_values(array_filter(cleaning_list(today()->format('Y-m-d')), fn ($c) => $c['is_due']));
    if (!$due) {
        max_send($chatId, 'Сейчас убирать нечего.');
        return;
    }
    $rows = array_map(fn ($c) => [['text' => '✓ ' . $c['place'], 'payload' => 'c:done:' . $c['id']]], array_slice($due, 0, 10));
    $text = '<b>Ждут уборки: ' . count($due) . '</b>' . "\n" . e(implode("\n", array_map(fn ($c) => $c['place'] . ' — ' . mb_strtolower(cleaning_when($c)), $due)))
        . "\n\nУбрали — нажмите на кнопку.";
    max_send($chatId, $text, $rows);
}

/** Ответ на нажатие кнопки: всплывающее уведомление и, если передано, новый текст сообщения (без кнопок). */
function max_answer(string $callbackId, string $notification, ?string $newHtml = null): void
{
    $body = ['notification' => mb_substr($notification, 0, 200)];
    if ($newHtml !== null) {
        $body['message'] = max_body($newHtml);
    }
    max_api('POST', 'answers', ['callback_id' => $callbackId], $body);
}

/** Нажали кнопку: действуем от имени привязанного сотрудника, с его правами. */
function max_handle_callback(array $cb, ?array $message): void
{
    $callbackId = (string) ($cb['callback_id'] ?? '');
    $staff = max_staff_by_user((int) ($cb['user']['user_id'] ?? 0));
    if ($callbackId === '') {
        return;
    }
    if (!$staff) {
        max_answer($callbackId, 'Сначала подключите MAX в админке: Профиль → Уведомления в MAX');
        return;
    }
    $orig = e(trim((string) ($message['body']['text'] ?? '')));
    [$kind, $action, $id] = explode(':', (string) ($cb['payload'] ?? '')) + ['', '', '0'];
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
                max_answer($callbackId, 'Уже: ' . mb_strtolower($b['status_label']), $orig . "\n\n<i>Статус: " . e(mb_strtolower($b['status_label'])) . '</i>');
                return;
            }
            $b = booking_set_status($id, 'confirmed');
            $sent = max_mail_guest($b['guest_email'] ?? '', 'booking', $b, mail_booking_confirmed($b));
            max_answer($callbackId, 'Бронь ' . booking_number($b) . ' подтверждена' . ($sent ? ', гостю ушло письмо' : ''),
                $orig . "\n\n<b>✓ Подтверждена</b> — " . e($staff['name']) . ($sent ? ', гостю ушло письмо' : ''));
        } elseif ($kind === 'x' && $action === 'ok') {
            $x = bath_get($id);
            if (!$x) {
                throw new BookingError('Запись не найдена');
            }
            if ($x['status'] !== 'new') {
                max_answer($callbackId, 'Уже: ' . mb_strtolower($x['status_label']), $orig . "\n\n<i>Статус: " . e(mb_strtolower($x['status_label'])) . '</i>');
                return;
            }
            $x = bath_set_status($id, 'confirmed');
            $sent = max_mail_guest($x['guest_email'] ?? '', 'bath', $x, mail_bath_confirmed($x));
            max_answer($callbackId, 'Запись в баню подтверждена' . ($sent ? ', гостю ушло письмо' : ''),
                $orig . "\n\n<b>✓ Подтверждена</b> — " . e($staff['name']) . ($sent ? ', гостю ушло письмо' : ''));
        } elseif ($kind === 'c' && $action === 'done') {
            cleaning_mark($id, 'done');
            max_answer($callbackId, 'Отмечено: убрано. Спасибо!', $orig . "\n\n<b>✓ Убрано</b> — " . e($staff['name']) . ', ' . date('H:i'));
        } else {
            max_answer($callbackId, 'Эта кнопка устарела — откройте админку');
        }
    } catch (BookingError $e) {
        max_answer($callbackId, $e->getMessage());
    } finally {
        act_as_staff(null);
    }
}

/** Письмо гостю после подтверждения кнопкой — как при подтверждении в админке. */
function max_mail_guest(string $email, string $entity, array $item, array $mail): bool
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
 * Забрать события без вебхука (GET /updates). $timeout — сколько секунд
 * ждать новых событий (0 — только то, что уже есть). Возвращает число
 * обработанных событий или -1, если MAX не ответил.
 */
function max_poll(int $timeout = 0): int
{
    $query = ['types' => implode(',', MAX_UPDATE_TYPES), 'limit' => 100, 'timeout' => $timeout];
    if (setting('max_marker')) {
        $query['marker'] = setting('max_marker');
    }
    [$code, $data] = max_api('GET', 'updates', $query, null, $timeout + 10);
    if ($code !== 200) {
        return -1;
    }
    $n = 0;
    foreach ($data['updates'] ?? [] as $u) {
        try {
            max_handle_update($u);
        } catch (Throwable $e) {
            error_log('hotel max update: ' . $e->getMessage());
        }
        $n++;
    }
    if (!empty($data['marker'])) {
        set_setting('max_marker', (string) $data['marker']);
    }
    max_flush();
    return $n;
}
