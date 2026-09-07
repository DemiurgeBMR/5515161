<?php
session_start();
require_once __DIR__ . '/../config.php';

// ========== ПОИСКОВЫЙ ЗАПРОС ==========
$search_query = trim($_GET['q'] ?? '');

$pdo = getDbConnection();

// Параметры фильтрации
$city = $_GET['city'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$has_electricity = isset($_GET['has_electricity']) ? 1 : 0;
$traffic_min = isset($_GET['traffic_min']) ? (int)$_GET['traffic_min'] : 0;

// ★★★ НОВЫЙ ФИЛЬТР: ТИП ПОМЕЩЕНИЯ ★★★
$space_type = $_GET['space_type'] ?? '';

// Пагинация
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// ---------- Построение запроса для подсчёта общего количества ----------
$sql_count = "SELECT COUNT(*) FROM locations l WHERE l.is_active = 1 AND l.is_moderated = 1";
$params = [];

if (!empty($city)) {
    $sql_count .= " AND l.city LIKE ?";
    $params[] = '%' . $city . '%';
}
if (!empty($min_price)) {
    $sql_count .= " AND l.price_month >= ?";
    $params[] = (float)$min_price;
}
if (!empty($max_price)) {
    $sql_count .= " AND l.price_month <= ?";
    $params[] = (float)$max_price;
}
if ($has_electricity) {
    $sql_count .= " AND l.has_electricity = 1";
}
if ($traffic_min > 0) {
    $sql_count .= " AND l.traffic_rating >= ?";
    $params[] = $traffic_min;
}
// ★★★ УСЛОВИЕ ПО ТИПУ ПОМЕЩЕНИЯ ★★★
if (!empty($space_type)) {
    $sql_count .= " AND l.space_type = ?";
    $params[] = $space_type;
}
// Поиск по тексту
if (!empty($search_query)) {
    $sql_count .= " AND (l.title LIKE ? OR l.city LIKE ? OR l.address LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total = $stmt_count->fetchColumn();
$total_pages = ceil($total / $per_page);

// ---------- Основной запрос с LIMIT и OFFSET ----------
$sql = "SELECT l.*, u.full_name as owner_name,
        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
        FROM locations l
        JOIN users u ON l.owner_id = u.id
        WHERE l.is_active = 1 AND l.is_moderated = 1";

// Повторяем условия
if (!empty($city)) {
    $sql .= " AND l.city LIKE ?";
}
if (!empty($min_price)) {
    $sql .= " AND l.price_month >= ?";
}
if (!empty($max_price)) {
    $sql .= " AND l.price_month <= ?";
}
if ($has_electricity) {
    $sql .= " AND l.has_electricity = 1";
}
if ($traffic_min > 0) {
    $sql .= " AND l.traffic_rating >= ?";
}
if (!empty($space_type)) {
    $sql .= " AND l.space_type = ?";
}
if (!empty($search_query)) {
    $sql .= " AND (l.title LIKE ? OR l.city LIKE ? OR l.address LIKE ?)";
}

$sql .= " ORDER BY l.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;

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
    
    <div class="catalog-container">
        <h1>📍 Доступные локации</h1>
        
        <!-- Отображение поискового запроса -->
        <?php if (!empty($search_query)): ?>
            <div class="search-info">
                Результаты по запросу: <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong>
                <a href="/pages/catalog.php" style="margin-left: 15px; color: #e94560; text-decoration: none;">Сбросить</a>
            </div>
        <?php endif; ?>
        
        <!-- Фильтры -->
        <form class="filters" method="GET">
            <!-- Скрытое поле для сохранения поискового запроса -->
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_query); ?>">
            
            <div class="filter-group">
                <label>Город</label>
                <input type="text" name="city" placeholder="Симферополь" value="<?php echo htmlspecialchars($city); ?>">
            </div>
            <div class="filter-group">
                <label>Цена от</label>
                <input type="number" name="min_price" placeholder="1000" value="<?php echo htmlspecialchars($min_price); ?>">
            </div>
            <div class="filter-group">
                <label>Цена до</label>
                <input type="number" name="max_price" placeholder="10000" value="<?php echo htmlspecialchars($max_price); ?>">
            </div>
            <div class="filter-group">
                <label>Электричество</label>
                <input type="checkbox" name="has_electricity" value="1" <?php echo $has_electricity ? 'checked' : ''; ?>>
            </div>
            <div class="filter-group">
                <label>Минимальный трафик</label>
                <select name="traffic_min">
                    <option value="">Любой</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($traffic_min == $i) ? 'selected' : ''; ?>>
                            <?php echo $i; ?> ★
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <!-- ★★★ ФИЛЬТР ПО ТИПУ ПОМЕЩЕНИЯ ★★★ -->
            <div class="filter-group">
                <label>Тип помещения</label>
                <select name="space_type">
                    <option value="">Любой</option>
                    <option value="retail" <?php echo ($space_type == 'retail') ? 'selected' : ''; ?>>Торговый центр / Магазин</option>
                    <option value="office" <?php echo ($space_type == 'office') ? 'selected' : ''; ?>>Бизнес-центр / Офис</option>
                    <option value="gym" <?php echo ($space_type == 'gym') ? 'selected' : ''; ?>>Спортзал / Фитнес-клуб</option>
                    <option value="hotel" <?php echo ($space_type == 'hotel') ? 'selected' : ''; ?>>Отель / Гостиница</option>
                    <option value="hospital" <?php echo ($space_type == 'hospital') ? 'selected' : ''; ?>>Больница / Медицинский центр</option>
                    <option value="transit" <?php echo ($space_type == 'transit') ? 'selected' : ''; ?>>Вокзал / Аэропорт</option>
                    <option value="coworking" <?php echo ($space_type == 'coworking') ? 'selected' : ''; ?>>Коворкинг</option>
                    <option value="laundromat" <?php echo ($space_type == 'laundromat') ? 'selected' : ''; ?>>Прачечная / Химчистка</option>
                    <option value="auto" <?php echo ($space_type == 'auto') ? 'selected' : ''; ?>>Автосалон / СТО</option>
                    <option value="warehouse" <?php echo ($space_type == 'warehouse') ? 'selected' : ''; ?>>Склад / Логистика</option>
                    <option value="factory" <?php echo ($space_type == 'factory') ? 'selected' : ''; ?>>Завод / Производство</option>
                    <option value="education" <?php echo ($space_type == 'education') ? 'selected' : ''; ?>>Учебное заведение (школа, вуз)</option>
                    <option value="cinema" <?php echo ($space_type == 'cinema') ? 'selected' : ''; ?>>Кинотеатр / Развлекательный центр</option>
                    <option value="cafe" <?php echo ($space_type == 'cafe') ? 'selected' : ''; ?>>Кафе / Ресторан</option>
                    <option value="bank" <?php echo ($space_type == 'bank') ? 'selected' : ''; ?>>Банк / Финансовое учреждение</option>
                    <option value="post" <?php echo ($space_type == 'post') ? 'selected' : ''; ?>>Почта / Отделение связи</option>
                    <option value="park" <?php echo ($space_type == 'park') ? 'selected' : ''; ?>>Парк / Сквер</option>
                    <option value="stadium" <?php echo ($space_type == 'stadium') ? 'selected' : ''; ?>>Стадион / Спорткомплекс</option>
                    <option value="museum" <?php echo ($space_type == 'museum') ? 'selected' : ''; ?>>Музей / Выставочный центр</option>
                    <option value="other" <?php echo ($space_type == 'other') ? 'selected' : ''; ?>>Другое</option>
                </select>
            </div>
            
            <button type="submit" class="btn-filter">Найти</button>
            <a href="/pages/catalog.php" class="btn-reset">Сбросить</a>
        </form>
        
        <!-- Список локаций -->
        <?php if (count($locations) > 0): ?>
            <div class="catalog-grid">
                <?php foreach ($locations as $loc): ?>
                    <div class="catalog-card">
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>">
                            <?php if (!empty($loc['main_photo'])): ?>
                                <img src="/<?php echo $loc['main_photo']; ?>" alt="<?php echo htmlspecialchars($loc['title']); ?>">
                            <?php else: ?>
                                <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                            <?php endif; ?>
                            <div class="info">
                                <div class="title"><?php echo htmlspecialchars($loc['title']); ?></div>
                                <div class="address">📍 <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                
<div style="color: #888; font-size: 13px; margin-top: 4px;">
    🗓️ <?php echo formatDateRu($loc['updated_at']); ?>
</div>

                                <!-- ★★★ ОТОБРАЖЕНИЕ ТИПА ПОМЕЩЕНИЯ ★★★ -->
                                <?php if (!empty($loc['space_type']) && isset($space_types[$loc['space_type']])): ?>
                                    <div class="space-type-label">
                                        🏢 <?php echo htmlspecialchars($space_types[$loc['space_type']]); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="meta-row">
                                    <span class="id-badge">ID: RR-<?php echo str_pad($loc['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    <?php if (!empty($loc['width']) && !empty($loc['depth'])): ?>
                                        <span>📐 <?php echo round($loc['width'] * $loc['depth'], 2); ?> м²</span>
                                    <?php endif; ?>
                                    <?php if ($loc['traffic_rating'] > 0): ?>
                                        <span>
                                            🚶 
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="star <?php echo ($i <= $loc['traffic_rating']) ? 'filled' : ''; ?>">★</span>
                                            <?php endfor; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ / мес</div>
                                <div class="badges">
                                    <?php if ($loc['has_electricity']): ?>
                                        <span class="badge electricity">⚡</span>
                                    <?php endif; ?>
                                    <?php if ($loc['has_wifi']): ?>
                                        <span class="badge wifi">📶</span>
                                    <?php endif; ?>
                                </div>
                                <div class="owner">👤 <?php echo htmlspecialchars($loc['owner_name']); ?></div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Пагинация -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query(array_filter($_GET, function($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>">←</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, function($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query(array_filter($_GET, function($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>">→</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="empty">
                <h3>😕 Ничего не найдено</h3>
                <p>Попробуйте изменить параметры фильтра или <a href="/pages/add_location.php" style="color:#e94560;">добавьте свою локацию</a>.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>