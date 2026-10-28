<?php
/** @var array $page title, description, path, [jsonld], [body_class], [noindex] */
$h = hotel();
$canonical = rtrim($h['site_url'], '/') . ($page['path'] ?? '/');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($page['title']) ?></title>
    <meta name="description" content="<?= e($page['description']) ?>">
<?php if (!empty($page['noindex'])): ?>
    <meta name="robots" content="noindex, follow">
<?php endif; ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="<?= e($h['name']) ?>">
    <meta property="og:title" content="<?= e($page['title']) ?>">
    <meta property="og:description" content="<?= e($page['description']) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta name="theme-color" content="#f3ebe1">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="/assets/fonts/manrope-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/cormorant-garamond-cyrillic-500-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?php if (!empty($page['jsonld'])): foreach ($page['jsonld'] as $ld): ?>
    <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; endif; ?>
</head>
<body class="<?= e($page['body_class'] ?? '') ?>">
<?php readfile(HOTEL_ROOT . '/src/partials/icons.svg'); ?>
<a class="skip-link" href="#main">Перейти к содержимому</a>
