<?php
require __DIR__ . '/../src/bootstrap.php';
session_boot();
$token = trim(get('token') ?: post('token'));
$guest = $token !== '' ? q_one('SELECT id, name, marketing_consent FROM guests WHERE marketing_unsubscribe_token = ? AND anonymized_at IS NULL', [$token]) : null;
$done = false;
if (is_post()) {
    csrf_check();
    if ($guest) {
        q('UPDATE guests SET marketing_consent = 0 WHERE id = ?', [(int) $guest['id']]);
        $done = true;
    }
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Отписка от рассылки — Гостиница Неаполь</title><link rel="stylesheet" href="/assets/css/style.css"></head>
<body><main class="container" style="max-width:620px;padding:48px 20px">
<section class="panel">
<h1>Отписка от рассылки</h1>
<?php if ($done): ?>
<p>Готово. Адрес исключён из рекламных и информационных рассылок гостиницы.</p>
<?php elseif (!$guest): ?>
<p>Ссылка недействительна или устарела. Если вы хотите отказаться от рассылок, напишите нам на info@hotel-neapol.ru.</p>
<?php else: ?>
<p><?= e($guest['name']) ?>, подтвердите отказ от рекламных и информационных писем.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn--primary" type="submit">Отписаться</button></form>
<?php endif; ?>
<p><a href="/">На сайт гостиницы</a></p>
</section></main></body></html>