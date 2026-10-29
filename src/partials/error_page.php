<?php
/**
 * Страница для гостя, когда что-то не так: сайт обновляется (503), сломалось
 * (500), страница не найдена (404). Телефон виден всегда — забронировать
 * можно звонком. Перед подключением задайте $errorCode.
 *
 * Использует только config/hotel.php — работает и без базы данных.
 */
$errorCode = $errorCode ?? 503;
http_response_code($errorCode);
if ($errorCode === 503) {
    header('Retry-After: 600');
}
header('Cache-Control: no-store');
$h = hotel();
$texts = [
    404 => ['Такой страницы нет', 'Возможно, ссылка устарела или в адресе опечатка. Всё главное — на главной странице, а забронировать номер можно и по телефону.'],
    500 => ['Что-то пошло не так', 'Мы уже разбираемся. Пока забронировать номер можно по телефону — администратор ответит и подскажет свободные даты.'],
    503 => ['Сайт обновляется', 'Онлайн-бронирование вернётся через несколько минут. Забронировать номер можно по телефону — администратор ответит и подскажет свободные даты.'],
];
[$title, $text] = $texts[$errorCode] ?? $texts[500];
$page = ['title' => $title . ' — ' . $h['name'], 'description' => 'Гостиница в Симферополе, ' . $h['address']['district'] . '.', 'path' => '/', 'noindex' => true, 'analytics' => false, 'og_image' => null];
require __DIR__ . '/head.php';
require __DIR__ . '/header.php';
?>
<main id="main" class="doc">
    <div class="container doc__inner">
        <h1 class="display"><?= e($title) ?></h1>
        <p class="lead"><?= e($text) ?></p>
        <p class="doc__actions">
            <a class="btn btn--primary btn--lg" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
<?php if ($errorCode === 404): ?>
            <a class="btn btn--outline btn--lg" href="/">На главную</a>
<?php endif; ?>
        </p>
        <p class="muted"><?= e(full_address()) ?></p>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
