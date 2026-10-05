<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

$stmt = $pdo->prepare("
    SELECT l.*,
           (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) as main_photo,
           EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active') as is_occupied
    FROM favorites f
    JOIN locations l ON l.id = f.location_id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

$unlockedIds = rr_unlocked_location_ids($pdo, $user_id, array_column($locations, 'id'));

$trafficLabels = [
    1 => 'Низкая',
    2 => 'Ниже среднего',
    3 => 'Средняя',
    4 => 'Высокая',
    5 => 'Максимальная',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Избранное — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="catalog-container">
        <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('heart'); ?> Избранные локации</h2>

        <?php if (count($locations) > 0): ?>
            <p class="page-intro">Локации, которые вы сохранили — <?php echo count($locations); ?>.</p>
            <div class="catalog-grid">
                <?php foreach ($locations as $loc): ?>
                    <?php $locHasFullAccess = $loc['owner_id'] == $user_id || in_array($loc['id'], $unlockedIds, true); ?>
                    <div class="catalog-card favorite-remove-scope">
                        <button type="button" class="favorite-btn favorite-btn-remove active" data-location-id="<?php echo $loc['id']; ?>" title="Убрать из избранного"><?php echo rr_icon('heart'); ?></button>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="catalog-card-link">
                            <?php if (!empty($loc['main_photo'])): ?>
                                <img src="/<?php echo htmlspecialchars($loc['main_photo']); ?>" alt="<?php echo htmlspecialchars($loc['title']); ?>">
                            <?php else: ?>
                                <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                            <?php endif; ?>
                            <div class="info">
                                <div class="title">
                                    <?php echo htmlspecialchars($loc['title']); ?>
                                    <?php if ($loc['is_occupied']): ?>
                                        <span class="occupied-pill"><?php echo rr_icon('lock'); ?> Занято</span>
                                    <?php elseif (!$loc['is_active'] || !$loc['is_moderated']): ?>
                                        <span class="occupied-pill"><?php echo rr_icon('eye-off'); ?> Недоступно</span>
                                    <?php endif; ?>
                                </div>
                                <div class="price"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽ <span class="price-unit">/ мес</span></div>
                                <?php if ($locHasFullAccess): ?>
                                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                                <?php else: ?>
                                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($loc['city']); ?> <span class="address-locked">· точный адрес по подписке</span></div>
                                <?php endif; ?>
                                <?php if ($loc['traffic_rating'] > 0): ?>
                                    <div class="meta-row">
                                        <span class="meta-tag"><?php echo rr_icon('walk'); ?> <?php echo $trafficLabels[(int)$loc['traffic_rating']] ?? ''; ?> трафик</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                        <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" class="btn-card-cta">
                            <?php echo $locHasFullAccess ? rr_icon('arrow-right') . ' Узнать подробнее' : rr_icon('lock') . ' Узнать подробнее'; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">
                <p>Вы пока не добавили ни одной локации в избранное.</p>
                <p><a href="/pages/catalog.php" class="accent-link">Найти локации</a></p>
            </div>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
