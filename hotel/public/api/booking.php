<?php
/**
 * Приём заявок с сайта: бронирование и «сообщить об открытии бани».
 *
 * Заявка уходит администратору на почту и/или в Telegram (config/secrets.php).
 * На сервере ничего не сохраняется — персональные данные гостей не копятся
 * в файлах, о которых потом все забудут.
 *
 * Отвечает JSON, если его просили (форма с JS), иначе — редиректом обратно
 * на главную с результатом в адресе (форма без JS).
 */

require __DIR__ . '/../../src/bootstrap.php';

const RATE_LIMIT_MAX    = 5;    // заявок
const RATE_LIMIT_WINDOW = 600;  // за 10 минут с одного IP
const MIN_FILL_SECONDS  = 3;    // быстрее человек форму не заполнит

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$type = ($_POST['type'] ?? '') === 'bath' ? 'bath' : 'booking';

function respond(bool $ok, int $status, string $error = '', array $fields = []): void
{
    global $wantsJson, $type;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'error' => $error, 'fields' => (object) $fields], JSON_UNESCAPED_UNICODE);
    } else {
        $param = $type === 'bath' ? 'notify' : 'booking';
        $anchor = $type === 'bath' ? 'bath' : 'booking';
        header('Location: /?' . $param . '=' . ($ok ? 'ok' : 'error') . '#' . $anchor, true, 303);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

// Боты заполняют скрытое поле и отправляют форму мгновенно. Отвечаем им
// «успехом», чтобы не подсказывать, что их раскусили.
$ts = (int) ($_POST['ts'] ?? 0);
if (trim((string) ($_POST['website'] ?? '')) !== '' || ($ts > 0 && time() - $ts < MIN_FILL_SECONDS)) {
    respond(true, 200);
}

// -------------------------------------------------------------------
// Проверка полей
// -------------------------------------------------------------------

$errors = [];
$str = fn (string $k, int $max) => mb_substr(trim(str_replace(["\r", "\0"], '', (string) ($_POST[$k] ?? ''))), 0, $max);

$phone = $str('phone', 25);
$phoneDigits = preg_replace('/\D+/', '', $phone);
if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
    $errors['phone'] = 'Проверьте номер телефона';
}
if (($_POST['consent'] ?? '') !== '1') {
    $errors['consent'] = 'Нужно согласие на обработку персональных данных';
}

$h = hotel();

if ($type === 'bath') {
    if ($errors) {
        respond(false, 422, 'validation', $errors);
    }
    $subject = 'Баня и хамам: сообщить об открытии';
    $lines = [$subject, '', 'Телефон: ' . $phone];
} else {
    $name = $str('name', 80);
    if ($name === '') {
        $errors['name'] = 'Как к вам обращаться?';
    }

    $in = parse_date($str('check_in', 10));
    $out = parse_date($str('check_out', 10));
    if (!$in || $in < today()->modify('-1 day')) {
        $errors['check_in'] = 'Выберите дату заезда';
    }
    if (!$out || ($in && $out <= $in)) {
        $errors['check_out'] = 'Выезд должен быть позже заезда';
    }
    $nights = ($in && $out && $out > $in) ? (int) $in->diff($out)->days : 0;
    if ($nights > 365) {
        $errors['check_out'] = 'Срок больше года — позвоните нам, обсудим';
    }

    $roomKey = $str('room', 30);
    if ($roomKey !== '' && !isset($h['rooms'][$roomKey])) {
        $roomKey = '';
    }
    $guests = max(1, min(10, (int) ($_POST['guests'] ?? 1)));

    $contactNames = ['call' => 'позвонить'];
    foreach (messenger_links() as $m) {
        $contactNames[$m['key']] = 'написать в ' . $m['label'];
    }
    $contact = $str('contact', 20);
    $contactLabel = $contactNames[$contact] ?? $contactNames['call'];

    $comment = $str('comment', 1000);

    if ($errors) {
        respond(false, 422, 'validation', $errors);
    }

    $fmt = fn (DateTimeImmutable $d) => $d->format('d.m.Y');
    $subject = 'Заявка на бронирование: ' . $fmt($in) . '–' . $fmt($out) . ', ' . $name;
    $lines = [
        'Новая заявка на бронирование',
        '',
        'Имя: ' . $name,
        'Телефон: ' . $phone,
        'Связаться: ' . $contactLabel,
        '',
        'Заезд: ' . $fmt($in),
        'Выезд: ' . $fmt($out) . ' (' . $nights . ' ' . plural($nights, 'ночь', 'ночи', 'ночей') . ')',
        'Номер: ' . ($roomKey !== '' ? $h['rooms'][$roomKey]['name'] : 'подобрать'),
        'Гостей: ' . $guests,
    ];
    if ($roomKey !== '' && ($est = estimate($roomKey, $nights))) {
        $lines[] = 'Расчёт на сайте: ' . ($est['total'] !== null
            ? money($est['total']) . ($est['tier'] ? ' (' . $est['tier']['text'] . ')' : '')
            : money($est['base']) . ' без скидки, ' . $est['tier']['label'] . ' — ' . $est['tier']['text']);
        if ($guests > $h['rooms'][$roomKey]['guests']) {
            $lines[] = '⚠ Гостей больше, чем мест в номере';
        }
    }
    if ($comment !== '') {
        $lines[] = '';
        $lines[] = 'Комментарий: ' . $comment;
    }
}

