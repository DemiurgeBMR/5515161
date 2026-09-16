<?php
/**
 * Динамический sitemap: статичные страницы + все активные и промодерированные
 * локации. Новое объявление появляется здесь автоматически при следующем
 * заходе краулера, без ручного обновления файла.
 *
 * Отдаётся с расширением .php, а не .xml — сайт нигде не использует
 * mod_rewrite, и добавлять его только ради одного файла ради чистого URL не
 * стоит риска (не факт, что на хостинге вообще включён AllowOverride).
 * Поисковики прекрасно принимают sitemap по любому URL, если он верно
 * указан при отправке в Google Search Console / Яндекс.Вебмастер и/или
 * через строку Sitemap: в robots.txt.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$cacheKey = 'sitemap_xml';
$cached = getCached($cacheKey, 3600);
if ($cached !== null) {
    echo $cached;
    exit;
}

$pdo = getDbConnection();

$staticPages = [
    ['loc' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
    ['loc' => '/pages/catalog.php', 'changefreq' => 'daily', 'priority' => '0.9'],
    ['loc' => '/pages/map.php', 'changefreq' => 'daily', 'priority' => '0.7'],
    ['loc' => '/pages/how_it_works.php', 'changefreq' => 'monthly', 'priority' => '0.5'],
];

$stmt = $pdo->query("
    SELECT id, updated_at FROM locations
    WHERE is_active = 1 AND is_moderated = 1
    ORDER BY updated_at DESC
    LIMIT 5000
");
$locations = $stmt->fetchAll();

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($staticPages as $page) {
    $xml .= "  <url>\n";
    $xml .= '    <loc>' . htmlspecialchars(SITE_URL . $page['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    $xml .= '    <changefreq>' . $page['changefreq'] . "</changefreq>\n";
    $xml .= '    <priority>' . $page['priority'] . "</priority>\n";
    $xml .= "  </url>\n";
}

foreach ($locations as $loc) {
    $xml .= "  <url>\n";
    $xml .= '    <loc>' . htmlspecialchars(SITE_URL . '/pages/location.php?id=' . (int) $loc['id'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if (!empty($loc['updated_at'])) {
        $xml .= '    <lastmod>' . date('Y-m-d', strtotime($loc['updated_at'])) . "</lastmod>\n";
    }
    $xml .= "    <changefreq>weekly</changefreq>\n";
    $xml .= "    <priority>0.8</priority>\n";
    $xml .= "  </url>\n";
}

$xml .= '</urlset>' . "\n";

setCache($cacheKey, $xml);
echo $xml;
