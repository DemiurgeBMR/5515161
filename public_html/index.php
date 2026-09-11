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
    <title><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* Дополнительные стили для главной */
        .hero {
            text-align: center;
            padding: 80px 20px 60px;
            background: linear-gradient(135deg, #2d3436 0%, #000000 100%);
            color: white;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
        }
        .hero h1 {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .hero p {
            font-size: 20px;
            opacity: 0.8;
            margin-bottom: 30px;
        }
        .cta-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 14px 35px;
            border-radius: 30px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: 0.2s;
        }
        .btn-primary {
            background: #e94560;
            color: white;
        }
        .btn-primary:hover {
            background: #c73652;
            transform: scale(1.03);
        }
        .btn-secondary {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.25);
        }
        .locations-preview {
            max-width: 1200px;
            margin: 0 auto 60px;
            padding: 0 20px;
        }
        .locations-preview h2 {
            font-size: 32px;
            margin-bottom: 25px;
        }
        .location-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }
        .location-card {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            transition: 0.2s;
            position: relative;
        }
        .location-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.5);
            border-color: var(--border-strong, #3a3a45);
        }
        .location-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: #222;
        }
        .location-card .info {
            padding: 15px;
        }
        .location-card .title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text, #f2f2f5);
        }
        .location-card .address {
            color: var(--text-muted, #9a9aa5);
            font-size: 14px;
        }
        /* ★★★ Блок с ID, площадью и трафиком ★★★ */
        .location-card .meta-row {
            display: flex;
            gap: 10px;
            font-size: 13px;
            color: var(--text-muted, #9a9aa5);
            margin: 5px 0 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .location-card .meta-row .id-badge {
            background: var(--bg-elevated-2, #1c1c24);
            padding: 0 8px;
            border-radius: 12px;
            font-size: 11px;
            color: var(--text-muted, #9a9aa5);
        }
        .location-card .meta-row .star {
            color: var(--border-strong, #3a3a45);
        }
        .location-card .meta-row .star.filled {
            color: #f1c40f;
        }
        .location-card .price {
            font-size: 22px;
            color: #ff5c7a;
            font-weight: bold;
            margin-top: 6px;
        }
        .view-all {
            text-align: center;
            margin: 40px 0;
        }
        .view-all a {
            background: #e94560;
            color: white;
            padding: 12px 40px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: bold;
        }
        .view-all a:hover {
            background: #c73652;
        }
        .empty-home {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            margin-top: 30px;
        }
        .empty-home h3 {
            margin-bottom: 10px;
        }
        .location-card a {
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
    </style>
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
        <section class="locations-preview">
            <h2>🔥 Свежие предложения</h2>
            <?php if (count($latest_locations) > 0): ?>
                <div class="location-grid">
                    <?php foreach ($latest_locations as $loc): ?>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                            <div class="location-card">
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
                                                                        <div style="color: #888; font-size: 13px; margin-top: 4px;">
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