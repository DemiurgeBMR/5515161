<?php
/**
 * Страница «сайт настраивается» — когда база недоступна. Гость всё равно
 * видит телефон и может забронировать по звонку.
 */
http_response_code(503);
header('Retry-After: 600');
$h = hotel();
$page = ['title' => $h['name'] . ' — гостиница в Симферополе', 'description' => 'Гостиница рядом с онкодиспансером в Симферополе.', 'path' => '/', 'noindex' => true];
require __DIR__ . '/head.php';
?>
<main id="main" class="doc">
    <div class="container doc__inner">
        <h1 class="display">Сайт обновляется</h1>
        <p class="lead">Онлайн-бронирование вернётся через несколько минут. Забронировать номер можно по телефону — администратор ответит и подскажет свободные даты.</p>
        <p><a class="btn btn--primary btn--lg" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a></p>
        <p class="muted"><?= e(full_address()) ?></p>
    </div>
</main>
</body>
</html>
