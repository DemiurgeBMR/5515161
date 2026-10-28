<?php
/**
 * @var array $page title, description, path, [jsonld], [body_class], [noindex],
 *                  [analytics] — false, чтобы не подключать Метрику (страницы с личными
 *                  данными: бронь по секретной ссылке, «Мои брони»), [og_image]
 */
$h = hotel();
$canonical = rtrim($h['site_url'], '/') . ($page['path'] ?? '/');
$noindex = !empty($page['noindex']) || !site_indexing();
$analytics = $h['analytics'] ?? [];
$metrika = ($page['analytics'] ?? true) ? metrika_id() : '';
$ogImage = array_key_exists('og_image', $page) ? $page['og_image'] : og_image_url();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($page['title']) ?></title>
    <meta name="description" content="<?= e($page['description']) ?>">
<?php if ($noindex): ?>
    <meta name="robots" content="noindex, <?= site_indexing() ? 'follow' : 'nofollow' ?>">
<?php endif; ?>
<?php if (!empty($analytics['yandex_verification'])): ?>
    <meta name="yandex-verification" content="<?= e($analytics['yandex_verification']) ?>">
<?php endif; ?>
<?php if (!empty($analytics['google_verification'])): ?>
    <meta name="google-site-verification" content="<?= e($analytics['google_verification']) ?>">
<?php endif; ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="<?= e($h['name']) ?>">
    <meta property="og:title" content="<?= e($page['title']) ?>">
    <meta property="og:description" content="<?= e($page['description']) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($ogImage): ?>
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
    <meta name="theme-color" content="#f3ebe1">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preload" href="/assets/fonts/manrope-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/cormorant-garamond-cyrillic-500-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?php if (!empty($page['jsonld'])): foreach ($page['jsonld'] as $ld): ?>
    <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; endif; ?>
</head>
<body class="<?= e($page['body_class'] ?? '') ?>"<?php if ($metrika !== ''): ?> data-metrika="<?= e($metrika) ?>" data-webvisor="<?= !empty($analytics['webvisor']) ? '1' : '0' ?>"<?php endif; ?>>
<?php readfile(HOTEL_ROOT . '/src/partials/icons.svg'); ?>
<a class="skip-link" href="#main">Перейти к содержимому</a>
