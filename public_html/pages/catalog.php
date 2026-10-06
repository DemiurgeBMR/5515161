<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// ========== ПОИСКОВЫЙ ЗАПРОС ==========
$search_query = trim($_GET['q'] ?? '');

$pdo = getDbConnection();

$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$user_id = $_SESSION['user_id'] ?? 0;
$is_operator = ($_SESSION['user_role'] ?? null) === 'operator';

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
// собственник открепляет оператора, условие само перестаёт выполняться и
// локация возвращается в каталог — отдельный флаг для этого не нужен.
$notOccupiedSql = "NOT EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active')";

// ---------- Города: полный список (для выпадающего списка в фильтрах) и
// топ-12 по числу активных локаций для строки быстрых фильтров-чипов ниже —
// оба независимы от остальных фильтров, это витрина, а не результат
// текущего поиска ----------
$allCityCounts = $pdo->query("
    SELECT city, COUNT(*) as cnt
    FROM locations l
    WHERE is_active = 1 AND is_moderated = 1 AND $notOccupiedSql
    GROUP BY city
    ORDER BY cnt DESC, city ASC
")->fetchAll();
$topCityCounts = array_slice($allCityCounts, 0, 12);

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

// Телефон: шторка фильтров показывает «Показать N предложений» и пересчитывает N
// при изменении полей — тем же запросом подсчёта, без выборки карточек и вёрстки.
if (isset($_GET['m_count'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['total' => (int)$total]);
    exit;
}

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

// Какие из показанных на этой странице локаций оператор уже разблокировал —
// один запрос на всю страницу вместо проверки на каждую карточку отдельно.
$unlockedIds = $is_operator ? rr_unlocked_location_ids($pdo, $user_id, array_column($locations, 'id')) : [];
$favoritedIds = $is_operator ? rr_favorited_location_ids($pdo, $user_id, array_column($locations, 'id')) : [];

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

// ---------- Телефон (≤768px): плитки категорий, строка «N локаций · город / сортировка»,
// шторки фильтров/города/сортировки, компактный пейджер. На десктопе эти блоки скрыты (.m-only). ----------
// Ссылка каталога с текущими фильтрами, в которых часть параметров заменена/убрана (пустые не тащим).
$mCatalogUrl = function (array $set = [], array $drop = []) use ($filterParams) {
    $p = array_filter($filterParams, function ($v, $k) use ($drop) {
        return !in_array($k, $drop, true) && $k !== 'm_count' && $v !== '' && $v !== null;
    }, ARRAY_FILTER_USE_BOTH);
    foreach ($set as $k => $v) {
        if ($v === '' || $v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    $qs = http_build_query($p);
    return '/pages/catalog.php' . ($qs !== '' ? '?' . $qs : '');
};
// Сколько фильтров шторки включено — счётчик на кнопке фильтров (поиск и сортировка не считаются)
$mActiveFilters = ($city !== '' ? 1 : 0) + ($space_type !== '' ? 1 : 0) + ($traffic_min > 0 ? 1 : 0)
    + ($access_hours !== '' ? 1 : 0) + (($min_price !== '' || $max_price !== '') ? 1 : 0)
    + (($min_area !== '' || $max_area !== '') ? 1 : 0) + $has_electricity + $has_wifi + $has_water;
// Плитки категорий: короткие подписи и иконки; показываем типы, по которым есть свободные локации
$mTypeTiles = [
    'retail' => ['Магазины', 'bag'], 'office' => ['Офисы', 'briefcase'], 'gym' => ['Фитнес', 'dumbbell'],
    'hotel' => ['Отели', 'bed'], 'hospital' => ['Медицина', 'cross'], 'transit' => ['Вокзалы', 'train'],
    'cafe' => ['Кафе', 'coffee'], 'coworking' => ['Коворкинги', 'users'], 'education' => ['Учёба', 'book'],
    'cinema' => ['Досуг', '@film'], 'auto' => ['Авто', 'wrench'], 'warehouse' => ['Склады', '@box'],
    'factory' => ['Заводы', '@factory'], 'bank' => ['Банки', 'card'], 'post' => ['Почта', 'mail'],
    'park' => ['Парки', '@tree'], 'stadium' => ['Стадионы', '@trophy'], 'museum' => ['Музеи', '@landmark'],
    'laundromat' => ['Прачечные', 'droplet'], 'other' => ['Другое', 'more'],
];
// Иконки, которых нет в includes/icons.php, — в том же линейном стиле
$mExtraIcons = [
    'film'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18M17 3v18M3 8h4M3 16h4M17 8h4M17 16h4M3 12h18"/>',
    'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    'factory'  => '<path d="M3 21V10l6 4v-4l6 4V5h6v16z"/><path d="M7 17h2M12 17h2M17 17h2"/>',
    'tree'     => '<path d="M12 22v-5"/><path d="M12 3 6 11h3l-4 6h14l-4-6h3z"/>',
    'trophy'   => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
    'landmark' => '<path d="M3 21h18M5 21v-9M9.5 21v-9M14.5 21v-9M19 21v-9M2 9l10-6 10 6z"/>',
];
$mIcon = function ($name) use ($mExtraIcons) {
    if ($name !== '' && $name[0] === '@') {
        return '<svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $mExtraIcons[substr($name, 1)] . '</svg>';
    }
    return rr_icon($name);
};
$mTypeCounts = [];
foreach ($pdo->query("SELECT space_type, COUNT(*) AS cnt FROM locations l WHERE l.is_active = 1 AND l.is_moderated = 1 AND $notOccupiedSql GROUP BY space_type") as $r) {
    $mTypeCounts[(string)$r['space_type']] = (int)$r['cnt'];
}
$mSortLabels = [
    'newest'       => 'Сначала новые',
    'price_asc'    => 'Сначала дешевле',
    'price_desc'   => 'Сначала дороже',
    'traffic_desc' => 'Сначала проходимые',
];
$mCredits = $is_operator ? rr_credits_summary($pdo, $user_id) : null;
// Подписи-«чипы» для полей шторки (выпадающие списки на телефоне превращаются в ряды чипов)
$mTypeChipLabels = [
    'retail' => 'Магазины и ТЦ', 'office' => 'Офисы', 'gym' => 'Фитнес', 'hotel' => 'Отели',
    'hospital' => 'Медицина', 'transit' => 'Вокзалы', 'cafe' => 'Кафе', 'coworking' => 'Коворкинги',
    'education' => 'Учебные заведения', 'cinema' => 'Кино и досуг', 'auto' => 'Автосалоны и СТО',
    'warehouse' => 'Склады', 'factory' => 'Заводы', 'bank' => 'Банки', 'post' => 'Почта',
    'park' => 'Парки', 'stadium' => 'Стадионы', 'museum' => 'Музеи', 'laundromat' => 'Прачечные', 'other' => 'Другое',
];
$mTypeChipsVisible = 6;   // остальные — под «Ещё N»
$mTypeKeys = array_keys($space_types);
$mTypeSelectedHidden = $space_type !== '' && array_search($space_type, $mTypeKeys, true) >= $mTypeChipsVisible;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Каталог локаций для вендинга — RR</title>
    <meta name="description" content="Каталог мест под вендинговые автоматы: фильтры по городу, цене и типу помещения. Подберите точку для установки или сдайте своё помещение в аренду.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Каталог локаций для вендинга — RR">
    <meta property="og:description" content="Места под вендинговые автоматы с фильтрами по городу, цене и типу помещения.">
    <meta property="og:url" content="<?php echo htmlspecialchars(SITE_URL); ?>/pages/catalog.php">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-pg-catalog">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="catalog-page">
    <div class="catalog-container">
        <h1>Доступные локации</h1>

        <!-- Поиск и фильтры: единая панель вместо разбросанных пилюль — по
             образцу общего блока фильтров Auto.ru: один блок, сгруппированные
             поля, один явный кнопка-сабмит вместо автопосыла формы при каждом
             изменении поля. -->
        <div class="filters-card">
        <form class="catalog-filters" method="GET" id="catFilterForm">
            <div class="search-box">
                <span class="filters-search-icon"><?php echo rr_icon('search'); ?></span>
                <input type="text" name="q" placeholder="Город, тип помещения, район, ID (RR-00007)..." value="<?php echo htmlspecialchars($search_query); ?>" enterkeyhint="search" aria-label="Поиск локаций">
            </div>
            <!-- Телефон: кнопка шторки фильтров рядом с поиском (счётчик — сколько фильтров включено) -->
            <button type="button" class="m-only cat-fbtn<?php echo $mActiveFilters ? ' is-active' : ''; ?>" data-m-sheet-open="catFilterSheet" aria-controls="catFilterSheet" aria-label="Фильтры<?php echo $mActiveFilters ? ': включено ' . $mActiveFilters : ''; ?>">
                <?php echo rr_icon('sliders'); ?>
                <?php if ($mActiveFilters): ?><i class="cat-fbtn-n" aria-hidden="true"><?php echo $mActiveFilters; ?></i><?php endif; ?>
            </button>

            <!-- На десктопе — обычный блок полей формы; на телефоне — высокая шторка «Фильтры»
                 (шапка со «Сбросить», прокручиваемое тело, липкая кнопка «Показать N»). -->
            <div class="m-sheet m-sheet--tall cat-fsheet" id="catFilterSheet" aria-labelledby="catFilterTitle">
            <div class="m-sheet-handle m-only" aria-hidden="true"></div>
            <div class="m-sheet-head m-only">
                <b id="catFilterTitle">Фильтры</b>
                <a href="<?php echo htmlspecialchars($mCatalogUrl([], ['city', 'space_type', 'traffic_min', 'access_hours', 'sort', 'min_price', 'max_price', 'min_area', 'max_area', 'has_electricity', 'has_wifi', 'has_water'])); ?>" class="m-sheet-link" data-cat-reset>Сбросить</a>
                <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
            </div>
            <div class="m-sheet-body catalog-filters cat-fsheet-body">
            <div class="filters-grid">
                <div class="filter-field">
                    <label>Город</label>
                    <select name="city">
                        <option value="">Все города</option>
                        <?php foreach ($allCityCounts as $cc): ?>
                            <option value="<?php echo htmlspecialchars($cc['city']); ?>" <?php echo ($city === $cc['city']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cc['city']); ?> (<?php echo $cc['cnt']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Тип помещения</label>
                    <select name="space_type">
                        <option value="">Любой тип</option>
                        <?php foreach ($space_types as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($space_type === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="m-only cat-chips<?php echo $mTypeSelectedHidden ? ' is-expanded' : ''; ?>" data-chips-for="space_type" role="group" aria-label="Тип помещения">
                        <button type="button" class="m-chip" data-value="" aria-pressed="<?php echo $space_type === '' ? 'true' : 'false'; ?>">Любой</button>
                        <?php foreach ($mTypeKeys as $i => $key): ?>
                            <button type="button" class="m-chip<?php echo $i >= $mTypeChipsVisible ? ' cat-chip-more' : ''; ?>" data-value="<?php echo $key; ?>" aria-pressed="<?php echo $space_type === $key ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($mTypeChipLabels[$key] ?? $space_types[$key]); ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="m-chip cat-chip-toggle" data-chips-toggle aria-expanded="<?php echo $mTypeSelectedHidden ? 'true' : 'false'; ?>"><span class="cat-chip-toggle-more">Ещё <?php echo count($mTypeKeys) - $mTypeChipsVisible; ?></span><span class="cat-chip-toggle-less">Свернуть</span> <?php echo rr_icon('chevron-down'); ?></button>
                    </div>
                </div>

                <div class="filter-field">
                    <label>Проходимость</label>
                    <select name="traffic_min">
                        <option value="">Любая</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($traffic_min == $i) ? 'selected' : ''; ?>><?php echo $trafficLabels[$i]; ?> и выше</option>
                        <?php endfor; ?>
                    </select>
                    <div class="m-only cat-chips" data-chips-for="traffic_min" role="group" aria-label="Проходимость">
                        <button type="button" class="m-chip" data-value="" aria-pressed="<?php echo $traffic_min <= 0 ? 'true' : 'false'; ?>">Любая</button>
                        <?php for ($i = 2; $i <= 5; $i++): ?>
                            <button type="button" class="m-chip" data-value="<?php echo $i; ?>" aria-pressed="<?php echo $traffic_min === $i ? 'true' : 'false'; ?>" aria-label="<?php echo $trafficLabels[$i]; ?><?php echo $i < 5 ? ' и выше' : ''; ?>"><?php echo rr_icon('star'); ?> <?php echo $i; ?><?php echo $i < 5 ? '+' : ''; ?></button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="filter-field">
                    <label>Часы доступа</label>
                    <select name="access_hours">
                        <option value="">Любые</option>
                        <?php foreach ($access_hours_options as $ah): ?>
                            <option value="<?php echo htmlspecialchars($ah); ?>" <?php echo ($access_hours === $ah) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ah); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="m-only cat-chips" data-chips-for="access_hours" role="group" aria-label="Часы доступа">
                        <button type="button" class="m-chip" data-value="" aria-pressed="<?php echo $access_hours === '' ? 'true' : 'false'; ?>">Любые</button>
                        <?php foreach ($access_hours_options as $ah): ?>
                            <button type="button" class="m-chip" data-value="<?php echo htmlspecialchars($ah); ?>" aria-pressed="<?php echo $access_hours === $ah ? 'true' : 'false'; ?>"><?php echo htmlspecialchars(str_replace([':00', '-'], ['', '–'], $ah)); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="filter-field">
                    <label>Сортировка</label>
                    <select name="sort">
                        <option value="newest" <?php echo ($sort === 'newest') ? 'selected' : ''; ?>>Сначала новые</option>
                        <option value="price_asc" <?php echo ($sort === 'price_asc') ? 'selected' : ''; ?>>Цена: по возрастанию</option>
                        <option value="price_desc" <?php echo ($sort === 'price_desc') ? 'selected' : ''; ?>>Цена: по убыванию</option>
                        <option value="traffic_desc" <?php echo ($sort === 'traffic_desc') ? 'selected' : ''; ?>>Сначала проходимые</option>
                    </select>
                    <div class="m-only cat-chips" data-chips-for="sort" role="group" aria-label="Сортировка">
                        <?php foreach ($mSortLabels as $key => $label): ?>
                            <button type="button" class="m-chip" data-value="<?php echo $key; ?>" aria-pressed="<?php echo $sort === $key ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="filters-grid filters-grid-range">
                <div class="filter-field">
                    <label>Цена от, ₽</label>
                    <input type="number" name="min_price" placeholder="1000" min="0" inputmode="numeric" value="<?php echo htmlspecialchars($min_price); ?>">
                </div>
                <div class="filter-field">
                    <label>Цена до, ₽</label>
                    <input type="number" name="max_price" placeholder="10000" min="0" inputmode="numeric" value="<?php echo htmlspecialchars($max_price); ?>">
                </div>
                <div class="filter-field">
                    <label>Площадь от, м²</label>
                    <input type="number" name="min_area" placeholder="0.5" min="0" step="0.1" inputmode="decimal" value="<?php echo htmlspecialchars($min_area); ?>">
                </div>
                <div class="filter-field">
                    <label>Площадь до, м²</label>
                    <input type="number" name="max_area" placeholder="5" min="0" step="0.1" inputmode="decimal" value="<?php echo htmlspecialchars($max_area); ?>">
                </div>
            </div>

            <div class="filters-bottom-row">
                <div class="filters-amenities">
                    <span class="m-only cat-flabel">Удобства</span>
                    <label class="chip-checkbox">
                        <input type="checkbox" name="has_electricity" value="1" <?php echo $has_electricity ? 'checked' : ''; ?>> <?php echo rr_icon('bolt'); ?> Электричество
                    </label>
                    <label class="chip-checkbox">
                        <input type="checkbox" name="has_wifi" value="1" <?php echo $has_wifi ? 'checked' : ''; ?>> <?php echo rr_icon('wifi'); ?> Wi-Fi
                    </label>
                    <label class="chip-checkbox">
                        <input type="checkbox" name="has_water" value="1" <?php echo $has_water ? 'checked' : ''; ?>> <?php echo rr_icon('droplet'); ?> Вода
                    </label>
                </div>
                <div class="filters-actions">
                    <?php if ($search_query !== '' || $city !== '' || $space_type !== '' || $has_electricity || $has_wifi || $has_water || $traffic_min > 0 || $min_price !== '' || $max_price !== '' || $min_area !== '' || $max_area !== '' || $access_hours !== ''): ?>
                        <a href="/pages/catalog.php" class="btn-reset"><?php echo rr_icon('x'); ?> Сбросить</a>
                    <?php endif; ?>
                    <button type="submit" class="btn-filter-primary"><?php echo rr_icon('search'); ?> Показать предложения</button>
                </div>
            </div>
            </div><!-- /.m-sheet-body -->
            <div class="m-sheet-foot m-only">
                <button type="submit" class="m-btn m-btn--block cat-fsheet-submit" id="catFilterSubmit" data-plural="предложение|предложения|предложений">Показать <?php echo (int)$total; ?> <?php echo rr_plural_ru((int)$total, 'предложение', 'предложения', 'предложений'); ?></button>
            </div>
            </div><!-- /.m-sheet -->
        </form>
            <!-- Телефон: строка-призыв в «шапке» каталога; оператору — сколько контактов осталось -->
            <div class="m-only cat-hero-row">
                <a href="/pages/how_it_works.php" class="cat-hero-link">Найди место для автомата<br>за 5 минут <?php echo rr_icon('chevron-right'); ?></a>
                <?php if ($mCredits !== null): ?>
                    <a href="/pages/subscription.php" class="cat-credits-chip" aria-label="Доступно контактов: <?php echo (int)$mCredits['total_available']; ?>"><?php echo rr_icon('unlock'); ?> <?php echo (int)$mCredits['total_available']; ?></a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Телефон: плитки категорий (тип помещения) — ссылки сохраняют остальные фильтры -->
        <nav class="m-only cat-tiles" aria-label="Категории">
            <a href="<?php echo htmlspecialchars($mCatalogUrl([], ['space_type', 'page'])); ?>" class="cat-tile<?php echo $space_type === '' ? ' is-on' : ''; ?>"<?php echo $space_type === '' ? ' aria-current="true"' : ''; ?>><span>Все</span><i><?php echo rr_icon('grid'); ?></i></a>
            <?php foreach ($mTypeTiles as $key => $tile): ?>
                <?php if (empty($mTypeCounts[$key]) && $space_type !== $key) continue; ?>
                <a href="<?php echo htmlspecialchars($mCatalogUrl(['space_type' => $key], ['page'])); ?>" class="cat-tile<?php echo $space_type === $key ? ' is-on' : ''; ?>"<?php echo $space_type === $key ? ' aria-current="true"' : ''; ?>><span><?php echo htmlspecialchars($tile[0]); ?></span><i><?php echo $mIcon($tile[1]); ?></i></a>
            <?php endforeach; ?>
        </nav>

        <!-- Телефон: «N локаций · город» и сортировка -->
        <div class="m-only cat-sortrow">
            <b class="cat-count"><?php echo (int)$total; ?> <?php echo rr_plural_ru((int)$total, 'локация', 'локации', 'локаций'); ?></b>
            <?php if (count($topCityCounts) > 0): ?>
                <button type="button" class="cat-sortrow-btn cat-sortrow-city" data-m-sheet-open="catCitySheet" aria-controls="catCitySheet"><?php echo rr_icon('map-pin'); ?><span><?php echo htmlspecialchars($city !== '' ? $city : 'Все города'); ?></span><?php echo rr_icon('chevron-down'); ?></button>
            <?php endif; ?>
            <button type="button" class="cat-sortrow-btn cat-sortrow-sort" data-m-sheet-open="catSortSheet" aria-controls="catSortSheet" aria-label="Сортировка: <?php echo htmlspecialchars($mSortLabels[$sort]); ?>"><span><?php echo htmlspecialchars($mSortLabels[$sort]); ?></span><?php echo rr_icon('chevron-down'); ?></button>
        </div>

        <div class="catalog-subtitle m-hide">Найдено локаций: <?php echo $total; ?></div>

        <!-- Быстрый выбор города -->
        <?php if (count($topCityCounts) > 0): ?>
            <?php
                $cityLinkParams = array_filter($_GET, function ($k) {
                    return $k !== 'page' && $k !== 'city';
                }, ARRAY_FILTER_USE_KEY);
                $cityLinkQs = http_build_query($cityLinkParams);
            ?>
            <!-- На десктопе — строка чипов городов; на телефоне — шторка «Город» (из строки «N локаций · город»). -->
            <div class="m-sheet cat-citysheet" id="catCitySheet" aria-labelledby="catCityTitle">
            <div class="m-sheet-handle m-only" aria-hidden="true"></div>
            <div class="m-sheet-head m-only">
                <b id="catCityTitle">Город</b>
                <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
            </div>
            <div class="m-sheet-body">
            <div class="city-chip-row">
                <a href="/pages/catalog.php<?php echo $cityLinkQs ? '?' . $cityLinkQs : ''; ?>" class="city-chip <?php echo $city === '' ? 'active' : ''; ?>">
                    Все города
                </a>
                <?php foreach ($topCityCounts as $cc): ?>
                    <a href="/pages/catalog.php?<?php echo $cityLinkQs ? $cityLinkQs . '&' : ''; ?>city=<?php echo urlencode($cc['city']); ?>" class="city-chip <?php echo ($city === $cc['city']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cc['city']); ?> <span class="city-chip-count">(<?php echo $cc['cnt']; ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
            </div>
            </div>
        <?php endif; ?>

        <!-- Телефон: шторка сортировки -->
        <div class="m-sheet m-only cat-sortsheet" id="catSortSheet" aria-labelledby="catSortTitle">
            <div class="m-sheet-handle" aria-hidden="true"></div>
            <div class="m-sheet-head">
                <b id="catSortTitle">Сортировка</b>
                <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
            </div>
            <div class="m-sheet-body">
                <ul class="m-menu">
                    <?php foreach ($mSortLabels as $key => $label): ?>
                        <li><a href="<?php echo htmlspecialchars($mCatalogUrl(['sort' => $key === 'newest' ? '' : $key], ['page'])); ?>" class="m-menu-item<?php echo $sort === $key ? ' is-accent' : ''; ?>"<?php echo $sort === $key ? ' aria-current="true"' : ''; ?>><span><?php echo htmlspecialchars($label); ?></span><?php if ($sort === $key) echo rr_icon('check'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Список локаций -->
        <?php if (count($locations) > 0): ?>
            <div class="catalog-grid">
                <?php foreach ($locations as $loc): ?>
                    <?php
                        $locHasFullAccess = $is_admin || $loc['owner_id'] == $user_id || in_array($loc['id'], $unlockedIds, true);
                        $isFavorited = in_array($loc['id'], $favoritedIds, true);
                    ?>
                    <div class="catalog-card">
                        <?php if ($is_operator): ?>
                            <button type="button" class="favorite-btn<?php echo $isFavorited ? ' active' : ''; ?>" data-location-id="<?php echo $loc['id']; ?>" aria-pressed="<?php echo $isFavorited ? 'true' : 'false'; ?>" aria-label="В избранное" title="<?php echo $isFavorited ? 'Убрать из избранного' : 'В избранное'; ?>"><?php echo rr_icon('heart'); ?></button>
                        <?php endif; ?>
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
                                        <span class="verified-pill"><?php echo rr_icon('check'); ?> Проверено</span>
                                    <?php endif; ?>
                                </div>
                                <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ <span class="price-unit">/ мес</span></div>
                                <?php if ($locHasFullAccess): ?>
                                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                <?php else: ?>
                                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city']); ?> <span class="address-locked">· точный адрес по подписке</span><?php echo rr_icon('lock', 'm-only cat-lock'); ?></div>
                                <?php endif; ?>

                                <div class="meta-row">
                                    <?php if (!empty($loc['space_type']) && isset($space_types[$loc['space_type']])): ?>
                                        <span class="meta-tag"><?php echo rr_icon('building'); ?> <?php echo htmlspecialchars($space_types[$loc['space_type']]); ?></span>
                                    <?php endif; ?>
                                    <?php if ($loc['traffic_rating'] > 0): ?>
                                        <span class="meta-tag"><?php echo rr_icon('walk'); ?> <?php echo $trafficLabels[(int)$loc['traffic_rating']] ?? ''; ?> трафик</span>
                                    <?php endif; ?>
                                    <?php if (!empty($loc['width']) && !empty($loc['depth'])): ?>
                                        <span class="meta-tag"><?php echo rr_icon('square'); ?> <?php echo round($loc['width'] * $loc['depth'], 2); ?> м²</span>
                                    <?php endif; ?>
                                </div>

                                <div class="badges">
                                    <?php if ($loc['traffic_rating'] > 0): $tr = max(0, min(5, (int)$loc['traffic_rating'])); ?>
                                        <span class="m-only cat-stars" role="img" aria-label="Проходимость: <?php echo $tr; ?> из 5"><?php echo str_repeat('★', $tr); ?><i><?php echo str_repeat('★', 5 - $tr); ?></i></span>
                                    <?php endif; ?>
                                    <span class="id-badge">RR-<?php echo str_pad($loc['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                    <?php if ($loc['has_electricity']): ?>
                                        <span class="amenity-badge electricity"><?php echo rr_icon('bolt'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($loc['has_wifi']): ?>
                                        <span class="amenity-badge wifi"><?php echo rr_icon('wifi'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($loc['has_water']): ?>
                                        <span class="amenity-badge water"><?php echo rr_icon('droplet'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="btn-card-cta">
                            <?php echo $locHasFullAccess ? rr_icon('arrow-right') . ' Узнать подробнее' : rr_icon('lock') . ' Узнать подробнее'; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
            <?php if ($total_pages > 1): ?>
                <!-- Телефон: компактный пейджер «‹ · 2 из 3 · ›» -->
                <nav class="m-only cat-pager" aria-label="Страницы">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars($mCatalogUrl($page - 1 > 1 ? ['page' => $page - 1] : [], ['page'])); ?>" class="cat-pager-btn" rel="prev"><?php echo rr_icon('chevron-left'); ?> Назад</a>
                    <?php else: ?>
                        <span class="cat-pager-btn is-disabled" aria-hidden="true"><?php echo rr_icon('chevron-left'); ?> Назад</span>
                    <?php endif; ?>
                    <span class="cat-pager-now"><b><?php echo $page; ?></b> из <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo htmlspecialchars($mCatalogUrl(['page' => $page + 1])); ?>" class="cat-pager-btn" rel="next">Далее <?php echo rr_icon('chevron-right'); ?></a>
                    <?php else: ?>
                        <span class="cat-pager-btn is-disabled" aria-hidden="true">Далее <?php echo rr_icon('chevron-right'); ?></span>
                    <?php endif; ?>
                </nav>
                <div class="pagination m-hide">
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
            <div class="empty m-empty cat-empty">
                <h3><?php echo rr_icon('frown'); ?> Ничего не найдено</h3>
                <p>Попробуйте изменить параметры фильтра или <a href="/pages/add_location.php">добавьте свою локацию</a>.</p>
                <a href="/pages/catalog.php" class="m-only m-btn cat-empty-reset"><?php echo rr_icon('refresh'); ?> Сбросить фильтры</a>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <script src="/assets/js/m/catalog.js"></script>
</body>
</html>
