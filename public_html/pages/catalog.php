<?php
session_start();
require_once __DIR__ . '/../config.php';

// ========== ПОИСКОВЫЙ ЗАПРОС ==========
$search_query = trim($_GET['q'] ?? '');

$pdo = getDbConnection();

$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$user_id = $_SESSION['user_id'] ?? 0;
$hasFullAccess = $is_admin || currentUserHasSubscription();

// Текстовые уровни трафика — те же формулировки, что и в подсказке "Как
// оценить проходимость места?" на карточке локации (pages/location.php),
// чтобы термины совпадали по всему сайту.
$trafficLabels = [
    1 => 'Низкая',
    2 => 'Ниже среднего',
    3 => 'Средняя',
    4 => 'Высокая',
    5 => 'Максимальная',
];

// Параметры фильтрации
$city            = trim($_GET['city'] ?? '');
$min_price       = $_GET['min_price'] ?? '';
$max_price       = $_GET['max_price'] ?? '';
$min_area        = $_GET['min_area'] ?? '';
$max_area        = $_GET['max_area'] ?? '';
$has_electricity = isset($_GET['has_electricity']) ? 1 : 0;
$has_wifi        = isset($_GET['has_wifi']) ? 1 : 0;
$has_water       = isset($_GET['has_water']) ? 1 : 0;
$traffic_min     = isset($_GET['traffic_min']) ? (int)$_GET['traffic_min'] : 0;
$space_type      = $_GET['space_type'] ?? '';
$access_hours    = $_GET['access_hours'] ?? '';
$sort            = $_GET['sort'] ?? 'newest';

$sort_options = [
    'newest'       => 'l.created_at DESC',
    'price_asc'    => 'l.price_month ASC',
    'price_desc'   => 'l.price_month DESC',
    'traffic_desc' => 'l.traffic_rating DESC',
];
if (!isset($sort_options[$sort])) {
    $sort = 'newest';
}

// Пагинация
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// Локация, за которой уже реально закреплён (активно) оператор, больше не
// свободна — её незачем показывать в публичном каталоге/на карте другим
// операторам, которые пришли бы с заявкой на уже занятое место. Как только
// владелец открепляет оператора, условие само перестаёт выполняться и
// локация возвращается в каталог — отдельный флаг для этого не нужен.
$notOccupiedSql = "NOT EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active')";

