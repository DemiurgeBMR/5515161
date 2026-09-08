<?php
session_start();
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /pages/catalog.php');
    exit;
}

// ★★★ ЕСЛИ АДМИН — ПОКАЗЫВАЕМ БЕЗ ФИЛЬТРАЦИИ ★★★
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Имя владельца показываем только подписчикам (см. $hasFullAccess ниже) —
// сам JOIN безобиден, это просто SELECT, скрытие происходит в шаблоне.
if ($is_admin) {
    $sql = "
        SELECT l.*, ow.full_name as owner_name,
        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
        FROM locations l
        JOIN users ow ON ow.id = l.owner_id
        WHERE l.id = ?
    ";
    $params = [$id];
} else {
    // Для обычных пользователей: показываем, если активно ИЛИ если это владелец (даже неактивное)
    $sql = "
        SELECT l.*, ow.full_name as owner_name,
        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) as main_photo
        FROM locations l
        JOIN users ow ON ow.id = l.owner_id
        WHERE l.id = ?
          AND ( (l.is_active = 1 AND l.is_moderated = 1)
                OR (l.owner_id = ? AND (l.is_moderated = 0 OR l.is_active = 0)) )
    ";
    $params = [$id, $user_id];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$location = $stmt->fetch();

if (!$location) {
    header('Location: /pages/catalog.php');
    exit;
}

// ★★★ Флаг предпросмотра для владельца ★★★
$is_preview = false;
if ($location) {
    // Если объявление не активно или не промодерировано, и пользователь - владелец или админ
    if (($location['is_active'] == 0 || $location['is_moderated'] == 0) && ($is_admin || ($user_id == $location['owner_id']))) {
        $is_preview = true;
    }
}

// ★★★ Точный адрес, имя владельца и возможность написать ему — по подписке
// (или владельцу/админу своей же локации), без неё — только город
// (см. pages/subscription.php) ★★★
$isOwnListing = isset($_SESSION['user_id']) && $_SESSION['user_id'] == $location['owner_id'];
$hasFullAccess = $is_admin || $isOwnListing || currentUserHasSubscription();

// Блок обращения к владельцу показываем гостям (предложим войти) и
// операторам, которые не владеют этой локацией — самому владельцу и
// другим владельцам, листающим чужую локацию, писать самому себе/друг
// другу через аренду незачем.
$showInquiryBlock = !$is_admin && !$isOwnListing
    && (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'operator');

// Отметка "прошло модерацию" — то же самое условие, по которому объявление
// вообще попадает в публичный каталог (см. catalog.php), поэтому в самом
// каталоге она будет стоять всегда, а здесь корректно пропадёт для
// черновика/ожидающего модерации объявления, которое видит только его
// владелец или админ в режиме предпросмотра.
$isVerified = ($location['is_moderated'] == 1 && $location['is_active'] == 1);

// ★★★ ВЫЧИСЛЯЕМ ПЛОЩАДЬ ★★★
$area = null;
if (!empty($location['width']) && !empty($location['depth'])) {
    $area = round($location['width'] * $location['depth'], 2);
}

// ★★★ ПОЛУЧАЕМ РЕКОМЕНДАЦИИ (с кешированием) ★★★
$recommendations = [];
$cacheKey = 'rec_' . $id;
$cacheTtl = 3600;

$cached = getCached($cacheKey, $cacheTtl);
if ($cached !== null) {
    $recommendations = $cached;
} else {
    if ($location) {
        $current_city = $location['city'];
        $current_price = $location['price_month'];
        $current_type = $location['space_type'];
        $current_traffic = $location['traffic_rating'];

        $sql_rec = "
            SELECT l.*, 
                (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
            FROM locations l
            WHERE l.id != ?
              AND l.is_active = 1 
              AND l.is_moderated = 1
              AND l.city = ?
            ORDER BY 
                CASE WHEN l.space_type = ? THEN 0 ELSE 1 END,
                ABS(l.price_month - ?) ASC,
                ABS(l.traffic_rating - ?) ASC,
                l.updated_at DESC
            LIMIT 6
        ";

        $stmt_rec = $pdo->prepare($sql_rec);
        $stmt_rec->execute([$id, $current_city, $current_type, $current_price, $current_traffic]);
        $recommendations = $stmt_rec->fetchAll();

        if (count($recommendations) < 3) {
            $need = 6 - count($recommendations);
            $need = (int)$need;
            if ($need > 0) {
                $sql_rec_other = "
                    SELECT l.*,
                        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 LIMIT 1) as main_photo
                    FROM locations l
                    WHERE l.id != ?
                      AND l.is_active = 1 
                      AND l.is_moderated = 1
                      AND l.city != ?
                    ORDER BY 
                        CASE WHEN l.space_type = ? THEN 0 ELSE 1 END,
                        ABS(l.price_month - ?) ASC,
                        ABS(l.traffic_rating - ?) ASC,
                        l.updated_at DESC
                    LIMIT $need
                ";

                $stmt_rec_other = $pdo->prepare($sql_rec_other);
                $stmt_rec_other->execute([$id, $current_city, $current_type, $current_price, $current_traffic]);
                $other = $stmt_rec_other->fetchAll();
                $recommendations = array_merge($recommendations, $other);
            }
        }
    }

    setCache($cacheKey, $recommendations);
}

