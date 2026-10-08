<?php
require_once 'includes/session_bootstrap.php';
rr_session_start();
require_once 'config.php';

$pdo = getDbConnection();

// Последние 6 активных и прошедших модерацию локаций — уже занятые
// (за ними активно закреплён оператор) сюда не попадают, они больше не
// свободны для аренды (см. ту же логику в pages/catalog.php).
$stmt = $pdo->query("
    SELECT l.*, u.full_name as owner_name,
    (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
    FROM locations l
    JOIN users u ON l.owner_id = u.id
    WHERE l.is_active = 1 AND l.is_moderated = 1
      AND NOT EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active')
    ORDER BY l.created_at DESC
    LIMIT 6
");
$latest_locations = $stmt->fetchAll();

// Телефон (≤768px): призыв внизу главной зависит от роли (гость — сдать место, собственник — добавить, оператор — каталог)
$mRole = isset($_SESSION['user_id']) ? ($_SESSION['user_role'] ?? null) : null;
if ($mRole === 'owner') {
    $mCta = ['Есть ещё свободное место?', 'Добавьте его — операторы вендинга увидят его в каталоге и на карте.', '/pages/add_location.php', 'Добавить место'];
} elseif ($mRole === 'operator' || $mRole === 'admin') {
    $mCta = ['Ищете точку для автомата?', 'Фильтры по городу, цене, проходимости и типу помещения — в каталоге.', '/pages/catalog.php', 'Открыть каталог'];
} else {
    $mCta = ['Есть свободное место?', 'Разместите его на RR — операторы вендинга сами найдут вас и напишут.', '/pages/register.php?role=owner', 'Сдать место'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo SITE_NAME; ?> — площадки для вендинговых автоматов</title>
    <meta name="description" content="Riveg Rent — площадка для аренды мест под вендинговые автоматы. Собственники помещений размещают локации, операторы вендинга находят точки для установки.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars(SITE_NAME); ?>">
    <meta property="og:description" content="Аренда мест под вендинговые автоматы: собственники помещений и операторы вендинга находят друг друга.">
    <meta property="og:url" content="<?php echo htmlspecialchars(SITE_URL); ?>/">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-pg-home">
    <?php include 'includes/header.php'; ?>
    
    <main>
        <!-- Баннер -->
        <section class="hero">
            <h1>Найди место для вендинга за 5 минут</h1>
            <p>RR — маркетплейс аренды площадей под автоматы</p>
            <!-- Телефон: поиск по каталогу прямо с главной -->
            <form class="m-only home-search" action="/pages/catalog.php" method="GET" role="search">
                <?php echo rr_icon('search'); ?>
                <input type="search" name="q" placeholder="Город, тип помещения, район…" enterkeyhint="search" autocomplete="off" aria-label="Поиск локаций">
            </form>
            <div class="cta-buttons">
                <a href="/pages/register.php?role=owner" class="btn btn-primary">Сдам место</a>
                <a href="/pages/register.php?role=operator" class="btn btn-secondary">Хочу найти место</a>
            </div>
        </section>
        
        <!-- Свежие локации -->
        <section class="home-locations-preview">
            <h2><?php echo rr_icon('flame'); ?> Свежие предложения</h2>
            <?php if (count($latest_locations) > 0): ?>
                <a href="/pages/catalog.php" class="m-only m-section-link home-all-link">Все <?php echo rr_icon('chevron-right'); ?></a>
            <?php endif; ?>
            <?php if (count($latest_locations) > 0): ?>
                <div class="home-location-grid">
                    <?php foreach ($latest_locations as $loc): ?>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                            <div class="home-location-card">
                                <?php if (!empty($loc['main_photo'])): ?>
                                    <img src="/<?php echo $loc['main_photo']; ?>" alt="<?php echo htmlspecialchars($loc['title']); ?>">
                                <?php else: ?>
                                    <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                                <?php endif; ?>
                                <span class="m-only home-verified"><?php echo rr_icon('check'); ?> Проверено</span>
                                <div class="info">
                                    <div class="title"><?php echo htmlspecialchars($loc['title']); ?></div>
                                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                    
                                    <!-- ★★★ ID, площадь, трафик ★★★ -->
                                    <div class="meta-row">
                                        <span class="id-badge">ID: RR-<?php echo str_pad($loc['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                        <?php if (!empty($loc['width']) && !empty($loc['depth'])): ?>
                                            <span><?php echo rr_icon('square'); ?> <?php echo round($loc['width'] * $loc['depth'], 2); ?> м²</span>
                                        <?php endif; ?>
                                        <?php if ($loc['traffic_rating'] > 0): ?>
                                            <span>
                                                <?php echo rr_icon('walk'); ?>
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <span class="star <?php echo ($i <= $loc['traffic_rating']) ? 'filled' : ''; ?>">★</span>
                                                <?php endfor; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                                                        <div class="home-card-date" style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                                        <?php echo rr_icon('calendar'); ?> <?php echo formatDateRu($loc['updated_at']); ?>
                                    </div>
                                    
                                    <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ <span class="home-price-unit">/ мес</span></div>
                                    <div class="m-only home-card-row">
                                        <?php if ($loc['traffic_rating'] > 0): $tr = max(0, min(5, (int)$loc['traffic_rating'])); ?>
                                            <span class="cat-stars" role="img" aria-label="Проходимость: <?php echo $tr; ?> из 5"><?php echo str_repeat('★', $tr); ?><i><?php echo str_repeat('★', 5 - $tr); ?></i></span>
                                        <?php endif; ?>
                                        <span class="home-amen">
                                            <?php if ($loc['has_electricity']) echo rr_icon('bolt'); ?>
                                            <?php if ($loc['has_wifi']) echo rr_icon('wifi'); ?>
                                            <?php if ($loc['has_water']) echo rr_icon('droplet'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="view-all">
                    <a href="/pages/catalog.php">Смотреть все локации →</a>
                </div>
            <?php else: ?>
                <div class="empty-home">
                    <h3>Пока нет ни одной локации</h3>
                    <p>Станьте первым! <a href="/pages/add_location.php" style="color: #e94560;">Добавьте своё место</a></p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Телефон: «Как это работает» — три шага полосой с прокруткой -->
        <section class="m-only home-steps" aria-labelledby="homeStepsTitle">
            <div class="m-section-head">
                <h2 class="m-section-title" id="homeStepsTitle">Как это работает</h2>
                <a href="/pages/how_it_works.php" class="m-section-link">Подробнее <?php echo rr_icon('chevron-right'); ?></a>
            </div>
            <ol class="home-steps-list">
                <li class="home-step"><span class="home-step-n">1</span><b>Найдите место</b><span>Каталог и карта с фильтрами по городу, цене и проходимости.</span></li>
                <li class="home-step"><span class="home-step-n">2</span><b>Откройте контакт</b><span>Точный адрес и собственник — за один контакт, дальше чат по заявке.</span></li>
                <li class="home-step"><span class="home-step-n">3</span><b>Поставьте автомат</b><span>Договоритесь об условиях и согласуйте выезд в календаре.</span></li>
            </ol>
        </section>

        <!-- Телефон: призыв к действию -->
        <section class="m-only home-cta">
            <b class="home-cta-title"><?php echo htmlspecialchars($mCta[0]); ?></b>
            <p><?php echo htmlspecialchars($mCta[1]); ?></p>
            <a href="<?php echo htmlspecialchars($mCta[2]); ?>" class="m-btn m-btn--block"><?php echo htmlspecialchars($mCta[3]); ?></a>
        </section>
    </main>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>