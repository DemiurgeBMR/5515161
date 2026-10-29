<?php
/**
 * Свободные номера по ночам — для календаря на главной.
 *
 *   GET /api/availability.php?month=2026-11&months=2
 *
 * Без имён и любых личных данных: только сколько комнат каждого типа
 * свободно в каждую ночь. Календарь на сайте запрашивает это раз в минуту.
 */

require __DIR__ . '/../../src/bootstrap.php';

if (!db_ready()) {
    json_response(['ok' => false], 503);
}

$today = today();
$month = get('month', $today->format('Y-m'));
$start = preg_match('/^\d{4}-\d{2}$/', $month) ? parse_date($month . '-01') : null;
$thisMonth = parse_date($today->format('Y-m') . '-01');
if (!$start || $start < $thisMonth) {
    $start = $thisMonth;
}
if ($start > $thisMonth->modify('+18 months')) {
    json_response(['ok' => false, 'message' => 'Так далеко вперёд бронь не принимаем'], 400);
}
$months = max(1, min(3, (int) get('months', '2')));
$from = $start->format('Y-m-d');
$to = $start->modify('+' . $months . ' months')->format('Y-m-d');

$grid = availability_grid($from, $to);
$total = [];
foreach ($grid['rooms'] as $room) {
    $t = (int) $room['room_type_id'];
    $total[$t] = ($total[$t] ?? 0) + 1;
}
$types = [];
foreach (room_types() as $t) {
    $types[] = ['id' => $t['id'], 'name' => $t['name'], 'capacity' => $t['capacity'], 'rooms' => $total[$t['id']] ?? 0];
}

header('Cache-Control: no-store');
json_response([
    'ok'    => true,
    'today' => $today->format('Y-m-d'),
    'from'  => $from,
    'to'    => $to,
    'types' => $types,
    'free'  => $grid['free'],
]);