// ---------- Города для строки быстрых фильтров: топ-12 по числу активных
// локаций (независимо от остальных фильтров — это витрина, а не результат
// текущего поиска) ----------
$cityCounts = $pdo->query("
    SELECT city, COUNT(*) as cnt
    FROM locations l
    WHERE is_active = 1 AND is_moderated = 1 AND $notOccupiedSql
    GROUP BY city
    ORDER BY cnt DESC, city ASC
    LIMIT 12
")->fetchAll();

// ---------- Строим условия WHERE один раз — и для подсчёта, и для выборки,
// чтобы они не могли разъехаться между собой (было именно так раньше). ----------
$where = ['l.is_active = 1', 'l.is_moderated = 1', $notOccupiedSql];
$params = [];

// Поиск: запрос вида "RR-123" / "RR123" — точный поиск по ID (см. бывший
// pages/search.php, теперь встроено прямо сюда). Иначе — обычный текстовый
// поиск по названию/городу/адресу.
$id_search = null;
if ($search_query !== '' && preg_match('/^RR-?0*(\d+)$/i', $search_query, $m)) {
    $id_search = (int)$m[1];
}
if ($id_search !== null) {
    $where[] = 'l.id = ?';
    $params[] = $id_search;
} elseif ($search_query !== '') {
    $where[] = '(l.title LIKE ? OR l.city LIKE ? OR l.address LIKE ?)';
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

if ($city !== '') {
    $where[] = 'l.city = ?';
    $params[] = $city;
}
if ($min_price !== '') {
    $where[] = 'l.price_month >= ?';
    $params[] = (float)$min_price;
}
if ($max_price !== '') {
    $where[] = 'l.price_month <= ?';
    $params[] = (float)$max_price;
}
if ($min_area !== '') {
    $where[] = '(l.width * l.depth) >= ?';
    $params[] = (float)$min_area;
}
if ($max_area !== '') {
    $where[] = '(l.width * l.depth) <= ?';
    $params[] = (float)$max_area;
}
if ($has_electricity) {
    $where[] = 'l.has_electricity = 1';
}
if ($has_wifi) {
    $where[] = 'l.has_wifi = 1';
}
if ($has_water) {
    $where[] = 'l.has_water = 1';
}
if ($traffic_min > 0) {
    $where[] = 'l.traffic_rating >= ?';
    $params[] = $traffic_min;
}
if ($space_type !== '') {
    $where[] = 'l.space_type = ?';
    $params[] = $space_type;
}
if ($access_hours !== '') {
    $where[] = 'l.access_hours = ?';
    $params[] = $access_hours;
}

$whereSql = implode(' AND ', $where);

// ---------- Подсчёт общего количества ----------
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM locations l WHERE $whereSql");
$stmt_count->execute($params);
$total = $stmt_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ---------- Основной запрос с LIMIT и OFFSET ----------
$sql = "SELECT l.*,
        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) as main_photo
        FROM locations l
        WHERE $whereSql
        ORDER BY {$sort_options[$sort]}
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$locations = $stmt->fetchAll();

// ★★★ МАППИНГ ТИПОВ ДЛЯ КРАСИВОГО ОТОБРАЖЕНИЯ ★★★
$space_types = [
    'retail'     => 'Торговый центр / Магазин',
    'office'     => 'Бизнес-центр / Офис',
    'gym'        => 'Спортзал / Фитнес-клуб',
    'hotel'      => 'Отель / Гостиница',
    'hospital'   => 'Больница / Медицинский центр',
    'transit'    => 'Вокзал / Аэропорт',
    'coworking'  => 'Коворкинг',
    'laundromat' => 'Прачечная / Химчистка',
    'auto'       => 'Автосалон / СТО',
    'warehouse'  => 'Склад / Логистика',
    'factory'    => 'Завод / Производство',
    'education'  => 'Учебное заведение (школа, вуз)',
    'cinema'     => 'Кинотеатр / Развлекательный центр',
    'cafe'       => 'Кафе / Ресторан',
    'bank'       => 'Банк / Финансовое учреждение',
    'post'       => 'Почта / Отделение связи',
    'park'       => 'Парк / Сквер',
    'stadium'    => 'Стадион / Спорткомплекс',
    'museum'     => 'Музей / Выставочный центр',
    'other'      => 'Другое'
];

$access_hours_options = ['24/7', '08:00-22:00', '09:00-21:00', '10:00-20:00', 'По договоренности'];

// Для повторного использования всех текущих фильтров в ссылках пагинации/сброса
$filterParams = array_filter($_GET, function ($k) {
    return $k !== 'page';
}, ARRAY_FILTER_USE_KEY);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Каталог локаций — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="catalog-page">
    <div class="catalog-container">
        <h1>Доступные локации</h1>
        <div class="catalog-subtitle">Найдено локаций: <?php echo $total; ?></div>

        <!-- Поиск и фильтры -->
        <form class="filters" method="GET">
            <div class="filters-row">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" name="q" placeholder="Город, тип помещения, район, ID (RR-00007)..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>

                <select name="space_type" class="select-pill" onchange="this.form.submit()">
                    <option value="">Любой тип</option>
                    <?php foreach ($space_types as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($space_type === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="traffic_min" class="select-pill" onchange="this.form.submit()">
                    <option value="">Любой трафик</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($traffic_min == $i) ? 'selected' : ''; ?>>
                            <?php echo $trafficLabels[$i]; ?> и выше
                        </option>
                    <?php endfor; ?>
                </select>

                <select name="sort" class="select-pill" onchange="this.form.submit()">
                    <option value="newest" <?php echo ($sort === 'newest') ? 'selected' : ''; ?>>Сначала новые</option>
                    <option value="price_asc" <?php echo ($sort === 'price_asc') ? 'selected' : ''; ?>>Цена: по возрастанию</option>
                    <option value="price_desc" <?php echo ($sort === 'price_desc') ? 'selected' : ''; ?>>Цена: по убыванию</option>
                    <option value="traffic_desc" <?php echo ($sort === 'traffic_desc') ? 'selected' : ''; ?>>Сначала проходимые</option>
                </select>

                <button type="submit" class="btn-filter">Найти</button>
                <?php if ($search_query !== '' || $city !== '' || $space_type !== '' || $has_electricity || $has_wifi || $has_water || $traffic_min > 0 || $min_price !== '' || $max_price !== '' || $min_area !== '' || $max_area !== '' || $access_hours !== ''): ?>
                    <a href="/pages/catalog.php" class="btn-reset">✕ Сбросить</a>
                <?php endif; ?>
            </div>

            <div class="filters-row filters-row-secondary">
                <label class="chip-checkbox">
                    <input type="checkbox" name="has_electricity" value="1" onchange="this.form.submit()" <?php echo $has_electricity ? 'checked' : ''; ?>> ⚡ Электричество
                </label>
                <label class="chip-checkbox">
                    <input type="checkbox" name="has_wifi" value="1" onchange="this.form.submit()" <?php echo $has_wifi ? 'checked' : ''; ?>> 📶 Wi-Fi
                </label>
                <label class="chip-checkbox">
                    <input type="checkbox" name="has_water" value="1" onchange="this.form.submit()" <?php echo $has_water ? 'checked' : ''; ?>> 🚰 Вода
                </label>

                <details class="more-filters">
                    <summary>Цена, площадь, часы доступа</summary>
                    <div class="more-filters-body">
                        <div class="more-filters-field">
                            <label>Цена от</label>
                            <input type="number" name="min_price" placeholder="1000" min="0" value="<?php echo htmlspecialchars($min_price); ?>">
                        </div>
                        <div class="more-filters-field">
                            <label>Цена до</label>
                            <input type="number" name="max_price" placeholder="10000" min="0" value="<?php echo htmlspecialchars($max_price); ?>">
                        </div>
                        <div class="more-filters-field">
                            <label>Площадь от, м²</label>
                            <input type="number" name="min_area" placeholder="0.5" min="0" step="0.1" value="<?php echo htmlspecialchars($min_area); ?>">
                        </div>
                        <div class="more-filters-field">
                            <label>Площадь до, м²</label>
                            <input type="number" name="max_area" placeholder="5" min="0" step="0.1" value="<?php echo htmlspecialchars($max_area); ?>">
                        </div>
                        <div class="more-filters-field">
                            <label>Часы доступа</label>
                            <select name="access_hours">
                                <option value="">Любые</option>
                                <?php foreach ($access_hours_options as $ah): ?>
                                    <option value="<?php echo htmlspecialchars($ah); ?>" <?php echo ($access_hours === $ah) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ah); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-filter btn-filter-small">Применить</button>
                    </div>
                </details>
            </div>
            <?php if ($city !== ''): ?>
                <input type="hidden" name="city" value="<?php echo htmlspecialchars($city); ?>">
            <?php endif; ?>
        </form>

        <!-- Быстрый выбор города -->
        <?php if (count($cityCounts) > 0): ?>
            <?php
                $cityLinkParams = array_filter($_GET, function ($k) {
                    return $k !== 'page' && $k !== 'city';
                }, ARRAY_FILTER_USE_KEY);
                $cityLinkQs = http_build_query($cityLinkParams);
            ?>
            <div class="city-chip-row">
                <a href="/pages/catalog.php<?php echo $cityLinkQs ? '?' . $cityLinkQs : ''; ?>" class="city-chip <?php echo $city === '' ? 'active' : ''; ?>">
                    Все города
                </a>
                <?php foreach ($cityCounts as $cc): ?>
                    <a href="/pages/catalog.php?<?php echo $cityLinkQs ? $cityLinkQs . '&' : ''; ?>city=<?php echo urlencode($cc['city']); ?>" class="city-chip <?php echo ($city === $cc['city']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cc['city']); ?> <span class="city-chip-count">(<?php echo $cc['cnt']; ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Список локаций -->
        <?php if (count($locations) > 0): ?>
            <div class="catalog-grid">
                <?php foreach ($locations as $loc): ?>
                    <?php $locHasFullAccess = $hasFullAccess || $loc['owner_id'] == $user_id; ?>
                    <div class="catalog-card">
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="catalog-card-link">
                            <?php if (!empty($loc['main_photo'])): ?>
                                <img src="/<?php echo htmlspecialchars($loc['main_photo']); ?>" alt="<?php echo htmlspecialchars($loc['title']); ?>">
                            <?php else: ?>
                                <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                            <?php endif; ?>
                            <div class="info">
                                <div class="title">
                                    <?php echo htmlspecialchars($loc['title']); ?>
                                    <?php if ($loc['is_moderated'] == 1 && $loc['is_active'] == 1): ?>
                                        <span class="verified-pill">✓ Проверено</span>
                                    <?php endif; ?>
                                </div>
                                <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ <span class="price-unit">/ мес</span></div>
                                <?php if ($locHasFullAccess): ?>
                                    <div class="address">📍 <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                <?php else: ?>
                                    <div class="address">📍 <?php echo htmlspecialchars($loc['city']); ?> <span class="address-locked">· точный адрес по подписке</span></div>
                                <?php endif; ?>

                                <div class="meta-row">
                                    <?php if (!empty($loc['space_type']) && isset($space_types[$loc['space_type']])): ?>
                                        <span class="meta-tag">🏢 <?php echo htmlspecialchars($space_types[$loc['space_type']]); ?></span>
                                    <?php endif; ?>
                                    <?php if ($loc['traffic_rating'] > 0): ?>
                                        <span class="meta-tag">🚶 <?php echo $trafficLabels[(int)$loc['traffic_rating']] ?? ''; ?> трафик</span>
                                    <?php endif; ?>
                                    <?php if (!empty($loc['width']) && !empty($loc['depth'])): ?>
                                        <span class="meta-tag">📐 <?php echo round($loc['width'] * $loc['depth'], 2); ?> м²</span>
                                    <?php endif; ?>
                                </div>

                                <div class="badges">
                                    <span class="id-badge">RR-<?php echo str_pad($loc['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    <?php if ($loc['has_electricity']): ?>
                                        <span class="amenity-badge electricity">⚡</span>
                                    <?php endif; ?>
                                    <?php if ($loc['has_wifi']): ?>
                                        <span class="amenity-badge wifi">📶</span>
                                    <?php endif; ?>
                                    <?php if ($loc['has_water']): ?>
                                        <span class="amenity-badge water">🚰</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="btn-card-cta">
                            <?php echo $locHasFullAccess ? '📩 Узнать подробнее' : '🔒 Узнать подробнее'; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($filterParams); ?>">←</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($filterParams); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($filterParams); ?>">→</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty">
                <h3>😕 Ничего не найдено</h3>
                <p>Попробуйте изменить параметры фильтра или <a href="/pages/add_location.php">добавьте свою локацию</a>.</p>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