// ★★★ МАППИНГ ТИПОВ ПОМЕЩЕНИЙ ★★★
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

// Загружаем все фото локации
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $stmt_photos = $pdo->prepare("SELECT photo_path FROM location_photos WHERE location_id = ? AND is_main = 0 ORDER BY sort_order, id");
} else {
    $stmt_photos = $pdo->prepare("SELECT photo_path FROM location_photos WHERE location_id = ? AND is_main = 0 AND is_pending = 0 ORDER BY sort_order, id");
}
$stmt_photos->execute([$id]);
$photos = $stmt_photos->fetchAll();

// Увеличиваем счётчик просмотров — но не в режиме предпросмотра, иначе
// владелец/админ, листающий свой ещё не опубликованный черновик, накручивал
// бы публичную статистику просмотров до того, как объявление вообще стало видно.
if (!$is_preview) {
    $pdo->prepare("UPDATE locations SET views = views + 1 WHERE id = ?")->execute([$id]);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($location['title']); ?> — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="location-detail">
        <a href="/pages/catalog.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <?php if ($is_preview): ?>
    <div style="background: #fff3cd; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #f39c12;">
        <strong>👁️ Предпросмотр</strong> — это объявление ещё не опубликовано и видно только вам.
        <?php if ($location['is_moderated'] == 0): ?>
            <span style="display: inline-block; margin-left: 10px; background: #ffc107; color: #333; padding: 2px 12px; border-radius: 20px; font-size: 13px;">Ожидает модерации</span>
        <?php else: ?>
            <span style="display: inline-block; margin-left: 10px; background: #6c5ce7; color: white; padding: 2px 12px; border-radius: 20px; font-size: 13px;">Черновик</span>
        <?php endif; ?>
    </div>
<?php endif; ?>
        <div class="location-layout<?php echo $showInquiryBlock ? ' has-sidebar' : ''; ?>">
        <div class="detail-card">
            <!-- Главное фото -->
            <?php if ($isVerified): ?>
                <span class="verified-badge-photo">✓ Верифицировано</span>
            <?php endif; ?>
            <?php if (!empty($location['main_photo'])): ?>
                <img src="/<?php echo $location['main_photo']; ?>" alt="<?php echo htmlspecialchars($location['title']); ?>" class="main-photo">
            <?php else: ?>
                <img src="/assets/images/placeholder.jpg" alt="Нет фото" class="main-photo">
            <?php endif; ?>

<!-- Галерея дополнительных фото -->
<?php if (count($photos) > 0): ?>
    <div class="gallery">
        <?php foreach ($photos as $photo): ?>
            <img src="/<?php echo $photo['photo_path']; ?>" alt="Фото">
        <?php endforeach; ?>
    </div>
<?php endif; ?>
            
            <div class="info">
                <!-- ★★★ ID локации ★★★ -->
                <div style="color: #888; font-size: 14px; margin-bottom: 10px;">
                    📍 ID: RR-<?php echo str_pad($location['id'], 5, '0', STR_PAD_LEFT); ?>
                </div>

                <div class="title">
                    <?php echo htmlspecialchars($location['title']); ?>
                    <?php if ($isVerified): ?>
                        <span class="verified-pill">✓ Проверено</span>
                    <?php endif; ?>
                </div>
                <div class="price"><?php echo number_format($location['price_month'], 0, ',', ' '); ?> ₽ / месяц</div>
                <?php if ($hasFullAccess): ?>
                    <div class="address">📍 <?php echo htmlspecialchars($location['city'] . ', ' . $location['address']); ?></div>
                <?php else: ?>
                    <div class="address">
                        📍 <?php echo htmlspecialchars($location['city']); ?>
                        <a href="/pages/subscription.php" class="address-locked-hint">🔒 точный адрес — по подписке</a>
                    </div>
                <?php endif; ?>

<div style="color: #888; font-size: 14px; margin-top: 8px;">
🗓️ Добавлено: <?php echo formatDateRu($location['updated_at']); ?>
</div>
                
                <!-- ★★★ ТИП ПОМЕЩЕНИЯ (если есть) ★★★ -->
                <?php if (!empty($location['space_type']) && isset($space_types[$location['space_type']])): ?>
                    <div class="space-type-block">
                        🏢 <?php echo htmlspecialchars($space_types[$location['space_type']]); ?>
                    </div>
                <?php endif; ?>
                
                <!-- ★★★ Площадь и звёзды трафика ★★★ -->
<div class="meta-tags">
    <?php if ($area): ?>
        <span class="tag">📐 <?php echo $area; ?> м²</span>
    <?php endif; ?>
    <?php if ($location['traffic_rating'] > 0): ?>
        <span class="tag" style="display: inline-flex; align-items: center; gap: 6px;">
            🚶 Трафик: 
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star <?php echo ($i <= $location['traffic_rating']) ? 'filled' : ''; ?>">★</span>
            <?php endfor; ?>
            <span style="font-size: 16px; cursor: pointer; color: #e94560; margin-left: 4px;" onclick="openTrafficHelp()" title="Что означает каждая звезда?">❓</span>
        </span>
    <?php else: ?>
        <span class="tag">
            🚶 Трафик не указан
            <span style="font-size: 16px; cursor: pointer; color: #e94560; margin-left: 4px;" onclick="openTrafficHelp()" title="Что означает каждая звезда?">❓</span>
        </span>
    <?php endif; ?>
</div>
                
                <!-- Бейджики -->
                <div style="margin: 10px 0;">
                    <?php if ($location['has_electricity']): ?>
                        <span class="badge badge-electricity">⚡ Электричество</span>
                    <?php endif; ?>
                    <?php if ($location['has_wifi']): ?>
                        <span class="badge badge-wifi">📶 Wi-Fi</span>
                    <?php endif; ?>
                    <?php if ($location['has_water']): ?>
                        <span class="badge badge-water">🚰 Вода</span>
                    <?php endif; ?>
                    <?php if ($location['access_hours'] === '24/7'): ?>
                        <span class="badge badge-24h">🕒 Круглосуточно</span>
                    <?php else: ?>
                        <span class="badge badge-24h" style="background:#e8e8e8;color:#333;">🕒 <?php echo htmlspecialchars($location['access_hours']); ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- ★★★ Структурированное описание ★★★ -->
                <div class="description">
                    <?php if (!empty($location['description'])): ?>
                        <h4 style="margin-top: 15px; margin-bottom: 5px;">📌 Описание места</h4>
                        <?php 
                            // Разбиваем описание на абзацы по двойным переносам строк
                            $paragraphs = preg_split('/\n\s*\n/', $location['description']);
                            foreach ($paragraphs as $p):
                                $p = trim($p);
                                if (empty($p)) continue;
                                // Если абзац начинается с ключевых слов, делаем его заголовком
                                if (preg_match('/^(преимущества|особенности|для кого|расположение|инфраструктура|описание)/i', $p)) {
                                    echo '<h5>' . htmlspecialchars($p) . '</h5>';
                                } else {
                                    echo '<p>' . nl2br(htmlspecialchars($p)) . '</p>';
                                }
                            endforeach;
                        ?>
                    <?php else: ?>
                        <p style="color: #888;">Описание отсутствует.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Характеристики -->
                <div class="specs">
                    <?php if (!empty($location['width'])): ?>
                        <div class="spec-item"><span class="label">Ширина:</span> <span class="value"><?php echo $location['width']; ?> м</span></div>
                    <?php endif; ?>
                    <?php if (!empty($location['height'])): ?>
                        <div class="spec-item"><span class="label">Высота:</span> <span class="value"><?php echo $location['height']; ?> м</span></div>
                    <?php endif; ?>
                    <?php if (!empty($location['depth'])): ?>
                        <div class="spec-item"><span class="label">Глубина:</span> <span class="value"><?php echo $location['depth']; ?> м</span></div>
                    <?php endif; ?>
                    <div class="spec-item"><span class="label">Просмотров:</span> <span class="value"><?php echo $location['views']; ?></span></div>
                </div>
            </div>
        </div>

        <?php if ($showInquiryBlock): ?>
        <aside class="inquiry-sidebar">
            <div class="inquiry-card">
                <h3>Заинтересовала локация?</h3>
                <p class="inquiry-sub">С подпиской можно написать владельцу напрямую в один клик.</p>

                <div class="inquiry-price-row">
                    <span>Аренда в месяц</span>
                    <strong><?php echo number_format($location['price_month'], 0, ',', ' '); ?> ₽</strong>
                </div>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="/pages/login.php" class="btn-contact btn-block">Войдите, чтобы связаться</a>
                <?php elseif ($hasFullAccess): ?>
                    <div class="inquiry-owner">
                        <span>Владелец</span>
                        <strong><?php echo htmlspecialchars($location['owner_name']); ?></strong>
                    </div>
                    <a href="/pages/send_application.php?location_id=<?php echo $location['id']; ?>" class="btn-contact btn-block">📩 Отправить заявку на аренду</a>
                <?php else: ?>
                    <a href="/pages/subscription.php" class="btn-contact btn-block btn-subscribe">🔒 Подписка, чтобы связаться</a>
                <?php endif; ?>

                <ul class="inquiry-points">
                    <li>💬 RR передаёт ваше обращение владельцу — звонить самому не нужно</li>
                    <li>🤝 Условия аренды обсуждаются напрямую в чате с владельцем</li>
                    <li>🔒 Подписка открывает имя и контакт владельца на всех локациях</li>
                </ul>
            </div>
        </aside>
        <?php endif; ?>
        </div>

        <!-- Похожие объявления -->
        <?php if (count($recommendations) > 0): ?>
    <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;">
        <h3 style="margin-bottom: 15px;">🔍 Похожие объявления</h3>
        <div class="rec-grid">
            <?php foreach ($recommendations as $rec): ?>
                <a href="/pages/location.php?id=<?php echo $rec['id']; ?>" class="rec-card-link">
                    <div class="rec-card">
                        <?php if (!empty($rec['main_photo'])): ?>
                            <img src="/<?php echo $rec['main_photo']; ?>" alt="<?php echo htmlspecialchars($rec['title']); ?>">
                        <?php else: ?>
                            <img src="/assets/images/placeholder.jpg" alt="Нет фото">
                        <?php endif; ?>
                        <div class="rec-body">
                            <div class="rec-title"><?php echo htmlspecialchars($rec['title']); ?></div>
                            <div class="rec-city"><?php echo htmlspecialchars($rec['city']); ?></div>
                            <div class="rec-price"><?php echo number_format($rec['price_month'], 0, ',', ' '); ?> ₽</div>
                            <?php if ($rec['traffic_rating'] > 0): ?>
                                <div class="rec-traffic">
                                    🚶
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span style="color: <?php echo ($i <= $rec['traffic_rating']) ? '#f1c40f' : '#ddd'; ?>;">★</span>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
<!-- ★★★ МОДАЛЬНОЕ ОКНО С ПАМЯТКОЙ ★★★ -->
<div class="modal-overlay" id="trafficHelpModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeTrafficHelp()">&times;</button>
        <h3>🚶 Как оценить проходимость места?</h3>
        <p style="color:#555; margin-top:-5px;">Выберите уровень, который лучше всего описывает вашу локацию.</p>
        <table>
            <thead>
                <tr><th>Рейтинг</th><th>Где встречается</th><th>Трафик (чел/день)</th><th>Нюансы</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="stars-demo">★</span> Низкая</td>
                    <td>Малые офисы (&lt;50 чел), жилые дома, тихие коридоры</td>
                    <td>50–200</td>
                    <td>Мало людей, риск низкой окупаемости</td>
                </tr>
                <tr>
                    <td><span class="stars-demo">★★</span> Ниже среднего</td>
                    <td>Офисы (50–100 чел), гостиницы, точки "по пути"</td>
                    <td>200–500</td>
                    <td>Трафик есть, но люди часто спешат</td>
                </tr>
                <tr>
                    <td><span class="stars-demo">★★★</span> Средняя</td>
                    <td>Крупные офисы (>100 чел), склады, заводы, фитнес-клубы, университеты</td>
                    <td>500–3 000</td>
                    <td><strong>Хороший выбор:</strong> стабильная аудитория</td>
                </tr>
                <tr>
                    <td><span class="stars-demo">★★★★</span> Высокая</td>
                    <td>ТРЦ, парки развлечений, больницы, крупные офисные центры</td>
                    <td>3 000–10 000</td>
                    <td>Люди проводят время, высокий потенциал</td>
                </tr>
                <tr>
                    <td><span class="stars-demo">★★★★★</span> Максимальная</td>
                    <td>Аэропорты, ж/д вокзалы, туристические центры</td>
                    <td>10 000+</td>
                    <td><strong>Золотая жила,</strong> но аренда очень дорогая</td>
                </tr>
            </tbody>
        </table>
        <div class="note">
            <strong>💡 Важно!</strong>
            Оценивайте не только количество людей, но и <strong>время пребывания</strong> (стоят/ждут) и наличие <strong>альтернатив</strong> (конкуренты). Самые прибыльные места — где люди задерживаются на 10–30 минут.
        </div>
        <p style="text-align: right; margin-top: 15px; color:#888; font-size:13px;">Подсказка всегда доступна по ❓</p>
    </div>
</div>
<!-- ★★★ МОДАЛЬНОЕ ОКНО ДЛЯ ПРОСМОТРА ФОТО ★★★ -->
<div class="photo-modal" id="photoModal">
    <div class="photo-modal-content">
        <button class="photo-modal-close" onclick="closePhotoModal()">&times;</button>
        <button class="photo-modal-prev" onclick="prevPhoto()">&#10094;</button>
        <button class="photo-modal-next" onclick="nextPhoto()">&#10095;</button>
        <img id="modalPhoto" src="" alt="Фото">
        <div class="photo-modal-counter" id="photoCounter"></div>
    </div>
</div>
<script>
    function openTrafficHelp() {
        document.getElementById('trafficHelpModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeTrafficHelp() {
        document.getElementById('trafficHelpModal').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById('trafficHelpModal').addEventListener('click', function(e) {
        if (e.target === this) closeTrafficHelp();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeTrafficHelp();
    });
    // ★★★ ГАЛЕРЕЯ-КАРУСЕЛЬ ★★★
let photoList = [];
let currentPhotoIndex = 0;

function openPhotoModal(index) {
    // Собираем все фото (главное + галерея)
    const mainImg = document.querySelector('.main-photo');
    const galleryImgs = document.querySelectorAll('.gallery img');
    
    photoList = [];
    if (mainImg) photoList.push(mainImg.src);
    galleryImgs.forEach(img => photoList.push(img.src));
    
    if (photoList.length === 0) return;
    
    currentPhotoIndex = Math.min(index, photoList.length - 1);
    if (currentPhotoIndex < 0) currentPhotoIndex = 0;
    
    document.getElementById('modalPhoto').src = photoList[currentPhotoIndex];
    document.getElementById('photoCounter').textContent = (currentPhotoIndex + 1) + ' / ' + photoList.length;
    document.getElementById('photoModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closePhotoModal() {
    document.getElementById('photoModal').classList.remove('active');
    document.body.style.overflow = '';
}

function nextPhoto() {
    if (currentPhotoIndex < photoList.length - 1) {
        currentPhotoIndex++;
        document.getElementById('modalPhoto').src = photoList[currentPhotoIndex];
        document.getElementById('photoCounter').textContent = (currentPhotoIndex + 1) + ' / ' + photoList.length;
    }
}

function prevPhoto() {
    if (currentPhotoIndex > 0) {
        currentPhotoIndex--;
        document.getElementById('modalPhoto').src = photoList[currentPhotoIndex];
        document.getElementById('photoCounter').textContent = (currentPhotoIndex + 1) + ' / ' + photoList.length;
    }
}

// Закрыть по ESC и листать стрелками
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePhotoModal();
    if (e.key === 'ArrowRight') nextPhoto();
    if (e.key === 'ArrowLeft') prevPhoto();
});

// Клик по главному фото
document.querySelector('.main-photo')?.addEventListener('click', function() {
    openPhotoModal(0);
});

// Клик по миниатюрам в галерее (убираем старый onclick и вешаем новый)
document.querySelectorAll('.gallery img').forEach(function(img, idx) {
    // Удаляем старый обработчик, если он был прописан в HTML
    img.removeAttribute('onclick');
    img.addEventListener('click', function() {
        // +1 потому что первое фото уже есть в main-photo
        openPhotoModal(idx + 1);
    });
});
</script>
</body>
</html>