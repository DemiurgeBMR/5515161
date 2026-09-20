<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для собственников
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /pages/profile.php');
    exit;
}

$pdo = getDbConnection();

// Проверяем, что локация принадлежит текущему пользователю
$stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ? AND owner_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$location = $stmt->fetch();

if (!$location) {
    header('Location: /pages/profile.php');
    exit;
}

// ★★★ Получаем текущий рейтинг проходимости ★★★
$traffic_rating = $location['traffic_rating'] ?? 0;

// Загружаем фото
$stmt_photos = $pdo->prepare("SELECT * FROM location_photos WHERE location_id = ? ORDER BY sort_order, id");
$stmt_photos->execute([$id]);
$photos = $stmt_photos->fetchAll();

$error = '';
$success = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['csrf_token'] ?? '')) {
    $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price_month = floatval($_POST['price_month'] ?? 0);
    $width = floatval($_POST['width'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);
    $depth = floatval($_POST['depth'] ?? 0);
    $has_electricity = isset($_POST['has_electricity']) ? 1 : 0;
    $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
    $has_water = isset($_POST['has_water']) ? 1 : 0;
    $access_hours = $_POST['access_hours'] ?? '24/7';

    // ★★★ Получаем и валидируем рейтинг проходимости ★★★
    $traffic_rating = intval($_POST['traffic_rating'] ?? 0);
    if ($traffic_rating < 0 || $traffic_rating > 5) $traffic_rating = 0;

    // ★★★ ПОЛУЧАЕМ ТИП ПОМЕЩЕНИЯ ★★★
    $space_type = $_POST['space_type'] ?? null;
    if ($space_type === '') $space_type = null;

// Валидация
if (empty($title) || empty($address) || empty($city) || $price_month <= 0) {
    $error = 'Заполните все обязательные поля (название, адрес, город, цена)';
} else {
    try {
        // ★★★ СОХРАНЯЕМ НОВУЮ РЕВИЗИЮ ★★★
        $revisionData = [
            'title'            => $title,
            'address'          => $address,
            'city'             => $city,
            'description'      => $description,
            'price_month'      => $price_month,
            'width'            => $width,
            'height'           => $height,
            'depth'            => $depth,
            'has_electricity'  => $has_electricity,
            'has_wifi'         => $has_wifi,
            'has_water'        => $has_water,
            'access_hours'     => $access_hours,
            'traffic_rating'   => $traffic_rating,
            'space_type'       => $space_type
        ];

        // Геокодируем адрес заново, если город или адрес изменились (для карты
        // и нормализации города). Если координаты не поменялись — не тратим
        // лишний запрос к Nominatim.
        if ($city !== $location['city'] || $address !== $location['address']) {
            $geo = geocodeAddress($address, $city);
            if ($geo) {
                if (!empty($geo['city'])) {
                    $revisionData['city'] = $geo['city'];
                }
                // lat/lng = null значит, что найденная улица не похожа на
                // введённую — не затираем этим прежние (верные) координаты
                // локации, просто оставляем их как есть.
                if ($geo['lat'] !== null) {
                    $revisionData['latitude'] = $geo['lat'];
                    $revisionData['longitude'] = $geo['lng'];
                }
            }
        }

        // Помечаем фото на удаление только сейчас, вместе с созданием ревизии —
        // а не сразу при получении формы. Иначе фото пропадало бы с публичной
        // карточки (is_pending=1 фильтруется на витрине) ещё до модерации, а
        // при провале валидации формы — зависало бы в таком состоянии навсегда,
        // потому что ревизия так и не создавалась.
        $deletedPhotos = $_POST['delete_photos'] ?? [];
        $deletedPhotoIds = [];
        if (is_array($deletedPhotos) && !empty($deletedPhotos)) {
            foreach ($deletedPhotos as $photo_id) {
                $photo_id = (int)$photo_id;
                $checkStmt = $pdo->prepare("SELECT id FROM location_photos WHERE id = ? AND location_id = ?");
                $checkStmt->execute([$photo_id, $id]);
                if ($checkStmt->fetch()) {
                    $updateStmt = $pdo->prepare("
                        UPDATE location_photos
                        SET pending_action = 'delete', is_pending = 1
                        WHERE id = ? AND location_id = ?
                    ");
                    $updateStmt->execute([$photo_id, $id]);
                    $deletedPhotoIds[] = $photo_id;
                }
            }
        }
        if (!empty($deletedPhotoIds)) {
            $revisionData['delete_photos'] = $deletedPhotoIds;
        }

        // Если есть новые фото – сохраняем их временно и записываем пути в ревизию
        $newPhotoPaths = [];
        if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
            // Загружаем фото в отдельную папку для ревизий (чтобы не мешали основным)
            $upload_dir = __DIR__ . '/../uploads/revisions/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            // Расширение сохранённого файла берётся из проверенного MIME-типа,
            // а не из имени файла клиента: имя вида x.jpg"><script>...</script>
            // иначе целиком становится "расширением" и попадает в путь на диске
            // и в БД, откуда выводится без экранирования на других страницах.
            $allowed_extensions = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            $max_size = 5 * 1024 * 1024;
            $uploaded_files = $_FILES['photos'];
            $total_files = min(count($uploaded_files['name']), 5);

            // Накопительная квота на пользователя — раньше размер и число файлов
            // ограничивались только на один запрос, ничто не мешало копить фото
            // годами и постепенно занять весь диск сервера.
            $diskLow = ($free = @disk_free_space(__DIR__)) !== false && $free < 500 * 1024 * 1024;
            $usedBytes = getUserUploadedBytes($pdo, $_SESSION['user_id']);

            for ($i = 0; $i < $total_files; $i++) {
                if ($uploaded_files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp_name = $uploaded_files['tmp_name'][$i];
                $file_type = mime_content_type($tmp_name);
                $extension = $allowed_extensions[$file_type] ?? null;
                if (!$extension) continue;
                if ($uploaded_files['size'][$i] > $max_size) {
                    $error = 'Файл "' . $uploaded_files['name'][$i] . '" превышает 5 МБ';
                    continue;
                }
                if ($diskLow || $usedBytes + $uploaded_files['size'][$i] > USER_UPLOAD_QUOTA_BYTES) {
                    $error = 'Достигнут лимит на общий объём загруженных фото (200 МБ на аккаунт). Удалите старые фото у своих локаций, чтобы освободить место.';
                    continue;
                }

                $new_name = uniqid() . '.' . $extension;
                $temp_path = $upload_dir . 'temp_' . $new_name;
                if (!move_uploaded_file($tmp_name, $temp_path)) continue;

                $final_path = $upload_dir . $new_name;
                $compressed = compressImage($temp_path, $final_path, 1200, 1200, 80);
                if ($compressed) {
                    unlink($temp_path);
                } else {
                    // Сжатие не удалось (например, повреждённое тело файла) —
                    // сохраняем как есть под тем же безопасным именем, вместо
                    // того чтобы просто потерять фото молча.
                    rename($temp_path, $final_path);
                }

                $newPhotoPaths[] = 'uploads/revisions/' . $new_name;
                $usedBytes += is_file($final_path) ? filesize($final_path) : 0;
            }
        }

        if (!empty($newPhotoPaths)) {
            $revisionData['new_photos'] = $newPhotoPaths;
        }

// Определяем главное фото из единой группы радиокнопок
$mainPhoto = $_POST['main_photo'] ?? '';
$mainPhotoId = null;

if (strpos($mainPhoto, 'existing_') === 0) {
    // Выбрано существующее фото
    $mainPhotoId = (int)substr($mainPhoto, strlen('existing_'));
    $revisionData['main_photo_id'] = $mainPhotoId;
} elseif (strpos($mainPhoto, 'new_') === 0) {
    // Выбрано новое фото (по индексу)
    $mainPhotoIndex = (int)substr($mainPhoto, strlen('new_'));
    if (isset($newPhotoPaths[$mainPhotoIndex])) {
        $revisionData['main_photo'] = $newPhotoPaths[$mainPhotoIndex];
    }
}
// Если ничего не выбрано – первое новое фото станет главным (это обрабатывается в applyRevision)

// ★★★ Смена главного фото среди уже одобренных фото локации не добавляет
// никакого нового непроверенного контента (само фото уже прошло модерацию
// раньше) — если больше ничего в объявлении не поменялось, применяем сразу,
// без очереди на модерацию. Любое другое изменение (текст, новые/удалённые
// фото) по-прежнему уходит ревизией, как раньше. ★★★
$contentUnchanged =
    empty($deletedPhotoIds) &&
    empty($newPhotoPaths) &&
    $title === $location['title'] &&
    $address === $location['address'] &&
    $city === $location['city'] &&
    $description === ($location['description'] ?? '') &&
    (float)$price_month === (float)$location['price_month'] &&
    (float)$width === (float)($location['width'] ?? 0) &&
    (float)$height === (float)($location['height'] ?? 0) &&
    (float)$depth === (float)($location['depth'] ?? 0) &&
    (int)$has_electricity === (int)$location['has_electricity'] &&
    (int)$has_wifi === (int)$location['has_wifi'] &&
    (int)$has_water === (int)$location['has_water'] &&
    $access_hours === $location['access_hours'] &&
    (int)$traffic_rating === (int)($location['traffic_rating'] ?? 0) &&
    $space_type === ($location['space_type'] ?? null);

$currentMainPhotoId = null;
foreach ($photos as $p) {
    if ($p['is_main'] == 1) {
        $currentMainPhotoId = (int)$p['id'];
        break;
    }
}

if ($contentUnchanged && $mainPhotoId !== null && $mainPhotoId !== $currentMainPhotoId) {
    // Проверяем, что выбранное фото действительно принадлежит этой локации
    // и не ждёт удаления, прежде чем делать его главным без модерации.
    $checkStmt = $pdo->prepare("
        SELECT id FROM location_photos
        WHERE id = ? AND location_id = ? AND (pending_action IS NULL OR pending_action != 'delete')
    ");
    $checkStmt->execute([$mainPhotoId, $id]);
    if ($checkStmt->fetch()) {
        $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?")->execute([$mainPhotoId]);
        clearCache('rec_' . $id);
        $success = 'Главное фото обновлено — оно уже опубликовано, модерация не нужна.';
    } else {
        $error = 'Не удалось найти выбранное фото.';
    }
} elseif ($contentUnchanged) {
    $success = 'Изменений не обнаружено.';
} else {
        // Сохраняем ревизию в БД
        $stmt = $pdo->prepare("
            INSERT INTO location_revisions (location_id, data, status)
            VALUES (?, ?, 'pending')
        ");
        $stmt->execute([$id, json_encode($revisionData)]);

        $success = 'Изменения отправлены на модерацию. Старая версия объявления остаётся активной до проверки.';
        clearCache('rec_' . $id);
}

        // Перезагружаем данные (они не изменились, но обновляем время)
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ? AND owner_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $location = $stmt->fetch();
        $traffic_rating = $location['traffic_rating'] ?? 0;
        $stmt_photos = $pdo->prepare("SELECT * FROM location_photos WHERE location_id = ? ORDER BY sort_order, id");
        $stmt_photos->execute([$id]);
        $photos = $stmt_photos->fetchAll();

    } catch (PDOException $e) {
        error_log('edit_location.php: ' . $e->getMessage());
        $error = DEBUG_MODE ? ('Ошибка базы данных: ' . $e->getMessage()) : 'Произошла ошибка. Попробуйте ещё раз позже.';
    }
}
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать локацию — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="add-form">
        <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('edit'); ?> Редактировать локацию</h2>
        
        <?php if ($error): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <!-- Основная информация -->
            <div class="form-group">
                <label>Название места *</label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($location['title']); ?>">
            </div>
            
            <div class="form-group">
                <label>Город *</label>
                <input type="text" name="city" required value="<?php echo htmlspecialchars($location['city']); ?>">
            </div>
            
            <div class="form-group">
                <label>Адрес *</label>
                <input type="text" name="address" required value="<?php echo htmlspecialchars($location['address']); ?>">
            </div>
            
            <div class="form-group">
                <label>Описание</label>
                <textarea name="description"><?php echo htmlspecialchars($location['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Цена в месяц (руб) *</label>
                    <input type="number" name="price_month" required step="1" min="0" value="<?php echo $location['price_month']; ?>">
                </div>
                <div class="form-group">
                    <label>Часы доступа</label>
                    <select name="access_hours">
                        <option value="24/7" <?php echo $location['access_hours'] == '24/7' ? 'selected' : ''; ?>>24/7</option>
                        <option value="08:00-22:00" <?php echo $location['access_hours'] == '08:00-22:00' ? 'selected' : ''; ?>>08:00 – 22:00</option>
                        <option value="09:00-21:00" <?php echo $location['access_hours'] == '09:00-21:00' ? 'selected' : ''; ?>>09:00 – 21:00</option>
                        <option value="10:00-20:00" <?php echo $location['access_hours'] == '10:00-20:00' ? 'selected' : ''; ?>>10:00 – 20:00</option>
                        <option value="По договоренности" <?php echo $location['access_hours'] == 'По договоренности' ? 'selected' : ''; ?>>По договоренности</option>
                    </select>
                </div>
            </div>

            <!-- ★★★ НОВЫЙ БЛОК: ТИП ПОМЕЩЕНИЯ ★★★ -->
            <div class="form-group">
                <label>Тип помещения</label>
                <select name="space_type" class="form-control">
                    <option value="">Не выбран</option>
                    <option value="retail" <?php echo ($location['space_type'] == 'retail') ? 'selected' : ''; ?>>Торговый центр / Магазин</option>
                    <option value="office" <?php echo ($location['space_type'] == 'office') ? 'selected' : ''; ?>>Бизнес-центр / Офис</option>
                    <option value="gym" <?php echo ($location['space_type'] == 'gym') ? 'selected' : ''; ?>>Спортзал / Фитнес-клуб</option>
                    <option value="hotel" <?php echo ($location['space_type'] == 'hotel') ? 'selected' : ''; ?>>Отель / Гостиница</option>
                    <option value="hospital" <?php echo ($location['space_type'] == 'hospital') ? 'selected' : ''; ?>>Больница / Медицинский центр</option>
                    <option value="transit" <?php echo ($location['space_type'] == 'transit') ? 'selected' : ''; ?>>Вокзал / Аэропорт</option>
                    <option value="coworking" <?php echo ($location['space_type'] == 'coworking') ? 'selected' : ''; ?>>Коворкинг</option>
                    <option value="laundromat" <?php echo ($location['space_type'] == 'laundromat') ? 'selected' : ''; ?>>Прачечная / Химчистка</option>
                    <option value="auto" <?php echo ($location['space_type'] == 'auto') ? 'selected' : ''; ?>>Автосалон / СТО</option>
                    <option value="warehouse" <?php echo ($location['space_type'] == 'warehouse') ? 'selected' : ''; ?>>Склад / Логистика</option>
                    <option value="factory" <?php echo ($location['space_type'] == 'factory') ? 'selected' : ''; ?>>Завод / Производство</option>
                    <option value="education" <?php echo ($location['space_type'] == 'education') ? 'selected' : ''; ?>>Учебное заведение (школа, вуз)</option>
                    <option value="cinema" <?php echo ($location['space_type'] == 'cinema') ? 'selected' : ''; ?>>Кинотеатр / Развлекательный центр</option>
                    <option value="cafe" <?php echo ($location['space_type'] == 'cafe') ? 'selected' : ''; ?>>Кафе / Ресторан</option>
                    <option value="bank" <?php echo ($location['space_type'] == 'bank') ? 'selected' : ''; ?>>Банк / Финансовое учреждение</option>
                    <option value="post" <?php echo ($location['space_type'] == 'post') ? 'selected' : ''; ?>>Почта / Отделение связи</option>
                    <option value="park" <?php echo ($location['space_type'] == 'park') ? 'selected' : ''; ?>>Парк / Сквер</option>
                    <option value="stadium" <?php echo ($location['space_type'] == 'stadium') ? 'selected' : ''; ?>>Стадион / Спорткомплекс</option>
                    <option value="museum" <?php echo ($location['space_type'] == 'museum') ? 'selected' : ''; ?>>Музей / Выставочный центр</option>
                    <option value="other" <?php echo ($location['space_type'] == 'other') ? 'selected' : ''; ?>>Другое</option>
                </select>
            </div>

            <!-- ★★★ БЛОК ЗВЁЗД ПРОХОДИМОСТИ (с уже закрашенными) ★★★ -->
            <div class="form-group">
                <label class="traffic-rating-label">
                    Проходимость места
                    <span class="traffic-help-icon" onclick="openTrafficHelp()" title="Что означает каждая звезда?"><?php echo rr_icon('help-circle'); ?></span>
                </label>
                <div class="star-rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span data-value="<?php echo $i; ?>" class="<?php echo ($i <= $traffic_rating) ? 'filled' : ''; ?>">★</span>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="traffic_rating" id="traffic_rating" value="<?php echo $traffic_rating; ?>">
                <div class="traffic-rating-hint">Оцените примерную проходимость (1 — низкая, 5 — очень высокая)</div>
            </div>
            
            <!-- Габариты -->
            <div class="form-row">
                <div class="form-group">
                    <label>Ширина (м)</label>
                    <input type="number" name="width" step="0.1" value="<?php echo htmlspecialchars($location['width'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Высота (м)</label>
                    <input type="number" name="height" step="0.1" value="<?php echo htmlspecialchars($location['height'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Глубина (м)</label>
                    <input type="number" name="depth" step="0.1" value="<?php echo htmlspecialchars($location['depth'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Коммуникации -->
            <div class="form-group">
                <label>Что есть на месте</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="has_electricity" <?php echo $location['has_electricity'] ? 'checked' : ''; ?>> <?php echo rr_icon('bolt'); ?> Электричество
                    </label>
                    <label>
                        <input type="checkbox" name="has_wifi" <?php echo $location['has_wifi'] ? 'checked' : ''; ?>> <?php echo rr_icon('wifi'); ?> Wi-Fi
                    </label>
                    <label>
                        <input type="checkbox" name="has_water" <?php echo $location['has_water'] ? 'checked' : ''; ?>> <?php echo rr_icon('droplet'); ?> Вода
                    </label>
                </div>
            </div>

<!-- Текущие фото -->
<?php 
// Загружаем ТОЛЬКО активные фото (не удалённые и не ожидающие удаления)
$stmt_active_photos = $pdo->prepare("
    SELECT * FROM location_photos 
    WHERE location_id = ? AND (pending_action != 'delete' OR pending_action IS NULL)
    ORDER BY sort_order, id
");
$stmt_active_photos->execute([$id]);
$active_photos = $stmt_active_photos->fetchAll();

// Также загружаем фото, ожидающие добавления (для отображения, что они добавятся после модерации)
$stmt_pending_add = $pdo->prepare("
    SELECT * FROM location_photos 
    WHERE location_id = ? AND pending_action = 'add' AND is_pending = 1
    ORDER BY sort_order, id
");
$stmt_pending_add->execute([$id]);
$pending_add_photos = $stmt_pending_add->fetchAll();

// Фото, отмеченные на удаление (показываем их как удалённые)
$stmt_pending_delete = $pdo->prepare("
    SELECT * FROM location_photos 
    WHERE location_id = ? AND pending_action = 'delete' AND is_pending = 1
    ORDER BY sort_order, id
");
$stmt_pending_delete->execute([$id]);
$pending_delete_photos = $stmt_pending_delete->fetchAll();

$all_photos = array_merge($active_photos, $pending_add_photos);
?>
<?php if (count($all_photos) > 0 || count($pending_delete_photos) > 0): ?>
    <div class="form-group">
        <label>Текущие фото</label>
        <div class="current-photos">
            <?php foreach ($all_photos as $photo): 
                $isPendingAdd = ($photo['is_pending'] == 1 && $photo['pending_action'] == 'add');
                $isMain = ($photo['is_main'] == 1);
            ?>
<div class="photo-item<?php echo $isPendingAdd ? ' pending-add' : ''; ?>">
    <img src="/<?php echo $photo['photo_path']; ?>" alt="Фото">
    <div class="photo-pending-note">
        <?php if ($isPendingAdd): ?>
            <?php echo rr_icon('clock'); ?> Добавится после модерации
        <?php elseif ($isMain): ?>
            Главное
        <?php endif; ?>
    </div>
    <?php if (!$isPendingAdd): ?>
        <label class="radio-label-block">
            <input type="radio" name="main_photo" value="existing_<?php echo $photo['id']; ?>" <?php echo $isMain ? 'checked' : ''; ?>>
            Главное
        </label>
        <label>
            <input type="checkbox" name="delete_photos[]" value="<?php echo $photo['id']; ?>"> Удалить
        </label>
    <?php else: ?>
        <span class="photo-pending-note">Ожидает добавления</span>
    <?php endif; ?>
</div>
            <?php endforeach; ?>
            
            <?php foreach ($pending_delete_photos as $photo): ?>
                <div class="photo-item pending-delete">
                    <img src="/<?php echo $photo['photo_path']; ?>" alt="Фото">
                    <div class="photo-delete-overlay">
                        <?php echo rr_icon('trash'); ?> Будет удалено
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($pending_delete_photos) > 0): ?>
            <div class="photo-notice danger">
                <?php echo rr_icon('warning'); ?> Отмеченные фото будут удалены после модерации
            </div>
        <?php endif; ?>
        <?php if (count($pending_add_photos) > 0): ?>
            <div class="photo-notice info">
                <?php echo rr_icon('camera'); ?> Новые фото появятся после модерации
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
          
            <!-- Загрузка новых фото -->
            <div class="form-group">
                <label>Добавить новые фотографии (до 5 шт)</label>
                <div class="file-upload" onclick="document.getElementById('photoInput').click();">
                    <span class="icon"><?php echo rr_icon('camera'); ?></span>
                    <div class="text">
                        Кликните или перетащите фото<br>
                        <span>Поддерживаются JPG, PNG, WEBP (до 5 МБ)</span>
                    </div>
                    <input type="file" id="photoInput" name="photos[]" accept="image/*" multiple>
                </div>
                <div id="fileNames" class="file-names-hint"></div>
                <div id="photoPreview" class="photo-preview-grid"></div>
            </div>
            
            <div class="form-actions-row">
                <button type="submit" class="btn-submit"><?php echo rr_icon('save'); ?> Сохранить изменения</button>
                <a href="/pages/profile.php" class="btn-submit secondary">Отмена</a>
            </div>
        </form>
    </div>
    
    <!-- ★★★ МОДАЛЬНОЕ ОКНО С ПАМЯТКОЙ ★★★ -->
    <div class="modal-overlay" id="trafficHelpModal">
        <div class="modal-box">
            <button class="close-btn" onclick="closeTrafficHelp()" aria-label="Закрыть">&times;</button>
            <h3><?php echo rr_icon('walk'); ?> Как оценить проходимость места?</h3>
            <p class="traffic-modal-subtitle">Выберите уровень, который лучше всего описывает вашу локацию.</p>
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
                <strong><?php echo rr_icon('lightbulb'); ?> Важно!</strong>
                Оценивайте не только количество людей, но и <strong>время пребывания</strong> (стоят/ждут) и наличие <strong>альтернатив</strong> (конкуренты). Самые прибыльные места — где люди задерживаются на 10–30 минут.
            </div>
            <p class="traffic-modal-footnote">Подсказка всегда доступна по <?php echo rr_icon('help-circle'); ?></p>
        </div>
    </div>
    
<!-- ★★★ ВАЛИДАЦИЯ ФАЙЛОВ ПРИ ЗАГРУЗКЕ ★★★ -->
<script>
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 МБ
    const fileInput = document.getElementById('photoInput');
    const fileNamesDiv = document.getElementById('fileNames');
    const submitBtn = document.querySelector('.btn-submit');

fileInput.addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    const previewContainer = document.getElementById('photoPreview');
    previewContainer.innerHTML = '';
    let validFiles = [];
    let invalidFiles = [];

    files.forEach((file, index) => {
        if (file.size > MAX_FILE_SIZE) {
            invalidFiles.push(file);
        } else {
            validFiles.push(file);
            const reader = new FileReader();
            reader.onload = function(ev) {
                const div = document.createElement('div');
                div.className = 'photo-preview-item';
                div.innerHTML = `
                    <img src="${ev.target.result}" class="photo-preview-thumb" alt="Предпросмотр фото">
                    <label class="photo-preview-radio-label">
                        <input type="radio" name="main_photo" value="new_${index}" ${index === 0 ? 'checked' : ''}>
                        Главное
                    </label>
                `;
                previewContainer.appendChild(div);
            };
            reader.readAsDataURL(file);
        }
    });

    let message = '';
    if (validFiles.length > 0) {
        message += `<div class="upload-msg-ok"><?php echo rr_icon('check'); ?> ${validFiles.length} файлов готовы</div>`;
    }
    if (invalidFiles.length > 0) {
        message += `<div class="upload-msg-error"><?php echo rr_icon('x'); ?> ${invalidFiles.length} файлов превышают 5 МБ</div>`;
        submitBtn.disabled = true;
    } else {
        submitBtn.disabled = false;
    }
    document.getElementById('fileNames').innerHTML = message;
});

</script>
<script>
        // ★★★ Управление звёздами (клик) ★★★
        document.querySelectorAll('.star-rating span').forEach(function(star) {
            star.addEventListener('click', function() {
                const value = this.dataset.value;
                document.getElementById('traffic_rating').value = value;

                document.querySelectorAll('.star-rating span').forEach(function(s, idx) {
                    s.classList.toggle('filled', idx < value);
                });
            });
        });

        // ★★★ Открыть / закрыть памятку ★★★
        function openTrafficHelp() {
            document.getElementById('trafficHelpModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeTrafficHelp() {
            document.getElementById('trafficHelpModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        // Закрыть по клику на оверлей
        document.getElementById('trafficHelpModal').addEventListener('click', function(e) {
            if (e.target === this) closeTrafficHelp();
        });
        // Закрыть по Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTrafficHelp();
        });
    </script>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>