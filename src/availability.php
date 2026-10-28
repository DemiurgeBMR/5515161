<?php
/**
 * Занятость номеров.
 *
 * Бронь занимает ночи с check_in включительно по check_out не включая.
 * Комнату занимают брони в статусах «заявка», «подтверждена», «проживает»
 * и закрытия (ремонт, занято на другой площадке). Выехавшие, отменённые и
 * неприехавшие брони комнату не держат.
 */

const OCCUPYING_STATUSES = ['new', 'confirmed', 'checked_in'];

function occupying_in(): string
{
    return "'" . implode("', '", OCCUPYING_STATUSES) . "'";
}

/** Ночи периода: ['2026-11-05', '2026-11-06', ...] (без даты выезда). */
function nights_list(string $from, string $to): array
{
    $out = [];
    $d = parse_date($from);
    $end = parse_date($to);
    if (!$d || !$end) {
        return $out;
    }
    while ($d < $end) {
        $out[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $out;
}

/** Комнаты с названием типа, в порядке типов. */
function rooms_list(bool $activeOnly = true, ?int $typeId = null): array
{
    $where = [];
    $params = [];
    if ($activeOnly) {
        $where[] = 'r.is_active = 1 AND t.is_active = 1';
    }
    if ($typeId !== null) {
        $where[] = 'r.room_type_id = ?';
        $params[] = $typeId;
    }
    return q_all(
        'SELECT r.*, t.name AS type_name, t.capacity FROM rooms r JOIN room_types t ON t.id = r.room_type_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.sort, t.base_price, t.id, r.sort, r.name, r.id',
        $params
    );
}

/**
 * Что мешает поселить в комнату на эти даты: бронь или закрытие.
 * null — комната свободна.
 */
function room_conflict(int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): ?array
{
    $params = [$roomId, $checkOut, $checkIn];
    $sql = 'SELECT b.id, b.check_in, b.check_out, b.status, g.name AS guest_name
            FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id
            WHERE b.room_id = ? AND b.status IN (' . occupying_in() . ') AND b.check_in < ? AND b.check_out > ?';
    if ($excludeBookingId) {
        $sql .= ' AND b.id <> ?';
        $params[] = $excludeBookingId;
    }
    $b = q_one($sql . ' ORDER BY b.check_in LIMIT 1', $params);
    if ($b) {
        return ['kind' => 'booking'] + $b;
    }
    $c = q_one(
        'SELECT id, date_from, date_to, reason, source FROM room_closures WHERE room_id = ? AND date_from < ? AND date_to > ? ORDER BY date_from LIMIT 1',
        [$roomId, $checkOut, $checkIn]
    );
    return $c ? ['kind' => 'closure'] + $c : null;
}

function conflict_text(array $c): string
{
    if ($c['kind'] === 'booking') {
        return 'бронь №' . $c['id'] . ($c['guest_name'] ? ' (' . $c['guest_name'] . ')' : '') . ', ' . ru_date_range($c['check_in'], $c['check_out']);
    }
    return ($c['source'] === 'ical' ? 'занято на другой площадке' : 'комната закрыта' . ($c['reason'] !== '' ? ' — ' . $c['reason'] : ''))
        . ', ' . ru_date_range($c['date_from'], $c['date_to']);
}

/**
 * Заблокировать комнаты типа до конца транзакции. Пока одна бронь
 * проверяет и занимает комнату, вторая ждёт — и потом видит уже занятую.
 */
function lock_rooms(int $typeId): array
{
    return array_map('intval', q('SELECT id FROM rooms WHERE room_type_id = ? ORDER BY id FOR UPDATE', [$typeId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Первая комната типа, свободная на весь срок, или null. */
function find_free_room(int $typeId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): ?int
{
    foreach (rooms_list(true, $typeId) as $room) {
        if (!room_conflict((int) $room['id'], $checkIn, $checkOut, $excludeBookingId)) {
            return (int) $room['id'];
        }
    }
    return null;
}

/**
 * Занятость по ночам для календаря на сайте и шахматки:
 * ['busy' => [room_id => [date => true]], 'free' => [date => [type_id => свободно комнат]]].
 */
function availability_grid(string $from, string $to): array
{
    $rooms = rooms_list(true);
    $busy = [];
    $mark = function (int $roomId, string $a, string $b) use (&$busy, $from, $to) {
        foreach (nights_list(max($a, $from), min($b, $to)) as $night) {
            $busy[$roomId][$night] = true;
        }
    };
    foreach (q_all('SELECT room_id, check_in, check_out FROM bookings WHERE room_id IS NOT NULL AND status IN (' . occupying_in() . ') AND check_in < ? AND check_out > ?', [$to, $from]) as $b) {
        $mark((int) $b['room_id'], $b['check_in'], $b['check_out']);
    }
    foreach (q_all('SELECT room_id, date_from, date_to FROM room_closures WHERE date_from < ? AND date_to > ?', [$to, $from]) as $c) {
        $mark((int) $c['room_id'], $c['date_from'], $c['date_to']);
    }
    $free = [];
    foreach (nights_list($from, $to) as $night) {
        foreach ($rooms as $room) {
            $t = (int) $room['room_type_id'];
            $free[$night][$t] = ($free[$night][$t] ?? 0) + (empty($busy[(int) $room['id']][$night]) ? 1 : 0);
        }
    }
    return ['busy' => $busy, 'free' => $free, 'rooms' => $rooms];
}

/** Свободные комнаты на даты — для выбора в карточке брони. */
function free_rooms(string $checkIn, string $checkOut, ?int $excludeBookingId = null): array
{
    $out = [];
    foreach (rooms_list(true) as $room) {
        if (!room_conflict((int) $room['id'], $checkIn, $checkOut, $excludeBookingId)) {
            $out[(int) $room['id']] = $room;
        }
    }
    return $out;
}
