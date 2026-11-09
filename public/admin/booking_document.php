<?php
/**
 * Печатная справка о фактическом проживании гостя.
 * Доступна сотрудникам только после завершения проживания.
 */
require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();
if (is_housekeeper($me)) {
    http_response_code(403);
    exit('Недостаточно прав');
}
$id = (int) get('id');
$b = booking_get($id);
if (!$b) {
    http_response_code(404);
    exit('Бронь не найдена');
}
if ($b['status'] !== 'checked_out') {
    http_response_code(409);
    exit('Справку о фактическом проживании можно сформировать после выезда гостя.');
}
$h = hotel();
$l = $h['legal'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Справка о проживании — бронь №<?= (int) $b['id'] ?></title>
<style>
body{font:16px/1.55 Arial,sans-serif;color:#111;margin:0;background:#eee}
.sheet{max-width:780px;margin:32px auto;padding:64px 72px;background:#fff;min-height:900px;box-sizing:border-box}
h1{text-align:center;font-size:22px;margin:52px 0 38px;text-transform:uppercase}
p{margin:18px 0}.sign{margin-top:90px;display:flex;justify-content:space-between;gap:30px}
.line{border-top:1px solid #333;padding-top:8px;min-width:220px;text-align:center}
.actions{max-width:780px;margin:16px auto;display:flex;justify-content:flex-end}
button{padding:10px 18px;border:0;background:#29231f;color:white;border-radius:4px;font-size:15px}
@media print{body{background:#fff}.sheet{margin:0;max-width:none;min-height:0;padding:25mm 22mm}.actions{display:none}@page{size:A4;margin:0}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Печать / Сохранить в PDF</button></div>
<main class="sheet">
    <p style="text-align:center"><strong><?= e($l['operator']) ?></strong><br><?= e($l['legal_address']) ?><br>ИНН <?= e($l['inn']) ?> · ОГРНИП <?= e($l['ogrn']) ?></p>
    <h1>Справка о проживании</h1>
    <p>Настоящая справка подтверждает, что <?= e($b['guest_name'] ?: 'гость') ?> действительно проживал(а) в гостинице «<?= e($h['name']) ?>» по адресу: <?= e(full_address()) ?>.</p>
    <p>Период фактического проживания: с <strong><?= e(ru_date($b['check_in'])) ?></strong> по <strong><?= e(ru_date($b['check_out'])) ?></strong>.</p>
    <p>Номер: <?= e($b['room_name'] ?: 'не указан') ?> (<?= e($b['type_name']) ?>).</p>
    <p>Справка выдана по месту требования.</p>
    <div class="sign">
        <div>Индивидуальный предприниматель</div>
        <div class="line"><?= e($l['operator']) ?></div>
    </div>
    <p style="margin-top:45px">Дата выдачи: <?= e(ru_date(today()->format('Y-m-d'))) ?></p>
</main>
</body>
</html>
