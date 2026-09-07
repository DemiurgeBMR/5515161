<?php
session_start();
require_once __DIR__ . '/../config.php';

// Только для собственников
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /pages/profile.php');
    exit;
}

$pdo = getDbConnection();

// Проверяем, что локация принадлежит текущему пользователю
$stmt = $pdo->prepare("SELECT owner_id FROM locations WHERE id = ?");
$stmt->execute([$id]);
$loc = $stmt->fetch();

if (!$loc || $loc['owner_id'] != $_SESSION['user_id']) {
    header('Location: /pages/profile.php');
    exit;
}

// ============================================================
// ★★★ ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ ПРИМЕНЕНИЯ РЕВИЗИИ (ДЛЯ НОВЫХ ЛОКАЦИЙ) ★★★
// ============================================================
function applyRevisionToLocation($pdo, $revision, $locationId, $setModerated = false) {
    $data = json_decode($revision['data'], true);
    if (!$data) {
        return false;
    }

    // Обновляем поля локации
    $fields = ['title', 'address', 'city', 'description', 'price_month', 'width', 'height', 'depth',
               'has_electricity', 'has_wifi', 'access_hours', 'traffic_rating', 'space_type'];
    $setParts = [];
    $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $setParts[] = "$f = ?";
            $params[] = $data[$f];
        }
    }
    if (!empty($setParts)) {
        $params[] = $locationId;
        $sql = "UPDATE locations SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Обработка удаления фото
    if (!empty($data['delete_photos']) && is_array($data['delete_photos'])) {
        $deleteIds = $data['delete_photos'];
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $pdo->prepare("SELECT photo_path FROM location_photos WHERE id IN ($placeholders) AND location_id = ?");
        $stmt->execute(array_merge($deleteIds, [$locationId]));
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($paths as $path) {
            $fullPath = __DIR__ . '/../' . $path;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
        $stmt = $pdo->prepare("DELETE FROM location_photos WHERE id IN ($placeholders) AND location_id = ?");
        $stmt->execute(array_merge($deleteIds, [$locationId]));
    }

    // Обработка новых фото (перенос из revisions в locations)
    if (!empty($data['new_photos']) && is_array($data['new_photos'])) {
        $uploadDir = __DIR__ . '/../uploads/locations/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        foreach ($data['new_photos'] as $tempPath) {
            $tempFull = __DIR__ . '/../' . $tempPath;
            if (!file_exists($tempFull)) continue;
            $ext = pathinfo($tempFull, PATHINFO_EXTENSION);
            $newName = uniqid() . '.' . $ext;
            $newFull = $uploadDir . $newName;
            if (rename($tempFull, $newFull)) {
                $relativePath = 'uploads/locations/' . $newName;
                $stmt = $pdo->prepare("INSERT INTO location_photos (location_id, photo_path, sort_order, is_main, is_pending, pending_action) VALUES (?, ?, 0, 0, 0, NULL)");
                $stmt->execute([$locationId, $relativePath]);
            }
        }
    }

    // Обработка смены главного фото
    if (!empty($data['set_main_photo'])) {
        $mainPhotoId = (int)$data['set_main_photo'];
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ? AND location_id = ?");
        $stmt->execute([$mainPhotoId, $locationId]);
    } elseif (!empty($data['main_photo'])) {
        $mainPhotoPath = $data['main_photo'];
        $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE photo_path = ? AND location_id = ?");
        $stmt->execute([$mainPhotoPath, $locationId]);
        $photo = $stmt->fetch();
        if ($photo) {
            $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
            $stmt->execute([$locationId]);
            $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?");
            $stmt->execute([$photo['id']]);
        }
    } elseif (!empty($data['main_photo_id'])) {
        $mainPhotoId = (int)$data['main_photo_id'];
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ? AND location_id = ?");
        $stmt->execute([$mainPhotoId, $locationId]);
    }

    // Если нужно установить модерацию (для админского одобрения), но здесь не используем
    if ($setModerated) {
        $stmt = $pdo->prepare("UPDATE locations SET is_moderated = 1, is_active = 1 WHERE id = ?");
        $stmt->execute([$locationId]);
    }

    // Назначаем главное фото, если его нет
    $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE location_id = ? AND is_main = 1");
    $stmt->execute([$locationId]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE location_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$locationId]);
        $first = $stmt->fetch();
        if ($first) {
            $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?");
            $stmt->execute([$first['id']]);
        }
    }
    return true;
}
// ============================================================

