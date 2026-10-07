<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
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

// Имя собственника показываем только подписчикам (см. $hasFullAccess ниже) —
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
    // Для обычных пользователей: показываем, если активно ИЛИ если это собственник (даже неактивное)
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

// ★★★ Флаг предпросмотра для собственника ★★★
$is_preview = false;
if ($location) {
    // Если объявление не активно или не промодерировано, и пользователь - собственник или админ
    if (($location['is_active'] == 0 || $location['is_moderated'] == 0) && ($is_admin || ($user_id == $location['owner_id']))) {
        $is_preview = true;
    }
}

// ★★★ Точный адрес, имя собственника и возможность написать ему — после
// разблокировки этой конкретной локации за кредит (или собственнику/админу
// своей же локации), без неё — только город (см. pages/subscription.php) ★★★
$isOwnListing = isset($_SESSION['user_id']) && $_SESSION['user_id'] == $location['owner_id'];
$isOperator = ($_SESSION['user_role'] ?? null) === 'operator';
$unlockError = '';

if ($isOperator && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_location'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $unlockError = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } elseif (!rr_unlock_location($pdo, $user_id, $id)) {
        $unlockError = 'Не хватает кредитов на разблокировку.';
    }
}

$isUnlocked = $isOperator && rr_location_unlocked($pdo, $user_id, $id);
$hasFullAccess = $is_admin || $isOwnListing || $isUnlocked;
$creditsSummary = $isOperator ? rr_credits_summary($pdo, $user_id) : null;
$isFavorited = $isOperator ? !empty(rr_favorited_location_ids($pdo, $user_id, [$id])) : false;

// ★★★ Локация уже занята активно закреплённым оператором? ★★★
// Как только собственник закрепил оператора за точкой, она перестаёт быть
// свободной для аренды — каталог/карта/рекомендации её больше не
// показывают (см. pages/catalog.php, pages/map.php), а здесь, по прямой
// ссылке, вместо приглашения написать собственнику — статус "занято" для всех,
// кроме собственника, самого закреплённого оператора и админа.
$stmt = $pdo->prepare("SELECT operator_id FROM location_operators WHERE location_id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$id]);
$assignedOperatorId = $stmt->fetchColumn();
$isOccupied = $assignedOperatorId !== false;
$isAssignedOperator = $isOccupied && isset($_SESSION['user_id']) && $_SESSION['user_id'] == $assignedOperatorId;

// Аудитория бокового блока — гости (предложим войти) и операторы, которые
// не владеют этой локацией: самому собственнику и другим собственникам, листающим
// чужую локацию, писать самому себе/друг другу через аренду незачем.
$sidebarAudience = !$is_admin && !$isOwnListing
    && (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'operator');

// Если точка уже занята — этой же аудитории (кроме самого закреплённого
// оператора) вместо приглашения написать собственнику показываем статус
// "занято" (см. шаблон ниже); писать по уже занятой точке незачем.
$showInquiryBlock = $sidebarAudience && (!$isOccupied || $isAssignedOperator);
$showOccupiedBadge = $sidebarAudience && $isOccupied && !$isAssignedOperator;
$showInquirySidebar = $showInquiryBlock || $showOccupiedBadge;

// Отметка "прошло модерацию" — то же самое условие, по которому объявление
// вообще попадает в публичный каталог (см. catalog.php), поэтому в самом
// каталоге она будет стоять всегда, а здесь корректно пропадёт для
// черновика/ожидающего модерации объявления, которое видит только его
// собственник или админ в режиме предпросмотра.
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
                (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) as main_photo
            FROM locations l
            WHERE l.id != ?
              AND l.is_active = 1
              AND l.is_moderated = 1
              AND NOT EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active')
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
                        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) as main_photo
                    FROM locations l
                    WHERE l.id != ?
                      AND l.is_active = 1
                      AND l.is_moderated = 1
                      AND NOT EXISTS (SELECT 1 FROM location_operators lo WHERE lo.location_id = l.id AND lo.status = 'active')
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

