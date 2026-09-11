<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$revision_id = isset($_GET['revision_id']) ? (int)$_GET['revision_id'] : 0;
if ($revision_id <= 0) {
    header('Location: /admin/index.php');
    exit;
}

$pdo = getDbConnection();

// Получаем ревизию
$stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE id = ?");
$stmt->execute([$revision_id]);
$revision = $stmt->fetch();
if (!$revision || $revision['status'] !== 'pending') {
    header('Location: /admin/index.php');
    exit;
}

// Получаем текущие данные объявления
$stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
$stmt->execute([$revision['location_id']]);
$location = $stmt->fetch();
if (!$location) {
    header('Location: /admin/index.php');
    exit;
}

$newData = json_decode($revision['data'], true);
if (!$newData) {
    // если данные невалидны, показываем ошибку
    die('Ошибка: данные ревизии повреждены.');
}

// Маппинг полей для отображения
$fieldLabels = [
    'title' => 'Название',
    'address' => 'Адрес',
    'city' => 'Город',
    'description' => 'Описание',
    'price_month' => 'Цена (руб/мес)',
    'width' => 'Ширина (м)',
    'height' => 'Высота (м)',
    'depth' => 'Глубина (м)',
    'has_electricity' => 'Электричество',
    'has_wifi' => 'Wi-Fi',
    'has_water' => 'Вода',
    'access_hours' => 'Часы доступа',
    'traffic_rating' => 'Рейтинг трафика',
    'space_type' => 'Тип помещения'
];

$boolValues = ['Нет', 'Да'];
$spaceTypes = [
    'retail' => 'Торговый центр / Магазин',
    'office' => 'Бизнес-центр / Офис',
    'gym' => 'Спортзал / Фитнес-клуб',
    'hotel' => 'Отель / Гостиница',
    'hospital' => 'Больница / Медицинский центр',
    'transit' => 'Вокзал / Аэропорт',
    'coworking' => 'Коворкинг',
    'laundromat' => 'Прачечная / Химчистка',
    'auto' => 'Автосалон / СТО',
    'warehouse' => 'Склад / Логистика',
    'factory' => 'Завод / Производство',
    'education' => 'Учебное заведение (школа, вуз)',
    'cinema' => 'Кинотеатр / Развлекательный центр',
    'cafe' => 'Кафе / Ресторан',
    'bank' => 'Банк / Финансовое учреждение',
    'post' => 'Почта / Отделение связи',
    'park' => 'Парк / Сквер',
    'stadium' => 'Стадион / Спорткомплекс',
    'museum' => 'Музей / Выставочный центр',
    'other' => 'Другое'
];

function formatValue($field, $value, $spaceTypes, $boolValues) {
    if ($value === null || $value === '') return '<span style="color:#999;">(не указано)</span>';
    if ($field === 'has_electricity' || $field === 'has_wifi' || $field === 'has_water') {
        return $boolValues[(int)$value];
    }
    if ($field === 'space_type') {
        return htmlspecialchars($spaceTypes[$value] ?? $value);
    }
    if ($field === 'price_month') {
        return number_format($value, 0, ',', ' ') . ' ₽';
    }
    if ($field === 'traffic_rating') {
        $rating = max(0, min(5, (int)$value));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . ' (' . $rating . '/5)';
    }
    return htmlspecialchars($value);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Просмотр ревизии — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="changes-container">
        <a href="/admin/view_revisions.php?id=<?php echo $location['id']; ?>" class="btn-back">← Назад к списку ревизий</a>
        
        <div class="changes-card">
            <h2>📋 Ревизия #<?php echo $revision['id']; ?> для объявления #<?php echo $location['id']; ?></h2>
            <p style="color: #888; margin-bottom: 20px;">
                Объявление: <strong><?php echo htmlspecialchars($location['title']); ?></strong><br>
                Создана: <?php echo date('d.m.Y H:i', strtotime($revision['created_at'])); ?>
            </p>
            
            <?php
            // Сравниваем поля
            $hasAnyChange = false;
            foreach ($fieldLabels as $field => $label) {
                $oldValue = $location[$field] ?? null;
                $newValue = $newData[$field] ?? null;
                $isChanged = ($oldValue != $newValue);
                if ($isChanged) $hasAnyChange = true;
                ?>
                <div class="change-row">
                    <div class="change-label"><?php echo $label; ?></div>
                    <div class="change-values">
                        <?php if ($isChanged): ?>
                            <div class="change-old">
                                <span class="value"><?php echo formatValue($field, $oldValue, $spaceTypes, $boolValues); ?></span>
                            </div>
                            <div class="change-arrow">→</div>
                            <div class="change-new">
                                <span class="value"><?php echo formatValue($field, $newValue, $spaceTypes, $boolValues); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="change-same">
                                <?php echo formatValue($field, $oldValue, $spaceTypes, $boolValues); ?>
                                <span style="font-size:12px; color:#999;">(без изменений)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            }
            ?>

            <!-- Блок фото -->
            <?php
            $hasPhotoChanges = false;
            $deleteIds = $newData['delete_photos'] ?? [];
            $newPhotoPaths = $newData['new_photos'] ?? [];
            $hasPhotoChanges = !empty($deleteIds) || !empty($newPhotoPaths);
            ?>
            <?php if ($hasPhotoChanges): ?>
                <div class="change-row" style="flex-direction: column; align-items: stretch; padding: 15px 0;">
                    <div class="change-label" style="width: 100%; margin-bottom: 10px;">📷 Фотографии</div>
                    <div class="photo-section">
                        <?php if (!empty($newPhotoPaths)): ?>
                            <div style="margin-bottom: 10px;">
                                <strong style="color:#2ecc71;">➕ Будут добавлены:</strong>
                                <div class="photo-grid">
                                    <?php foreach ($newPhotoPaths as $path): ?>
                                        <div class="photo-item photo-add">
                                            <img src="/<?php echo htmlspecialchars($path); ?>" alt="Новое фото">
                                            <div class="label" style="color:#2ecc71;">Новое</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($deleteIds)): 
                            // Получаем пути удаляемых фото для отображения
                            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                            $stmtDel = $pdo->prepare("SELECT photo_path FROM location_photos WHERE id IN ($placeholders) AND location_id = ?");
                            $stmtDel->execute(array_merge($deleteIds, [$location['id']]));
                            $delPhotos = $stmtDel->fetchAll(PDO::FETCH_COLUMN);
                        ?>
                            <div>
                                <strong style="color:#e74c3c;">❌ Будут удалены:</strong>
                                <div class="photo-grid">
                                    <?php foreach ($delPhotos as $path): ?>
                                        <div class="photo-item photo-delete">
                                            <img src="/<?php echo htmlspecialchars($path); ?>" alt="Удаляемое фото">
                                            <div class="label" style="color:#e74c3c;">Удаляется</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$hasAnyChange && !$hasPhotoChanges): ?>
                <div class="no-changes">
                    <h3>Нет изменений в этой ревизии</h3>
                </div>
            <?php endif; ?>

            <div class="change-actions">
                <a href="/admin/actions.php?action=approve_revision&revision_id=<?php echo $revision['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" onclick="return confirm('Одобрить эту ревизию?')">✅ Одобрить</a>
                <a href="/admin/actions.php?action=reject_revision&revision_id=<?php echo $revision['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" onclick="return confirm('Отклонить эту ревизию?')">❌ Отклонить</a>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>