// ========== ОБРАБОТЧИКИ ДЕЙСТВИЙ ==========

if ($action === 'toggle') {
    $stmt = $pdo->prepare("UPDATE locations SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['flash'] = 'Статус видимости изменён.';

} elseif ($action === 'delete') {
    // ★★★ 1. Обрабатываем ожидающие ревизии (удаляем временные файлы) ★★★
    $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $revisions = $stmt->fetchAll();

    foreach ($revisions as $rev) {
        $data = json_decode($rev['data'], true);
        if ($data) {
            // Удаляем новые фото из временной папки
            if (!empty($data['new_photos'])) {
                foreach ($data['new_photos'] as $path) {
                    $fullPath = __DIR__ . '/../' . $path;
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
            }
            // Если есть пометки на удаление существующих фото – сбрасываем их (необязательно, т.к. локация удаляется)
            if (!empty($data['delete_photos'])) {
                $placeholders = implode(',', array_fill(0, count($data['delete_photos']), '?'));
                $stmt2 = $pdo->prepare("UPDATE location_photos SET pending_action = NULL, is_pending = 0 WHERE id IN ($placeholders) AND location_id = ?");
                $stmt2->execute(array_merge($data['delete_photos'], [$id]));
            }
        }
        // Можно сразу удалить ревизию или просто отклонить – мы всё равно удаляем локацию
        $stmt2 = $pdo->prepare("DELETE FROM location_revisions WHERE id = ?");
        $stmt2->execute([$rev['id']]);
    }

    // ★★★ 2. Получаем все основные фото (которые уже в location_photos) ★★★
    $stmt = $pdo->prepare("SELECT photo_path FROM location_photos WHERE location_id = ?");
    $stmt->execute([$id]);
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Удаляем файлы с диска
    foreach ($photos as $path) {
        $fullPath = __DIR__ . '/../' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    // ★★★ 3. Удаляем локацию (каскадно удалятся фото-записи и связи) ★★★
    $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
    $stmt->execute([$id]);

    clearCache('rec_' . $id);
    $_SESSION['flash'] = 'Локация и все связанные файлы удалены.';
    header('Location: /pages/profile.php');
    exit;
} elseif ($action === 'withdraw_changes') {
    // ★★★ ОТЗЫВ ВСЕХ ОЖИДАЮЩИХ РЕВИЗИЙ ★★★

    $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $revisions = $stmt->fetchAll();

    if (empty($revisions)) {
        $_SESSION['flash'] = 'Нет ожидающих правок для отзыва.';
        header('Location: /pages/profile.php');
        exit;
    }

    // Проверяем, новая ли локация
    $stmt = $pdo->prepare("SELECT is_moderated FROM locations WHERE id = ?");
    $stmt->execute([$id]);
    $locData = $stmt->fetch();
    $isNew = ($locData && $locData['is_moderated'] == 0);

    foreach ($revisions as $rev) {
        $data = json_decode($rev['data'], true);
        if ($data) {
            if ($isNew) {
                // ★★★ Для новой локации применяем ревизию (сохраняем данные и фото) ★★★
                applyRevisionToLocation($pdo, $rev, $id, false);
                $stmt2 = $pdo->prepare("UPDATE location_revisions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt2->execute([$_SESSION['user_id'], $rev['id']]);
            } else {
                // ★★★ Для уже опубликованной локации - удаляем временные фото и сбрасываем пометки удаления ★★★
                if (!empty($data['new_photos'])) {
                    foreach ($data['new_photos'] as $path) {
                        $fullPath = __DIR__ . '/../' . $path;
                        if (file_exists($fullPath)) {
                            unlink($fullPath);
                        }
                    }
                }
                if (!empty($data['delete_photos'])) {
                    $placeholders = implode(',', array_fill(0, count($data['delete_photos']), '?'));
                    $stmt2 = $pdo->prepare("UPDATE location_photos SET pending_action = NULL, is_pending = 0 WHERE id IN ($placeholders) AND location_id = ?");
                    $stmt2->execute(array_merge($data['delete_photos'], [$id]));
                }
                $stmt2 = $pdo->prepare("UPDATE location_revisions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt2->execute([$_SESSION['user_id'], $rev['id']]);
            }
        }
    }

    clearCache('rec_' . $id);

    if ($isNew) {
        $_SESSION['flash'] = 'Правки отозваны, но данные и фото сохранены. Теперь вы можете отредактировать объявление и отправить его снова.';
    } else {
        $_SESSION['flash'] = 'Все правки успешно отозваны. Старая версия объявления восстановлена.';
    }
    header('Location: /pages/profile.php');
    exit;

} elseif ($action === 'withdraw_and_edit') {
    // Отзываем все правки и перенаправляем на редактирование

    $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    $revisions = $stmt->fetchAll();

    if (empty($revisions)) {
        $_SESSION['flash'] = 'Нет ожидающих правок для отзыва.';
        header('Location: /pages/profile.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT is_moderated FROM locations WHERE id = ?");
    $stmt->execute([$id]);
    $locData = $stmt->fetch();
    $isNew = ($locData && $locData['is_moderated'] == 0);

    foreach ($revisions as $rev) {
        $data = json_decode($rev['data'], true);
        if ($data) {
            if ($isNew) {
                applyRevisionToLocation($pdo, $rev, $id, false);
                $stmt2 = $pdo->prepare("UPDATE location_revisions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt2->execute([$_SESSION['user_id'], $rev['id']]);
            } else {
                if (!empty($data['new_photos'])) {
                    foreach ($data['new_photos'] as $path) {
                        $fullPath = __DIR__ . '/../' . $path;
                        if (file_exists($fullPath)) {
                            unlink($fullPath);
                        }
                    }
                }
                if (!empty($data['delete_photos'])) {
                    $placeholders = implode(',', array_fill(0, count($data['delete_photos']), '?'));
                    $stmt2 = $pdo->prepare("UPDATE location_photos SET pending_action = NULL, is_pending = 0 WHERE id IN ($placeholders) AND location_id = ?");
                    $stmt2->execute(array_merge($data['delete_photos'], [$id]));
                }
                $stmt2 = $pdo->prepare("UPDATE location_revisions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt2->execute([$_SESSION['user_id'], $rev['id']]);
            }
        }
    }

    clearCache('rec_' . $id);

    $_SESSION['flash'] = 'Правки отозваны. Теперь вы можете отредактировать объявление и отправить его снова.';
    header('Location: /pages/edit_location.php?id=' . $id);
    exit;

} elseif ($action === 'set_main_photo') {
    $photo_id = isset($_GET['photo_id']) ? (int)$_GET['photo_id'] : 0;
    if ($photo_id <= 0) {
        $_SESSION['flash'] = 'Ошибка: неверный идентификатор фото.';
        header('Location: /pages/edit_location.php?id=' . $id);
        exit;
    }

    // Проверяем, что фото принадлежит этой локации и НЕ помечено на удаление
    $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE id = ? AND location_id = ? AND (pending_action != 'delete' OR pending_action IS NULL)");
    $stmt->execute([$photo_id, $id]);
    if (!$stmt->fetch()) {
        $_SESSION['flash'] = 'Ошибка: фото не найдено, не принадлежит этой локации или помечено на удаление.';
        header('Location: /pages/edit_location.php?id=' . $id);
        exit;
    }

    // Проверяем, есть ли ожидающие ревизии
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM location_revisions WHERE location_id = ? AND status = 'pending'");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['flash'] = 'Невозможно сменить главное фото, пока есть правки на модерации. Отзовите правки или дождитесь проверки.';
        header('Location: /pages/edit_location.php?id=' . $id);
        exit;
    }

    // Получаем текущие данные локации
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
    $stmt->execute([$id]);
    $loc = $stmt->fetch();

    $revisionData = [
        'title'            => $loc['title'],
        'address'          => $loc['address'],
        'city'             => $loc['city'],
        'description'      => $loc['description'],
        'price_month'      => $loc['price_month'],
        'width'            => $loc['width'],
        'height'           => $loc['height'],
        'depth'            => $loc['depth'],
        'has_electricity'  => $loc['has_electricity'],
        'has_wifi'         => $loc['has_wifi'],
        'access_hours'     => $loc['access_hours'],
        'traffic_rating'   => $loc['traffic_rating'],
        'space_type'       => $loc['space_type'],
        'set_main_photo'   => $photo_id
    ];

    $stmt = $pdo->prepare("INSERT INTO location_revisions (location_id, data, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$id, json_encode($revisionData)]);
    clearCache('rec_' . $id);

    $_SESSION['flash'] = 'Запрос на смену главного фото отправлен на модерацию.';
    header('Location: /pages/edit_location.php?id=' . $id);
    exit;

} else {
    $_SESSION['flash'] = 'Неизвестное действие.';
}

header('Location: /pages/profile.php');
exit;