// ===== Телефоны (≤ 768px): данные «экрана объявления» =====
// Разметка с классом m-only ниже на десктопе скрыта (components/_m-ui.css), десктоп не меняется.
// Стили — assets/css/pages/m/_m-location.css, поведение — assets/js/m/location.js.
$mPhotos = [];
if (!empty($location['main_photo'])) {
    $mPhotos[] = $location['main_photo'];
}
foreach ($photos as $ph) {
    $mPhotos[] = $ph['photo_path'];
}
$mPrice = number_format($location['price_month'], 0, ',', ' ');
$mNum = function ($v) { return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')); };
$mYesNo = function ($v) { return $v ? 'Есть' : 'Нет'; };
$mTraffic = (int) $location['traffic_rating'];
$mTrafficLabels = [1 => 'Низкая', 2 => 'Ниже среднего', 3 => 'Средняя', 4 => 'Высокая', 5 => 'Максимальная'];

$mSpecs = [];
if (!empty($location['space_type']) && isset($space_types[$location['space_type']])) {
    $mSpecs[] = ['icon' => 'building', 'label' => 'Тип помещения', 'value' => $space_types[$location['space_type']], 'wide' => true];
}
if ($area) {
    $mSpecs[] = ['icon' => 'square', 'label' => 'Площадь', 'value' => $mNum($area) . ' м²'];
}
if (!empty($location['width']) || !empty($location['depth']) || !empty($location['height'])) {
    $dims = [];
    foreach (['width', 'depth', 'height'] as $k) {
        $dims[] = !empty($location[$k]) ? $mNum($location[$k]) : '—';
    }
    $mSpecs[] = ['icon' => 'grid', 'label' => 'Ш × Г × В', 'value' => implode(' × ', $dims) . ' м'];
}
$mSpecs[] = ['icon' => 'bolt',    'label' => 'Электричество', 'value' => $mYesNo($location['has_electricity']), 'off' => !$location['has_electricity']];
$mSpecs[] = ['icon' => 'wifi',    'label' => 'Wi-Fi',         'value' => $mYesNo($location['has_wifi']),        'off' => !$location['has_wifi']];
$mSpecs[] = ['icon' => 'droplet', 'label' => 'Вода',          'value' => $mYesNo($location['has_water']),       'off' => !$location['has_water']];
$mSpecs[] = ['icon' => 'shield',  'label' => 'Охрана',        'value' => $mYesNo($location['has_security']),    'off' => !$location['has_security']];
$mSpecs[] = ['icon' => 'clock', 'label' => 'Доступ', 'wide' => true,
             'value' => $location['access_hours'] === '24/7' ? 'Круглосуточно' : ($location['access_hours'] !== '' && $location['access_hours'] !== null ? $location['access_hours'] : 'Не указан')];

// Заявка по этой точке уже есть — главное действие ведёт сразу в её чат, а не на повторную подачу
$mChatAppId = null;
if ($isOperator && $hasFullAccess) {
    $stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('cancelled', 'rejected', 'unassigned') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id, $user_id]);
    $mChatAppId = $stmt->fetchColumn() ?: null;
}

// Главное действие экрана — липкая панель внизу (цена слева, кнопка справа)
$mCta = null;
if ($isOwnListing) {
    $mCta = ['href' => '/pages/edit_location.php?id=' . $id, 'label' => 'Редактировать', 'icon' => 'edit'];
} elseif ($showOccupiedBadge) {
    $mCta = ['href' => '/pages/catalog.php', 'label' => 'Другие локации', 'icon' => 'search', 'ghost' => true];
} elseif ($showInquiryBlock) {
    if (!isset($_SESSION['user_id'])) {
        $mCta = ['href' => '/pages/login.php', 'label' => 'Войти и связаться', 'icon' => 'user'];
    } elseif ($mChatAppId) {
        $mCta = ['href' => '/pages/application_chat.php?application_id=' . (int) $mChatAppId, 'label' => 'Открыть чат', 'icon' => 'message-circle'];
    } elseif ($hasFullAccess) {
        $mCta = ['href' => '/pages/send_application.php?location_id=' . $id, 'label' => 'Подать заявку', 'icon' => 'send'];
    } elseif ($creditsSummary['total_available'] > 0) {
        $mCta = ['sheet' => 'mUnlockSheet', 'label' => 'Открыть контакт', 'icon' => 'unlock'];
    } else {
        $mCta = ['href' => '/pages/subscription.php', 'label' => 'Пополнить баланс', 'icon' => 'card'];
    }
}
$mBackHref = $isOwnListing ? '/pages/profile.php' : '/pages/catalog.php';

// Мини-карта: статичные плитки OpenStreetMap вокруг точки (без JS-библиотек). Координаты —
// только тем, кому открыт точный адрес (как на pages/map.php: карта не раздаёт адреса бесплатно).
$mMapTiles = [];
$mMapsUrl = null;
if ($hasFullAccess) {
    if ($location['latitude'] !== null && $location['longitude'] !== null) {
        $mLat = (float) $location['latitude'];
        $mLng = (float) $location['longitude'];
        $mZoom = 16;
        $mWorld = 256 * (2 ** $mZoom);
        $mPx = ($mLng + 180) / 360 * $mWorld;
        $mPy = (1 - log(tan(deg2rad($mLat)) + 1 / cos(deg2rad($mLat))) / M_PI) / 2 * $mWorld;
        // Плитки, покрывающие окно до 768 × 180 px с точкой в центре
        for ($tx = (int) floor(($mPx - 384) / 256); $tx <= (int) floor(($mPx + 384) / 256); $tx++) {
            for ($ty = (int) floor(($mPy - 90) / 256); $ty <= (int) floor(($mPy + 90) / 256); $ty++) {
                // Поддомены a/b/c — их разрешает CSP (img-src https://*.tile.openstreetmap.org, includes/session_bootstrap.php)
                $mMapTiles[] = ['src' => 'https://' . 'abc'[($tx + $ty) % 3] . '.tile.openstreetmap.org/' . $mZoom . '/' . $tx . '/' . $ty . '.png',
                                'left' => (int) round($tx * 256 - $mPx), 'top' => (int) round($ty * 256 - $mPy)];
            }
        }
        $mMapsUrl = 'https://yandex.ru/maps/?pt=' . $mLng . ',' . $mLat . '&z=17&l=map';
    } else {
        $mMapsUrl = 'https://yandex.ru/maps/?text=' . rawurlencode($location['city'] . ', ' . $location['address']);
    }
}
$mOwnerInitial = mb_strtoupper(mb_substr(trim((string) $location['owner_name']) !== '' ? trim($location['owner_name']) : 'С', 0, 1));

