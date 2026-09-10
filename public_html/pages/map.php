<?php
session_start();
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

$city = trim($_GET['city'] ?? '');
$hasSubscription = currentUserHasSubscription();

if (!$hasSubscription) {
    // Без подписки — ни точек, ни координат, только агрегированный список
    // "город → сколько локаций". Полная интерактивная карта — только для
    // подписчиков (см. pages/subscription.php).
    $sql = "SELECT city, COUNT(*) as cnt FROM locations WHERE is_active = 1 AND is_moderated = 1";
    $params = [];
    if ($city !== '') {
        $sql .= " AND city LIKE ?";
        $params[] = '%' . $city . '%';
    }
    $sql .= " GROUP BY city ORDER BY city";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cityCounts = $stmt->fetchAll();
    $totalLocations = array_sum(array_column($cityCounts, 'cnt'));
} else {
    $sql = "SELECT l.id, l.title, l.city, l.address, l.price_month, l.traffic_rating, l.latitude, l.longitude,
            (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
            FROM locations l
            WHERE l.is_active = 1 AND l.is_moderated = 1
              AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL";
    $params = [];
    if ($city !== '') {
        $sql .= " AND l.city LIKE ?";
        $params[] = '%' . $city . '%';
    }
    $sql .= " ORDER BY l.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll();

    // Считаем опубликованные локации без координат — чтобы честно показать,
    // что они существуют, просто ещё не попали на карту (геокодер не смог найти адрес).
    $sql_no_geo = "SELECT COUNT(*) FROM locations
                   WHERE is_active = 1 AND is_moderated = 1
                     AND (latitude IS NULL OR longitude IS NULL)";
    $params_no_geo = [];
    if ($city !== '') {
        $sql_no_geo .= " AND city LIKE ?";
        $params_no_geo[] = '%' . $city . '%';
    }
    $stmt_no_geo = $pdo->prepare($sql_no_geo);
    $stmt_no_geo->execute($params_no_geo);
    $total_no_geo = (int) $stmt_no_geo->fetchColumn();

    $mapPoints = array_map(function ($loc) {
        return [
            'id'      => (int) $loc['id'],
            'title'   => $loc['title'],
            'city'    => $loc['city'],
            'address' => $loc['address'],
            'price'   => (float) $loc['price_month'],
            'traffic' => (int) $loc['traffic_rating'],
            'lat'     => (float) $loc['latitude'],
            'lng'     => (float) $loc['longitude'],
            'photo'   => !empty($loc['main_photo']) ? '/' . $loc['main_photo'] : '/assets/images/placeholder.jpg',
            'url'     => '/pages/location.php?id=' . (int) $loc['id'],
        ];
    }, $locations);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта локаций — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if ($hasSubscription): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <?php endif; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="map-page">
        <div class="map-toolbar">
            <h1>🗺️ Карта локаций</h1>
            <form method="GET" class="map-filter">
                <input type="text" name="city" placeholder="Фильтр по городу..." value="<?php echo htmlspecialchars($city); ?>">
                <button type="submit" class="btn-filter">Найти</button>
                <?php if ($city !== ''): ?>
                    <a href="/pages/map.php" class="btn-reset">Сбросить</a>
                <?php endif; ?>
            </form>
            <?php if ($hasSubscription): ?>
                <div class="map-count">
                    На карте: <strong><?php echo count($mapPoints); ?></strong>
                    <?php if ($total_no_geo > 0): ?>
                        <span class="map-count-hint" title="У этих локаций пока не определены координаты">
                            · ещё <?php echo $total_no_geo; ?> без координат
                        </span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="map-count">Всего локаций: <strong><?php echo $totalLocations; ?></strong></div>
            <?php endif; ?>
        </div>

        <?php if (!$hasSubscription): ?>
            <div class="map-paywall">
                <div class="map-paywall-icon">🔒</div>
                <h3>Карта с точками доступна по подписке</h3>
                <p>Без подписки видно только количество локаций по городам. Включите подписку, чтобы увидеть точки на карте и точные адреса локаций.</p>
                <a href="/pages/subscription.php" class="btn-filter">Оформить подписку</a>
            </div>

            <?php if (count($cityCounts) > 0): ?>
                <div class="city-counts">
                    <table class="city-counts-table">
                        <thead>
                            <tr><th>Город</th><th>Локаций</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cityCounts as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['city']); ?></td>
                                    <td><?php echo (int) $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty" style="margin-top: 20px;">
                    <h3>😕 Пока ничего нет</h3>
                    <p>Попробуйте изменить город или откройте <a href="/pages/catalog.php" style="color:#e94560;">полный каталог</a>.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div id="map"></div>

            <?php if (count($mapPoints) === 0): ?>
                <div class="empty" style="margin-top: 20px;">
                    <h3>😕 На карте пока ничего нет</h3>
                    <p>Попробуйте изменить город или откройте <a href="/pages/catalog.php" style="color:#e94560;">полный каталог</a>.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>

    <?php if ($hasSubscription): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    (function () {
        var points = <?php echo json_encode($mapPoints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        var map = L.map('map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
        }).addTo(map);

        var markers = [];
        points.forEach(function (loc) {
            var marker = L.marker([loc.lat, loc.lng]).addTo(map);
            var stars = '';
            for (var i = 1; i <= 5; i++) {
                stars += '<span style="color:' + (i <= loc.traffic ? '#f1c40f' : '#ddd') + '">★</span>';
            }
            var priceLabel = loc.price.toLocaleString('ru-RU') + ' ₽ / мес';
            marker.bindPopup(
                '<div class="map-popup">' +
                    '<img src="' + escapeHtml(loc.photo) + '" alt="">' +
                    '<div class="map-popup-title">' + escapeHtml(loc.title) + '</div>' +
                    '<div class="map-popup-address">📍 ' + escapeHtml(loc.city + ', ' + loc.address) + '</div>' +
                    (loc.traffic > 0 ? '<div class="map-popup-traffic">' + stars + '</div>' : '') +
                    '<div class="map-popup-price">' + priceLabel + '</div>' +
                    '<a href="' + escapeHtml(loc.url) + '" class="map-popup-link">Подробнее →</a>' +
                '</div>'
            );
            markers.push(marker);
        });

        if (markers.length > 0) {
            var group = L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.2));
        } else {
            map.setView([55.751244, 37.618423], 5);
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
