<?php
/**
 * Помощник для tests/run.php: дождаться общего момента старта и
 * попытаться забронировать номер. Несколько таких процессов запускаются
 * одновременно — проверка, что последний номер не продаётся дважды.
 *
 *     php tests/book_once.php <unix-время старта> <заезд> <выезд> <id типа>
 */

ob_start();
require __DIR__ . '/../src/bootstrap.php';

[, $startAt, $in, $out, $type] = $argv;
db(); // подключаемся заранее, чтобы все стартовали одновременно
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}
try {
    $b = booking_create(['name' => 'Гонка ' . getmypid(), 'phone' => '+7978' . random_int(1000000, 9999999), 'check_in' => $in, 'check_out' => $out, 'room_type_id' => (int) $type, 'guests' => 1]);
    $result = 'OK ' . $b['id'];
} catch (BookingError $e) {
    $result = 'нет мест';
} catch (Throwable $e) {
    $result = 'ERR ' . $e->getMessage();
}
ob_end_clean();
echo $result;