// Увеличиваем счётчик просмотров — но не в режиме предпросмотра, иначе
// собственник/админ, листающий свой ещё не опубликованный черновик, накручивал
// бы публичную статистику просмотров до того, как объявление вообще стало видно.
if (!$is_preview) {
    $pdo->prepare("UPDATE locations SET views = views + 1 WHERE id = ?")->execute([$id]);
}
?>
<?php
// Описание и картинка для меню превью (Open Graph) — без них ссылка на
// карточку, отправленная в мессенджер, разворачивается пустой, без текста
// и фото. Своего описания у локации может не быть — тогда собираем короткое
// из города и цены.
$ogDescription = trim($location['description'] ?? '');
if ($ogDescription === '') {
    $ogDescription = 'Место под вендинговый автомат в г. ' . $location['city']
        . ' — от ' . number_format((float) $location['price_month'], 0, '', ' ') . ' ₽/мес.';
}
$ogDescription = mb_substr($ogDescription, 0, 200, 'UTF-8');
$ogImage = !empty($location['main_photo']) ? SITE_URL . '/' . $location['main_photo'] : null;
$ogUrl = SITE_URL . '/pages/location.php?id=' . (int) $location['id'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($location['title']); ?> — RR</title>
    <meta name="description" content="<?php echo htmlspecialchars($ogDescription); ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($location['title']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($ogDescription); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($ogUrl); ?>">
    <?php if ($ogImage): ?>
        <meta property="og:image" content="<?php echo htmlspecialchars($ogImage); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-loc m-no-tabbar<?php echo $mCta ? ' m-has-cta' : ''; ?>">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="location-detail">
        <a href="/pages/catalog.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="flash-message"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
        <?php endif; ?>
        <?php if ($is_preview): ?>
    <div class="preview-notice">
        <strong><?php echo rr_icon('eye'); ?> Предпросмотр</strong> — это объявление ещё не опубликовано и видно только вам.
        <?php if ($location['is_moderated'] == 0): ?>
            <span class="preview-pill pending">Ожидает модерации</span>
        <?php else: ?>
            <span class="preview-pill draft">Черновик</span>
        <?php endif; ?>
    </div>
<?php endif; ?>
        <div class="location-layout<?php echo $showInquirySidebar ? ' has-sidebar' : ''; ?>">
        <div class="detail-card">
            <!-- Телефоны: листаемая галерея на всю ширину + панель «назад / поделиться / в избранное» поверх -->
            <div class="m-only m-loc-gallery">
                <div class="m-appbar m-loc-appbar">
                    <a href="<?php echo $mBackHref; ?>" class="m-appbar-back" data-m-back aria-label="Назад"><?php echo rr_icon('chevron-left'); ?></a>
                    <span class="m-loc-appbar-sp"></span>
                    <button type="button" class="m-appbar-act" data-m-share aria-label="Поделиться"><svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="m16 6-4-4-4 4"/><path d="M12 2v13"/></svg></button>
                    <?php if ($isOperator && !$isOwnListing): ?>
                        <button type="button" class="m-appbar-act favorite-btn m-loc-fav<?php echo $isFavorited ? ' active' : ''; ?>" data-location-id="<?php echo (int) $location['id']; ?>" aria-pressed="<?php echo $isFavorited ? 'true' : 'false'; ?>" aria-label="В избранное" title="<?php echo $isFavorited ? 'Убрать из избранного' : 'В избранное'; ?>"><?php echo rr_icon('heart'); ?></button>
                    <?php endif; ?>
                </div>
                <?php if ($mPhotos): ?>
                    <div class="m-loc-strip" data-m-strip aria-label="Фотографии">
                        <?php foreach ($mPhotos as $i => $src): ?>
                            <button type="button" class="m-loc-slide" data-m-photo="<?php echo $i; ?>" aria-label="Фото <?php echo $i + 1; ?> из <?php echo count($mPhotos); ?> — открыть на весь экран">
                                <img src="/<?php echo htmlspecialchars($src); ?>" alt="<?php echo htmlspecialchars($location['title']); ?>"<?php echo $i ? ' loading="lazy"' : ''; ?> decoding="async">
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($mPhotos) > 1): ?>
                        <span class="m-loc-count" aria-hidden="true"><b data-m-photo-idx>1</b> / <?php echo count($mPhotos); ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="m-loc-nophoto"><?php echo rr_icon('camera'); ?><span>Фото пока нет</span></div>
                <?php endif; ?>
            </div>
            <!-- Главное фото -->
            <?php if ($isVerified): ?>
                <span class="verified-badge-photo"><?php echo rr_icon('check'); ?> Верифицировано</span>
            <?php endif; ?>
            <?php if ($isOperator && !$isOwnListing): ?>
                <button type="button" class="favorite-btn favorite-btn-photo<?php echo $isFavorited ? ' active' : ''; ?>" data-location-id="<?php echo $location['id']; ?>" aria-pressed="<?php echo $isFavorited ? 'true' : 'false'; ?>" title="<?php echo $isFavorited ? 'Убрать из избранного' : 'В избранное'; ?>"><?php echo rr_icon('heart'); ?></button>
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
                <!-- Телефоны: цена, название, адрес, статусы -->
                <div class="m-only m-loc-head">
                    <div class="m-loc-price"><?php echo $mPrice; ?> ₽ <small>/ мес</small></div>
                    <h1 class="m-loc-title"><?php echo htmlspecialchars($location['title']); ?></h1>
                    <div class="m-loc-addr">
                        <?php echo rr_icon('map-pin'); ?>
                        <?php if ($hasFullAccess): ?>
                            <span><?php echo htmlspecialchars($location['city'] . ', ' . $location['address']); ?></span>
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($location['city']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$hasFullAccess && $showInquiryBlock): ?>
                        <a href="#mContact" class="m-loc-lockhint"><?php echo rr_icon('lock'); ?> Точный адрес — за 1 контакт</a>
                    <?php endif; ?>
                    <div class="m-loc-pills">
                        <?php if ($isVerified): ?>
                            <span class="m-pill is-info"><?php echo rr_icon('check'); ?> Проверено</span>
                        <?php endif; ?>
                        <?php if ($isOccupied): ?>
                            <span class="m-pill is-warning"><?php echo rr_icon('lock'); ?> Занято</span>
                        <?php endif; ?>
                        <?php if ($is_preview): ?>
                            <span class="m-pill is-warning"><?php echo $location['is_moderated'] == 0 ? 'На модерации' : 'Черновик'; ?></span>
                        <?php endif; ?>
                        <span class="m-pill is-muted">ID RR-<?php echo str_pad($location['id'], 5, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>

                <!-- ★★★ ID локации ★★★ -->
                <div class="location-id-line">
                    <?php echo rr_icon('map-pin'); ?> ID: RR-<?php echo str_pad($location['id'], 5, '0', STR_PAD_LEFT); ?>
                </div>

                <div class="title">
                    <?php echo htmlspecialchars($location['title']); ?>
                    <?php if ($isVerified): ?>
                        <span class="verified-pill"><?php echo rr_icon('check'); ?> Проверено</span>
                    <?php endif; ?>
                    <?php if ($isOccupied): ?>
                        <span class="occupied-pill"><?php echo rr_icon('lock'); ?> Занято</span>
                    <?php endif; ?>
                </div>
                <div class="price"><?php echo number_format($location['price_month'], 0, ',', ' '); ?> ₽ / месяц</div>
                <?php if ($hasFullAccess): ?>
                    <div class="address"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($location['city'] . ', ' . $location['address']); ?></div>
                <?php else: ?>
                    <div class="address">
                        <?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($location['city']); ?>
                        <a href="#unlock" class="address-locked-hint"><?php echo rr_icon('lock'); ?> точный адрес — за 1 контакт</a>
                    </div>
                <?php endif; ?>

                <?php if ($unlockError): ?>
                    <div class="error" role="alert"><?php echo htmlspecialchars($unlockError); ?></div>
                <?php endif; ?>

<div class="location-added-line">
<?php echo rr_icon('calendar'); ?> Добавлено: <?php echo formatDateRu($location['updated_at']); ?>
</div>
                
                <!-- ★★★ ТИП ПОМЕЩЕНИЯ (если есть) ★★★ -->
                <?php if (!empty($location['space_type']) && isset($space_types[$location['space_type']])): ?>
                    <div class="space-type-block">
                        <?php echo rr_icon('building'); ?> <?php echo htmlspecialchars($space_types[$location['space_type']]); ?>
                    </div>
                <?php endif; ?>
                
                <!-- ★★★ Площадь и звёзды трафика ★★★ -->
<div class="meta-tags">
    <?php if ($area): ?>
        <span class="tag"><?php echo rr_icon('square'); ?> <?php echo $area; ?> м²</span>
    <?php endif; ?>
    <?php if ($location['traffic_rating'] > 0): ?>
        <span class="tag traffic-tag">
            <?php echo rr_icon('walk'); ?> Трафик: 
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star <?php echo ($i <= $location['traffic_rating']) ? 'filled' : ''; ?>">★</span>
            <?php endfor; ?>
            <span class="traffic-help-icon-sm" onclick="openTrafficHelp()" title="Что означает каждая звезда?"><?php echo rr_icon('help-circle'); ?></span>
        </span>
    <?php else: ?>
        <span class="tag">
            <?php echo rr_icon('walk'); ?> Трафик не указан
            <span class="traffic-help-icon-sm" onclick="openTrafficHelp()" title="Что означает каждая звезда?"><?php echo rr_icon('help-circle'); ?></span>
        </span>
    <?php endif; ?>
</div>
                
                <!-- Бейджики -->
                <div class="badges-row">
                    <?php if ($location['has_electricity']): ?>
                        <span class="badge badge-electricity"><?php echo rr_icon('bolt'); ?> Электричество</span>
                    <?php endif; ?>
                    <?php if ($location['has_wifi']): ?>
                        <span class="badge badge-wifi"><?php echo rr_icon('wifi'); ?> Wi-Fi</span>
                    <?php endif; ?>
                    <?php if ($location['has_water']): ?>
                        <span class="badge badge-water"><?php echo rr_icon('droplet'); ?> Вода</span>
                    <?php endif; ?>
                    <?php if ($location['access_hours'] === '24/7'): ?>
                        <span class="badge badge-24h"><?php echo rr_icon('clock'); ?> Круглосуточно</span>
                    <?php else: ?>
                        <span class="badge badge-24h custom-hours"><?php echo rr_icon('clock'); ?> <?php echo htmlspecialchars($location['access_hours']); ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Телефоны: характеристики плитками -->
                <section class="m-only m-loc-sec" aria-labelledby="mSpecsTitle">
                    <h2 class="m-section-title" id="mSpecsTitle">Характеристики</h2>
                    <div class="m-loc-specs">
                        <?php foreach ($mSpecs as $s): ?>
                            <div class="m-loc-spec<?php echo !empty($s['off']) ? ' is-off' : ''; ?><?php echo !empty($s['wide']) ? ' is-wide' : ''; ?>">
                                <small><?php echo rr_icon($s['icon']); ?> <?php echo htmlspecialchars($s['label']); ?></small>
                                <b><?php echo htmlspecialchars($s['value']); ?></b>
                            </div>
                        <?php endforeach; ?>
                        <div class="m-loc-spec is-wide m-loc-traffic">
                            <span class="m-loc-spec-tx">
                                <small><?php echo rr_icon('walk'); ?> Проходимость</small>
                                <?php if ($mTraffic > 0): ?>
                                    <b><span class="m-loc-stars" aria-label="<?php echo $mTraffic; ?> из 5"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?php echo $i <= $mTraffic ? 'is-on' : ''; ?>" aria-hidden="true">★</span><?php endfor; ?></span> <?php echo $mTrafficLabels[$mTraffic] ?? ''; ?></b>
                                <?php else: ?>
                                    <b>Не указана</b>
                                <?php endif; ?>
                            </span>
                            <button type="button" class="m-loc-help" onclick="openTrafficHelp()" aria-label="Как оценить проходимость"><?php echo rr_icon('help-circle'); ?></button>
                        </div>
                    </div>
                </section>

                <?php if (!empty($location['description'])): ?>
                    <h2 class="m-only m-section-title m-loc-sec-title">Описание</h2>
                <?php elseif ($isOwnListing): ?>
                    <a href="/pages/edit_location.php?id=<?php echo (int) $location['id']; ?>" class="m-only m-card m-loc-nodesc">
                        <?php echo rr_icon('edit'); ?>
                        <span><b>Добавьте описание</b>Расскажите о трафике, розетке и доступе — с описанием заявок больше.</span>
                    </a>
                <?php endif; ?>
                <!-- ★★★ Структурированное описание ★★★ -->
                <div class="description<?php echo empty($location['description']) ? ' m-hide' : ''; ?>">
                    <?php if (!empty($location['description'])): ?>
                        <h4 class="description-heading">Описание места</h4>
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
                        <p class="description-empty">Описание отсутствует.</p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($location['description'])): ?>
                    <button type="button" class="m-only m-loc-more" data-m-desc-more aria-expanded="false" hidden>Показать полностью</button>
                <?php endif; ?>

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

                <!-- Телефоны: мини-карта -->
                <section class="m-only m-loc-sec" aria-labelledby="mMapTitle">
                    <h2 class="m-section-title" id="mMapTitle">Расположение</h2>
                    <?php if ($hasFullAccess): ?>
                        <a href="<?php echo htmlspecialchars($mMapsUrl); ?>" class="m-loc-map" target="_blank" rel="noopener" aria-label="Открыть точку на карте">
                            <?php if ($mMapTiles): ?>
                                <span class="m-loc-map-tiles" aria-hidden="true">
                                    <?php foreach ($mMapTiles as $t): ?>
                                        <img src="<?php echo htmlspecialchars($t['src']); ?>" alt="" width="256" height="256" loading="lazy" decoding="async" style="left:<?php echo $t['left']; ?>px;top:<?php echo $t['top']; ?>px">
                                    <?php endforeach; ?>
                                </span>
                                <span class="m-loc-map-attr" aria-hidden="true">© OpenStreetMap</span>
                            <?php endif; ?>
                            <span class="m-loc-map-pin" aria-hidden="true"><?php echo rr_icon('map-pin'); ?></span>
                        </a>
                        <div class="m-loc-map-row">
                            <span class="m-loc-map-addr"><?php echo htmlspecialchars($location['city'] . ', ' . $location['address']); ?></span>
                            <a href="<?php echo htmlspecialchars($mMapsUrl); ?>" class="m-btn m-btn--ghost m-btn--sm" target="_blank" rel="noopener"><?php echo rr_icon('map'); ?> В Картах</a>
                        </div>
                    <?php else: ?>
                        <a href="#mContact" class="m-loc-map is-locked">
                            <span class="m-loc-map-lock"><?php echo rr_icon('lock'); ?></span>
                            <span class="m-loc-map-locktx"><b><?php echo htmlspecialchars($location['city']); ?></b>Точный адрес и точка на карте откроются вместе с контактом</span>
                        </a>
                    <?php endif; ?>
                </section>

                <!-- Телефоны: собственник и связь с ним -->
                <?php if ($showInquirySidebar || $isOwnListing || $is_admin): ?>
                    <section class="m-only m-loc-sec" id="mContact" aria-labelledby="mContactTitle">
                        <h2 class="m-section-title" id="mContactTitle"><?php echo $isOwnListing ? 'Ваше объявление' : 'Собственник'; ?></h2>
                        <div class="m-card m-loc-owner">
                            <?php if ($isOwnListing): ?>
                                <div class="m-loc-owner-row">
                                    <span class="m-loc-ava" aria-hidden="true"><?php echo htmlspecialchars($mOwnerInitial); ?></span>
                                    <span class="m-loc-owner-who">
                                        <b><?php echo htmlspecialchars($location['owner_name']); ?></b>
                                        <small><?php echo $is_preview ? ($location['is_moderated'] == 0 ? 'Ждёт модерации — видно только вам' : 'Черновик — видно только вам') : 'Опубликовано · видят арендаторы'; ?></small>
                                    </span>
                                </div>
                                <div class="m-loc-owner-stats">
                                    <span><b><?php echo (int) $location['views']; ?></b> <?php echo rr_plural_ru((int) $location['views'], 'просмотр', 'просмотра', 'просмотров'); ?></span>
                                    <span>Размещено <?php echo formatDateRu($location['created_at']); ?></span>
                                </div>
                                <div class="m-btn-row">
                                    <a href="/pages/owner_applications.php" class="m-btn m-btn--ghost"><?php echo rr_icon('message-circle'); ?> Заявки</a>
                                    <a href="/pages/profile.php" class="m-btn m-btn--ghost"><?php echo rr_icon('building'); ?> Мои места</a>
                                </div>
                            <?php elseif ($showOccupiedBadge): ?>
                                <div class="m-loc-owner-row">
                                    <span class="m-loc-ava is-locked" aria-hidden="true"><?php echo rr_icon('lock'); ?></span>
                                    <span class="m-loc-owner-who">
                                        <b>Точка уже занята</b>
                                        <small>За локацией закреплён другой оператор</small>
                                    </span>
                                </div>
                                <p class="m-loc-owner-tx">Новые заявки на размещение по ней не принимаются — посмотрите похожие места ниже или в каталоге.</p>
                            <?php elseif ($hasFullAccess): ?>
                                <div class="m-loc-owner-row">
                                    <span class="m-loc-ava" aria-hidden="true"><?php echo htmlspecialchars($mOwnerInitial); ?></span>
                                    <span class="m-loc-owner-who">
                                        <b><?php echo htmlspecialchars($location['owner_name']); ?></b>
                                        <small>Собственник<?php if ($isOperator): ?> · <span class="m-loc-open"><?php echo rr_icon('unlock'); ?> контакт открыт</span><?php endif; ?></small>
                                    </span>
                                </div>
                                <?php if ($isOperator): ?>
                                    <p class="m-loc-owner-tx">Связь — через чат RR: заявка и все ответы собственника приходят в «Заявки» и в уведомления.</p>
                                    <?php if ($mChatAppId): ?>
                                        <a href="/pages/application_chat.php?application_id=<?php echo (int) $mChatAppId; ?>" class="m-btn m-btn--block"><?php echo rr_icon('message-circle'); ?> Открыть чат</a>
                                    <?php else: ?>
                                        <a href="/pages/send_application.php?location_id=<?php echo (int) $location['id']; ?>" class="m-btn m-btn--block"><?php echo rr_icon('send'); ?> Написать собственнику</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="m-loc-owner-row">
                                    <span class="m-loc-ava is-locked" aria-hidden="true"><?php echo rr_icon('lock'); ?></span>
                                    <span class="m-loc-owner-who">
                                        <b>Контакт скрыт</b>
                                        <small><?php if (!isset($_SESSION['user_id'])): ?>Доступен арендаторам после входа<?php elseif ($creditsSummary['total_available'] > 0): ?>У вас <?php echo (int) $creditsSummary['total_available']; ?> <?php echo rr_plural_ru($creditsSummary['total_available'], 'контакт', 'контакта', 'контактов'); ?><?php else: ?>Контакты закончились<?php endif; ?></small>
                                    </span>
                                </div>
                                <ul class="m-loc-points">
                                    <li><?php echo rr_icon('user'); ?> Имя собственника и точный адрес</li>
                                    <li><?php echo rr_icon('message-circle'); ?> Заявка и чат напрямую — звонить не нужно</li>
                                    <li><?php echo rr_icon('unlock'); ?> Открывается навсегда, даже если контакты закончатся</li>
                                </ul>
                                <?php if (!isset($_SESSION['user_id'])): ?>
                                    <div class="m-btn-row">
                                        <a href="/pages/login.php" class="m-btn">Войти</a>
                                        <a href="/pages/register.php" class="m-btn m-btn--ghost">Регистрация</a>
                                    </div>
                                <?php elseif ($creditsSummary['total_available'] > 0): ?>
                                    <button type="button" class="m-btn m-btn--block" data-m-sheet-open="mUnlockSheet" aria-haspopup="dialog"><?php echo rr_icon('unlock'); ?> Открыть контакт</button>
                                    <a href="/pages/subscription.php" class="m-loc-owner-link">Тарифы и баланс</a>
                                <?php else: ?>
                                    <a href="/pages/subscription.php" class="m-btn m-btn--block"><?php echo rr_icon('card'); ?> Пополнить баланс</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!$isOwnListing): ?>
                <p class="m-only m-loc-meta">
                    <span><?php echo rr_icon('calendar'); ?> Размещено <?php echo formatDateRu($location['created_at']); ?></span>
                    <span><?php echo rr_icon('eye'); ?> <?php echo (int) $location['views']; ?> <?php echo rr_plural_ru((int) $location['views'], 'просмотр', 'просмотра', 'просмотров'); ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($showInquiryBlock): ?>
        <aside class="inquiry-sidebar" id="unlock">
            <div class="inquiry-card">
                <h3>Заинтересовала локация?</h3>
                <p class="inquiry-sub">Разблокируйте контакт собственника за 1 кредит — дальше пишите напрямую в один клик.</p>

                <div class="inquiry-price-row">
                    <span>Аренда в месяц</span>
                    <strong><?php echo number_format($location['price_month'], 0, ',', ' '); ?> ₽</strong>
                </div>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="/pages/login.php" class="btn-contact btn-block">Войдите, чтобы связаться</a>
                <?php elseif ($hasFullAccess): ?>
                    <div class="inquiry-owner">
                        <span>Собственник</span>
                        <strong><?php echo htmlspecialchars($location['owner_name']); ?></strong>
                    </div>
                    <a href="/pages/send_application.php?location_id=<?php echo $location['id']; ?>" class="btn-contact btn-block"><?php echo rr_icon('arrow-right'); ?> Отправить заявку на аренду</a>
                <?php elseif ($creditsSummary['total_available'] > 0): ?>
                    <form method="POST" id="unlockForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="unlock_location" value="1">
                        <button type="submit" class="btn-contact btn-block">
                            <?php echo rr_icon('lock'); ?> Разблокировать контакт (<?php echo $creditsSummary['total_available']; ?> доступно)
                        </button>
                    </form>
                <?php else: ?>
                    <a href="/pages/subscription.php" class="btn-contact btn-block btn-subscribe"><?php echo rr_icon('lock'); ?> Кредиты закончились — пополнить</a>
                <?php endif; ?>

                <ul class="inquiry-points">
                    <li><?php echo rr_icon('message-circle'); ?> RR передаёт ваше обращение собственнику — звонить самому не нужно</li>
                    <li><?php echo rr_icon('check'); ?> Условия аренды обсуждаются напрямую в чате с собственником</li>
                    <li><?php echo rr_icon('lock'); ?> Разблокировка этой карточки открывает адрес и контакт навсегда, даже если кредиты потом закончатся</li>
                </ul>
            </div>
        </aside>
        <?php elseif ($showOccupiedBadge): ?>
        <aside class="inquiry-sidebar">
            <div class="inquiry-card inquiry-card-occupied">
                <h3><?php echo rr_icon('lock'); ?> Точка уже занята</h3>
                <p class="inquiry-sub">
                    За этой локацией уже закреплён другой оператор, поэтому она недоступна для новых
                    заявок на размещение.
                </p>
                <a href="/pages/catalog.php" class="btn-contact btn-block btn-subscribe"><?php echo rr_icon('search'); ?> Смотреть другие локации</a>
            </div>
        </aside>
        <?php endif; ?>
        </div>

        <!-- Похожие объявления -->
        <?php if (count($recommendations) > 0): ?>
    <div class="similar-listings-section">
        <h3 class="similar-listings-title"><?php echo rr_icon('search'); ?> Похожие объявления</h3>
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
                                    <?php echo rr_icon('walk'); ?>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="star <?php echo ($i <= $rec['traffic_rating']) ? 'filled' : ''; ?>">★</span>
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
<?php if ($mCta): ?>
<!-- Телефоны: липкая панель — цена и главное действие -->
<div class="m-only m-sticky-cta m-loc-cta">
    <div class="m-sticky-cta-price"><?php echo $mPrice; ?> ₽<small>в месяц</small></div>
    <?php if (!empty($mCta['sheet'])): ?>
        <button type="button" class="m-btn" data-m-sheet-open="<?php echo $mCta['sheet']; ?>" aria-haspopup="dialog"><?php echo rr_icon($mCta['icon']); ?> <?php echo htmlspecialchars($mCta['label']); ?></button>
    <?php else: ?>
        <a href="<?php echo htmlspecialchars($mCta['href']); ?>" class="m-btn<?php echo !empty($mCta['ghost']) ? ' m-btn--ghost' : ''; ?>"><?php echo rr_icon($mCta['icon']); ?> <?php echo htmlspecialchars($mCta['label']); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($isOperator && !$hasFullAccess && $showInquiryBlock && $creditsSummary['total_available'] > 0): ?>
