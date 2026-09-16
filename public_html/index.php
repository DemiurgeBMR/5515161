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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> — площадки для вендинговых автоматов</title>
    <meta name="description" content="Riveg Rent — площадка для аренды мест под вендинговые автоматы. Владельцы помещений размещают локации, операторы вендинга находят точки для установки.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars(SITE_NAME); ?>">
    <meta property="og:description" content="Аренда мест под вендинговые автоматы: владельцы помещений и операторы вендинга находят друг друга.">
    <meta property="og:url" content="<?php echo htmlspecialchars(SITE_URL); ?>/">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <main>
        <!-- Баннер -->
        <section class="hero">
            <h1>Найди место для вендинга за 5 минут</h1>
            <p>RR — маркетплейс аренды площадей под автоматы</p>
            <div class="cta-buttons">
                <a href="/pages/register.php?role=owner" class="btn btn-primary">Сдам место</a>
                <a href="/pages/register.php?role=operator" class="btn btn-secondary">Хочу найти место</a>
            </div>
        </section>
        
        <!-- Свежие локации -->
        <section class="home-locations-preview">
            <h2>🔥 Свежие предложения</h2>
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
                                <div class="info">
                                    <div class="title"><?php echo htmlspecialchars($loc['title']); ?></div>
                                    <div class="address">📍 <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                    
                                    <!-- ★★★ ID, площадь, трафик ★★★ -->
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
                                                                        <div style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                                        🗓️ <?php echo formatDateRu($loc['updated_at']); ?>
                                    </div>
                                    
                                    <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ / мес</div>
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
    </main>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>