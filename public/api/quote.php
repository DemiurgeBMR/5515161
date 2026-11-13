<?php
/**
 * Расчёт стоимости и проверка, свободен ли номер, — для формы брони.
 *
 *   GET /api/quote.php?type=ID&check_in=2026-11-05&check_out=2026-11-12&guests=2
 *
 * type=0 — «подберите мне»: самый недорогой свободный номер, где хватит места.
 */

require __DIR__ . '/../../src/bootstrap.php';

if (!db_ready()) {
    json_response(['ok' => false, 'message' => 'Расчёт временно недоступен — позвоните нам.'], 503);
}

$in = get('check_in');
$out = get('check_out');
$guests = max(1, min(20, (int) get('guests', '1')));
$typeId = (int) get('type', '0');

try {
    booking_validate_dates($in, $out, false);
} catch (BookingError $e) {
    json_response(['ok' => false, 'field' => $e->field, 'message' => $e->getMessage()]);
}

$types = room_types();
if ($typeId) {
    if (!isset($types[$typeId])) {
        json_response(['ok' => false, 'message' => 'Такого номера нет']);
    }
    $candidates = [$types[$typeId]];
} else {
    $candidates = array_values(array_filter($types, fn ($t) => $t['capacity'] >= $guests));
    usort($candidates, fn ($a, $b) => [$a['base_price'], $a['id']] <=> [$b['base_price'], $b['id']]);
}

$chosen = null;
foreach ($candidates as $t) {
    if ($t['capacity'] >= $guests && find_free_room($t['id'], $in, $out)) {
        $chosen = $t;
        break;
    }
}
$type = $chosen ?? ($candidates[0] ?? null);
if (!$type) {
    json_response(['ok' => true, 'available' => false, 'message' => 'Номеров на столько гостей нет — позвоните нам, что-нибудь придумаем.']);
}

$q = quote($type, $in, $out);
$message = '';
if (!$chosen) {
    $message = $type['capacity'] < $guests
        ? 'В номере «' . $type['name'] . '» — до ' . $type['capacity'] . ' ' . plural($type['capacity'], 'гостя', 'гостей', 'гостей') . '.'
        : 'На эти даты ' . ($typeId ? 'номер «' . $type['name'] . '» занят' : 'свободных номеров нет') . '. Посмотрите свободные даты в календаре выше или позвоните нам.';
}

json_response([
    'ok'        => true,
    'available' => $chosen !== null,
    'message'   => $message,
    'type'      => ['id' => $type['id'], 'name' => $type['name'], 'tbd' => (bool) $type['price_tbd']],
    'nights'    => $q['nights'],
    'sum'       => $q['sum'],
    'avg'       => $q['avg'],
    'seasonal'  => $q['seasonal'],
    'discount'  => $q['discount'],
    'total'     => $q['total'],
    'tier'      => $q['tier'] ? ['label' => $q['tier']['label'], 'percent' => $q['tier']['percent'], 'text' => $q['tier']['text']] : null,
    'next_tier' => $q['next_tier'] && $q['next_tier']['percent'] !== null
        ? ['nights' => $q['next_tier']['min_nights'], 'percent' => $q['next_tier']['percent']] : null,
    'prepayment' => $q['total'] !== null ? default_prepayment($q) : null,
]);
