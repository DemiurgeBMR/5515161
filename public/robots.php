<?php
// robots.txt для поисковиков (отдаётся как /robots.txt, см. .htaccess).
// Пока в config/hotel.php indexing = false, сайт закрыт целиком.
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
if (!site_indexing()) {
    echo "Disallow: /\n";
    exit;
}
echo "Disallow: /api/\nDisallow: /admin/\nDisallow: /booking.php\nDisallow: /account.php\nDisallow: /ical.php\n";
$base = rtrim(hotel()['site_url'], '/');
if (filled($base)) {
    echo "\nSitemap: " . $base . "/sitemap.xml\n";
}