// Лимит считаем только по заявкам, которые реально уходят администратору:
// гость, который ошибся в полях и поправил их, под него не попадёт.
if (rate_limited($_SERVER['REMOTE_ADDR'] ?? '')) {
    respond(false, 429, 'rate_limit');
}

$lines[] = '';
$lines[] = 'Отправлено с сайта ' . date('d.m.Y H:i');
$text = implode("\n", $lines);

// -------------------------------------------------------------------
// Отправка
// -------------------------------------------------------------------

$secrets = hotel_secrets();
$channels = 0;
$sent = 0;

if (filter_var($secrets['mail_to'], FILTER_VALIDATE_EMAIL)) {
    $channels++;
    $sent += send_mail($secrets['mail_to'], $secrets['mail_from'], $subject, $text) ? 1 : 0;
}
if ($secrets['telegram_bot_token'] !== '' && $secrets['telegram_chat_ids']) {
    $channels++;
    $sent += send_telegram($secrets['telegram_bot_token'], (array) $secrets['telegram_chat_ids'], $text) ? 1 : 0;
}

if ($channels === 0) {
    error_log('hotel booking: не настроен ни один канал отправки заявок (config/secrets.php)');
    respond(false, 503, 'not_configured');
}
if ($sent === 0) {
    respond(false, 502, 'send_failed');
}
respond(true, 200);

// -------------------------------------------------------------------

function send_mail(string $to, string $from, string $subject, string $text): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $params = '';
    if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'From: ' . mb_encode_mimeheader(hotel()['name'], 'UTF-8') . ' <' . $from . '>';
        $params = '-f' . $from;
    }
    $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, implode("\r\n", $headers), $params);
    if (!$ok) {
        error_log('hotel booking: mail() не отправил письмо');
    }
    return $ok;
}

function send_telegram(string $token, array $chatIds, string $text): bool
{
    $ok = false;
    foreach ($chatIds as $chatId) {
        $payload = http_build_query(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']);
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $context);
        $data = is_string($response) ? json_decode($response, true) : null;
        if (!empty($data['ok'])) {
            $ok = true;
        } else {
            error_log('hotel booking: Telegram не принял сообщение: ' . ($data['description'] ?? 'нет ответа'));
        }
    }
    return $ok;
}

/** Не больше RATE_LIMIT_MAX заявок за окно с одного адреса. Хранит только хеш IP. */
function rate_limited(string $ip): bool
{
    $dir = HOTEL_ROOT . '/storage';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    $file = $dir . '/rl-' . sha1($ip . '|' . __FILE__) . '.json';
    $now = time();

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return false;
    }
    flock($fp, LOCK_EX);
    $hits = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $hits = array_values(array_filter($hits, fn ($t) => is_int($t) && $t > $now - RATE_LIMIT_WINDOW));
    $limited = count($hits) >= RATE_LIMIT_MAX;
    if (!$limited) {
        $hits[] = $now;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);

    // Записи старше окна уже ни на что не влияют — удаляем, чтобы хеши IP не копились.
    foreach (glob($dir . '/rl-*.json') ?: [] as $old) {
        if ($old !== $file && filemtime($old) < $now - RATE_LIMIT_WINDOW) {
            @unlink($old);
        }
    }
    return $limited;
}
