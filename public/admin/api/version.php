<?php
/**
 * Для открытых страниц админки: изменились ли данные и сколько новых заявок.
 * Страницы спрашивают раз в 30 секунд.
 */
require __DIR__ . '/../../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';

session_boot();
if (!current_staff()) {
    json_response(['ok' => false], 401);
}
$c = admin_counters();
session_write_close();
json_response([
    'ok'      => true,
    'version' => setting('data_version', '0'),
    'badges'  => ['today' => $c['messages'] + $c['conflicts'], 'bookings' => $c['new']],
]);