<!-- Телефоны: подтверждение траты кредита (кнопка отправляет десктопную форму #unlockForm) -->
<div class="m-sheet m-only" id="mUnlockSheet" role="dialog" aria-modal="true" aria-labelledby="mUnlockTitle" aria-hidden="true">
    <div class="m-sheet-handle" aria-hidden="true"></div>
    <div class="m-sheet-head">
        <b id="mUnlockTitle">Открыть контакт?</b>
        <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
    </div>
    <div class="m-sheet-body">
        <p class="m-loc-sheet-tx">Спишется <b>1 контакт</b> — доступно <?php echo (int) $creditsSummary['total_available']; ?>. Для этой точки откроются:</p>
        <ul class="m-loc-points">
            <li><?php echo rr_icon('map-pin'); ?> Точный адрес и точка на карте</li>
            <li><?php echo rr_icon('user'); ?> Имя собственника</li>
            <li><?php echo rr_icon('message-circle'); ?> Заявка и чат с собственником</li>
            <li><?php echo rr_icon('unlock'); ?> Навсегда — даже если контакты закончатся</li>
        </ul>
    </div>
    <div class="m-sheet-foot">
        <button type="submit" form="unlockForm" class="m-btn m-btn--block"><?php echo rr_icon('unlock'); ?> Открыть за 1 контакт</button>
    </div>
</div>
<?php endif; ?>
<!-- ★★★ МОДАЛЬНОЕ ОКНО С ПАМЯТКОЙ ★★★ -->
<div class="modal-overlay" id="trafficHelpModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeTrafficHelp()" aria-label="Закрыть">&times;</button>
        <h3><?php echo rr_icon('walk'); ?> Как оценить проходимость места?</h3>
        <p class="traffic-modal-subtitle">Выберите уровень, который лучше всего описывает вашу локацию.</p>
        <table class="m-table-cards m-loc-traffic-table">
            <thead>
                <tr><th>Рейтинг</th><th>Где встречается</th><th>Трафик (чел/день)</th><th>Нюансы</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td class="m-cell-title" data-label=""><span class="stars-demo">★</span> Низкая</td>
                    <td data-label="Где встречается">Малые офисы (&lt;50 чел), жилые дома, тихие коридоры</td>
                    <td data-label="Трафик, чел/день">50–200</td>
                    <td data-label="Нюансы">Мало людей, риск низкой окупаемости</td>
                </tr>
                <tr>
                    <td class="m-cell-title" data-label=""><span class="stars-demo">★★</span> Ниже среднего</td>
                    <td data-label="Где встречается">Офисы (50–100 чел), гостиницы, точки "по пути"</td>
                    <td data-label="Трафик, чел/день">200–500</td>
                    <td data-label="Нюансы">Трафик есть, но люди часто спешат</td>
                </tr>
                <tr>
                    <td class="m-cell-title" data-label=""><span class="stars-demo">★★★</span> Средняя</td>
                    <td data-label="Где встречается">Крупные офисы (>100 чел), склады, заводы, фитнес-клубы, университеты</td>
                    <td data-label="Трафик, чел/день">500–3 000</td>
                    <td data-label="Нюансы"><strong>Хороший выбор:</strong> стабильная аудитория</td>
                </tr>
                <tr>
                    <td class="m-cell-title" data-label=""><span class="stars-demo">★★★★</span> Высокая</td>
                    <td data-label="Где встречается">ТРЦ, парки развлечений, больницы, крупные офисные центры</td>
                    <td data-label="Трафик, чел/день">3 000–10 000</td>
                    <td data-label="Нюансы">Люди проводят время, высокий потенциал</td>
                </tr>
                <tr>
                    <td class="m-cell-title" data-label=""><span class="stars-demo">★★★★★</span> Максимальная</td>
                    <td data-label="Где встречается">Аэропорты, ж/д вокзалы, туристические центры</td>
                    <td data-label="Трафик, чел/день">10 000+</td>
                    <td data-label="Нюансы"><strong>Золотая жила,</strong> но аренда очень дорогая</td>
                </tr>
            </tbody>
        </table>
        <div class="note">
            <strong><?php echo rr_icon('lightbulb'); ?> Важно!</strong>
            Оценивайте не только количество людей, но и <strong>время пребывания</strong> (стоят/ждут) и наличие <strong>альтернатив</strong> (конкуренты). Самые прибыльные места — где люди задерживаются на 10–30 минут.
        </div>
        <p class="traffic-modal-footnote">Подсказка всегда доступна по <?php echo rr_icon('help-circle'); ?></p>
    </div>
</div>
<!-- ★★★ МОДАЛЬНОЕ ОКНО ДЛЯ ПРОСМОТРА ФОТО ★★★ -->
<div class="photo-modal" id="photoModal">
    <div class="photo-modal-content">
        <button class="photo-modal-close" onclick="closePhotoModal()" aria-label="Закрыть">&times;</button>
        <button class="photo-modal-prev" onclick="prevPhoto()" aria-label="Предыдущее фото">&#10094;</button>
        <button class="photo-modal-next" onclick="nextPhoto()" aria-label="Следующее фото">&#10095;</button>
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
<script src="/assets/js/m/location.js" defer></script>
</body>
</html>