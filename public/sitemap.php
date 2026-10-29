<?php
// Карта сайта для Яндекса и Google. Отдаётся как /sitemap.xml (см. .htaccess).
require __DIR__ . '/../src/bootstrap.php';

$base = rtrim(hotel()['site_url'], '/');
$pages = ['/' => '1.0', '/rules.php' => '0.4', '/privacy.php' => '0.2', '/consent.php' => '0.2'];
if (bath_enabled()) {
    $pages = array_slice($pages, 0, 1, true) + ['/bath.php' => '0.8'] + $pages;
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($pages as $path => $priority) {
    $file = __DIR__ . ($path === '/' ? '/index.php' : $path);
    echo '  <url><loc>', e($base . $path), '</loc><lastmod>', date('Y-m-d', filemtime($file)),
        '</lastmod><priority>', $priority, '</priority></url>', "\n";
}
echo '</urlset>', "\n";
