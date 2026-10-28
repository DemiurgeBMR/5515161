<?php
/**
 * Календарь занятости комнаты для площадок бронирования (iCal).
 * Ссылку с ключом берут в админке → Площадки. Без ключа — 404.
 */

require __DIR__ . '/../src/bootstrap.php';

$room = db_ready() ? q_one('SELECT * FROM rooms WHERE id = ?', [(int) get('room')]) : null;
if (!$room || !hash_equals($room['ical_token'], get('key'))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="room-' . (int) $room['id'] . '.ics"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo ical_export($room);
