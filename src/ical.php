<?php
/**
 * Синхронизация занятости с площадками (Авито, Суточно.ру, Островок и др.)
 * через календари iCal — чтобы один номер не продали дважды.
 *
 * Экспорт: у каждой комнаты своя секретная ссылка /ical.php?room=ID&key=…
 * Её вставляют на площадке — площадка видит, когда комната занята у нас.
 *
 * Импорт: ссылку на календарь площадки вставляют в админке → Площадки.
 * Занятые там даты становятся закрытиями комнаты (source = ical) — сайт
 * перестаёт их продавать. Обновляется раз в 15 минут (tools/cron.php или
 * само при работе в админке) и по кнопке.
 */

const ICAL_MAX_BYTES = 2 * 1024 * 1024;

function ical_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
}

/** Календарь занятости комнаты: наши брони и ручные закрытия, без личных данных. */
function ical_export(array $room): string
{
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'hotel';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . ical_escape(mail_hotel_name()) . '//RU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ical_escape(mail_hotel_name() . ' — ' . $room['name']),
    ];
    $since = today()->modify('-30 days')->format('Y-m-d');
    $stamp = gmdate('Ymd\THis\Z');
    $events = q_all('SELECT id, check_in AS a, check_out AS b FROM bookings WHERE room_id = ? AND status IN (' . occupying_in() . ') AND check_out >= ?', [$room['id'], $since]);
    foreach ($events as $ev) {
        $lines = array_merge($lines, ical_event('booking-' . $ev['id'] . '@' . $host, $ev['a'], $ev['b'], $stamp));
    }
    // Закрытия, пришедшие с площадок, обратно не отдаём — иначе календари зациклятся.
    foreach (q_all("SELECT id, date_from AS a, date_to AS b FROM room_closures WHERE room_id = ? AND source = 'manual' AND date_to >= ?", [$room['id'], $since]) as $ev) {
        $lines = array_merge($lines, ical_event('closure-' . $ev['id'] . '@' . $host, $ev['a'], $ev['b'], $stamp));
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

function ical_event(string $uid, string $from, string $to, string $stamp): array
{
    return [
        'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . $stamp,
        'DTSTART;VALUE=DATE:' . str_replace('-', '', $from),
        'DTEND;VALUE=DATE:' . str_replace('-', '', $to),
        'SUMMARY:Занято', 'TRANSP:OPAQUE', 'END:VEVENT',
    ];
}

/** События календаря: [['uid', 'from' => 'Y-m-d', 'to' => 'Y-m-d', 'summary'], ...]. */
function ical_parse(string $ics): array
{
    // Длинные строки в iCal переносятся: перенос + пробел — продолжение строки.
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics);
    $events = [];
    $cur = null;
    foreach (preg_split("/\r?\n/", $ics) as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $cur = ['uid' => '', 'from' => null, 'to' => null, 'summary' => '', 'cancelled' => false];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($cur && $cur['from'] && !$cur['cancelled']) {
                if (!$cur['to'] || $cur['to'] <= $cur['from']) {
                    $cur['to'] = parse_date($cur['from'])->modify('+1 day')->format('Y-m-d');
                }
                unset($cur['cancelled']);
                $events[] = $cur;
            }
            $cur = null;
            continue;
        }
        if ($cur === null || strpos($line, ':') === false) {
            continue;
        }
        [$nameParams, $value] = explode(':', $line, 2);
        $name = strtoupper(explode(';', $nameParams)[0]);
        if ($name === 'DTSTART' || $name === 'DTEND') {
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $m)) {
                $cur[$name === 'DTSTART' ? 'from' : 'to'] = $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        } elseif ($name === 'UID') {
            $cur['uid'] = mb_substr($value, 0, 255);
        } elseif ($name === 'SUMMARY') {
            $cur['summary'] = mb_substr(stripcslashes($value), 0, 200);
        } elseif ($name === 'STATUS' && strtoupper(trim($value)) === 'CANCELLED') {
            $cur['cancelled'] = true;
        }
    }
    return $events;
}

function ical_fetch(string $url): string
{
    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('ссылка должна начинаться с https://');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'HotelCalendarSync/1.0',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > ICAL_MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!$ok || $code >= 400) {
            throw new RuntimeException($err !== '' ? $err : 'площадка ответила ошибкой ' . $code);
        }
        return $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'HotelCalendarSync/1.0']]);
    $body = @file_get_contents($url, false, $ctx, 0, ICAL_MAX_BYTES);
    if ($body === false) {
        throw new RuntimeException('не удалось скачать календарь');
    }
    return $body;
}

/** Обновить закрытия комнаты по календарям площадок. Возвращает текст ошибки или null. */
function ical_sync_room(array $room): ?string
{
    $urls = array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) $room['ical_import_url']))));
    if (!$urls) {
        return null;
    }
    $events = [];
    $error = null;
    foreach ($urls as $url) {
        try {
            $body = ical_fetch($url);
            if (stripos($body, 'BEGIN:VCALENDAR') === false) {
                throw new RuntimeException('по ссылке не календарь');
            }
            foreach (ical_parse($body) as $ev) {
                $ev['source_url'] = $url;
                $events[] = $ev;
            }
        } catch (Throwable $e) {
            $error = parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage();
        }
    }
    $today = today()->format('Y-m-d');
    // Если хоть одна площадка не ответила, старые закрытия не трогаем:
    // лучше лишний раз не продать, чем продать занятое.
    if ($error === null) {
        tx(function () use ($room, $events, $today) {
            q("DELETE FROM room_closures WHERE room_id = ? AND source = 'ical'", [$room['id']]);
            foreach ($events as $ev) {
                if ($ev['to'] <= $today) {
                    continue;
                }
                q_insert('room_closures', [
                    'room_id' => $room['id'], 'date_from' => $ev['from'], 'date_to' => $ev['to'],
                    'reason' => mb_substr(($ev['summary'] !== '' ? $ev['summary'] . ' — ' : '') . (parse_url($ev['source_url'], PHP_URL_HOST) ?: 'площадка'), 0, 255),
                    'source' => 'ical', 'external_uid' => $ev['uid'], 'created_at' => now(),
                ]);
            }
        });
        touch_data_version();
    }
    q('UPDATE rooms SET ical_synced_at = ?, ical_error = ? WHERE id = ?', [now(), mb_substr((string) $error, 0, 255), $room['id']]);
    return $error;
}

function ical_sync_all(): array
{
    $result = [];
    foreach (q_all("SELECT * FROM rooms WHERE ical_import_url IS NOT NULL AND ical_import_url <> ''") as $room) {
        $result[(int) $room['id']] = ical_sync_room($room);
    }
    return $result;
}

/**
 * Брони, которые пересекаются с занятостью на площадках, — двойная продажа.
 * Показываются на экране «Сегодня» и в шахматке.
 */
function ical_conflicts(): array
{
    return q_all(
        "SELECT b.id, b.check_in, b.check_out, r.name AS room_name, c.date_from, c.date_to, c.reason
         FROM bookings b JOIN rooms r ON r.id = b.room_id
         JOIN room_closures c ON c.room_id = b.room_id AND c.source = 'ical' AND c.date_from < b.check_out AND c.date_to > b.check_in
         WHERE b.status IN (" . occupying_in() . ") AND b.check_out >= ?
         ORDER BY b.check_in",
        [today()->format('Y-m-d')]
    );
}
