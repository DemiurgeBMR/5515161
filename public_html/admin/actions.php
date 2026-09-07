<?php
session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$revision_id = isset($_GET['revision_id']) ? (int)$_GET['revision_id'] : 0;

if ($id <= 0 && $action !== 'approve_revision' && $action !== 'reject_revision') {
    header('Location: /admin/index.php');
    exit;
}

if (!csrf_verify($_GET['csrf'] ?? '')) {
    $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    header('Location: /admin/index.php');
    exit;
}

$pdo = getDbConnection();

// ===== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ =====

/**
 * Применяет ревизию к локации (обновляет данные и фото)
 */
function applyRevision($pdo, $revision, $locationId) {
    $data = json_decode($revision['data'], true);
    if (!$data) {
        return false;
    }

    $mappedPaths = []; // всегда инициализируем

    // 1. Обновляем поля локации
    $fields = ['title', 'address', 'city', 'description', 'price_month', 'width', 'height', 'depth',
               'has_electricity', 'has_wifi', 'access_hours', 'traffic_rating', 'space_type',
               'latitude', 'longitude'];
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

    // 2. Обработка удаления фото
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

    // 3. Обработка новых фото
    if (!empty($data['new_photos']) && is_array($data['new_photos'])) {
        $uploadDir = __DIR__ . '/../uploads/locations/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($data['new_photos'] as $tempPath) {
            // Ожидаемый формат — "uploads/revisions/<имя_файла>" без вложенных
            // директорий; всё остальное отбрасываем, чтобы ../ в данных ревизии
            // не позволил переместить произвольный файл сервера.
            if (!is_string($tempPath) || !preg_match('#^uploads/revisions/[A-Za-z0-9_.-]+$#', $tempPath)) {
                continue;
            }
            $tempFull = __DIR__ . '/../' . $tempPath;
            if (!file_exists($tempFull)) continue;

            $ext = pathinfo($tempFull, PATHINFO_EXTENSION);
            $newName = uniqid() . '.' . $ext;
            $newFull = $uploadDir . $newName;

            if (rename($tempFull, $newFull)) {
                $relativePath = 'uploads/locations/' . $newName;
                $stmt = $pdo->prepare("
                    INSERT INTO location_photos (location_id, photo_path, sort_order, is_main, is_pending, pending_action)
                    VALUES (?, ?, 0, 0, 0, NULL)
                ");
                $stmt->execute([$locationId, $relativePath]);
                // Запоминаем соответствие
                $mappedPaths[$tempPath] = $relativePath;
            }
        }
    }

    // 4. Установка главного фото
    $mainSet = false;

    // 4.1 Если указан ID существующего фото (set_main_photo)
    if (!empty($data['set_main_photo'])) {
        $mainPhotoId = (int)$data['set_main_photo'];
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ? AND location_id = ?");
        $stmt->execute([$mainPhotoId, $locationId]);
        $mainSet = true;
    } 
    // 4.2 Если указан ID существующего фото (main_photo_id)
    elseif (!empty($data['main_photo_id'])) {
        $mainPhotoId = (int)$data['main_photo_id'];
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
        $stmt->execute([$locationId]);
        $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ? AND location_id = ?");
        $stmt->execute([$mainPhotoId, $locationId]);
        $mainSet = true;
    } 
    // 4.3 Если указан путь к новому фото (main_photo)
    elseif (!empty($data['main_photo']) && isset($mappedPaths[$data['main_photo']])) {
        $newMainPath = $mappedPaths[$data['main_photo']];
        $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE photo_path = ? AND location_id = ?");
        $stmt->execute([$newMainPath, $locationId]);
        $photo = $stmt->fetch();
        if ($photo) {
            $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
            $stmt->execute([$locationId]);
            $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?");
            $stmt->execute([$photo['id']]);
            $mainSet = true;
        }
    }

    // 5. Если главное не установлено, но есть новые фото – делаем первое из них главным
    if (!$mainSet && !empty($data['new_photos'])) {
        $firstNew = reset($data['new_photos']);
        if ($firstNew && isset($mappedPaths[$firstNew])) {
            $newMainPath = $mappedPaths[$firstNew];
            $stmt = $pdo->prepare("SELECT id FROM location_photos WHERE photo_path = ? AND location_id = ?");
            $stmt->execute([$newMainPath, $locationId]);
            $photo = $stmt->fetch();
            if ($photo) {
                $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 0 WHERE location_id = ?");
                $stmt->execute([$locationId]);
                $stmt = $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?");
                $stmt->execute([$photo['id']]);
                $mainSet = true;
            }
        }
    }

    // 6. Если после всех манипуляций всё ещё нет главного фото – назначаем первое попавшееся
    if (!$mainSet) {
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
    }

    return true;
}

/**
 * Отклоняет ревизию (чистит временные файлы, если нужно)
 */
function rejectRevision($pdo, $revision) {
    // По желанию можно удалить загруженные для этой ревизии фото, чтобы не занимали место
    $data = json_decode($revision['data'], true);
    if ($data && !empty($data['new_photos'])) {
        foreach ($data['new_photos'] as $path) {
            $fullPath = __DIR__ . '/../' . $path;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
    }
    // Меняем статус ревизии
    $stmt = $pdo->prepare("UPDATE location_revisions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
    $stmt->execute([$_SESSION['user_id'], $revision['id']]);
}

// ===== ОБРАБОТКА ДЕЙСТВИЙ =====

try {
    if ($action === 'approve_revision' && $revision_id > 0) {
        // Одобрить конкретную ревизию
        $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$revision_id]);
        $revision = $stmt->fetch();
        if (!$revision) {
            $_SESSION['flash'] = 'Ревизия не найдена или уже обработана.';
            header('Location: /admin/index.php');
            exit;
        }
        if (applyRevision($pdo, $revision, $revision['location_id'])) {
            // Помечаем ревизию как одобренную
            $stmt = $pdo->prepare("UPDATE location_revisions SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $revision_id]);

    // ★★★ НОВОЕ: если локация новая (не промодерирована), делаем её активной ★★★
    $stmt = $pdo->prepare("UPDATE locations SET is_moderated = 1, is_active = 1 WHERE id = ? AND is_moderated = 0");
    $stmt->execute([$revision['location_id']]);

            clearCache('rec_' . $revision['location_id']);
            $_SESSION['flash'] = 'Ревизия одобрена, изменения применены.';
        } else {
            $_SESSION['flash'] = 'Ошибка при применении ревизии.';
        }
    }
    elseif ($action === 'reject_revision' && $revision_id > 0) {
        // Отклонить конкретную ревизию
        $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$revision_id]);
        $revision = $stmt->fetch();
        if (!$revision) {
            $_SESSION['flash'] = 'Ревизия не найдена или уже обработана.';
            header('Location: /admin/index.php');
            exit;
        }
        rejectRevision($pdo, $revision);
        $_SESSION['flash'] = 'Ревизия отклонена.';
    }
    elseif ($action === 'approve_pending' && $id > 0) {
        // Одобрить все ожидающие ревизии для локации.
        // Применяем их все по порядку создания (а не только последнюю) —
        // иначе поля, изменённые в более ранней ревизии, но не тронутые в
        // последней, молча терялись бы при отклонении этой ранней ревизии.
        $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending' ORDER BY created_at ASC");
        $stmt->execute([$id]);
        $revisions = $stmt->fetchAll();
        if (empty($revisions)) {
            $_SESSION['flash'] = 'Нет ожидающих ревизий для этой локации.';
            header('Location: /admin/index.php');
            exit;
        }

        $allApplied = true;
        foreach ($revisions as $rev) {
            if (applyRevision($pdo, $rev, $id)) {
                $stmt = $pdo->prepare("UPDATE location_revisions SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $rev['id']]);
            } else {
                $allApplied = false;
            }
        }

        // ★★★ Если локация новая (не промодерирована), делаем её активной ★★★
        $stmt = $pdo->prepare("UPDATE locations SET is_moderated = 1, is_active = 1 WHERE id = ? AND is_moderated = 0");
        $stmt->execute([$id]);

        clearCache('rec_' . $id);
        $_SESSION['flash'] = $allApplied
            ? 'Все правки применены по порядку и одобрены.'
            : 'Часть правок не удалось применить — проверьте локацию.';
    }
    elseif ($action === 'reject_pending' && $id > 0) {
        // Отклонить все ожидающие ревизии для локации
        $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $revisions = $stmt->fetchAll();
        if (empty($revisions)) {
            $_SESSION['flash'] = 'Нет ожидающих ревизий для этой локации.';
        } else {
            foreach ($revisions as $rev) {
                rejectRevision($pdo, $rev);
            }
            $_SESSION['flash'] = 'Все правки отклонены.';
        }
    }
    elseif ($action === 'hide' && $id > 0) {
        $stmt = $pdo->prepare("UPDATE locations SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Локация скрыта.';
    }
    elseif ($action === 'show' && $id > 0) {
        $stmt = $pdo->prepare("UPDATE locations SET is_active = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Локация опубликована.';
    }
    elseif ($action === 'delete' && $id > 0) {
        // Удаляем ожидающие ревизии вместе с их временными фото
        $stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $revisions = $stmt->fetchAll();
        foreach ($revisions as $rev) {
            $data = json_decode($rev['data'], true);
            if ($data && !empty($data['new_photos'])) {
                foreach ($data['new_photos'] as $path) {
                    if (!is_string($path) || !preg_match('#^uploads/revisions/[A-Za-z0-9_.-]+$#', $path)) {
                        continue;
                    }
                    $fullPath = __DIR__ . '/../' . $path;
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
            }
            $stmt2 = $pdo->prepare("DELETE FROM location_revisions WHERE id = ?");
            $stmt2->execute([$rev['id']]);
        }

        // Удаляем уже сохранённые фото локации
        $stmt = $pdo->prepare("SELECT photo_path FROM location_photos WHERE location_id = ?");
        $stmt->execute([$id]);
        $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($photos as $path) {
            $fullPath = __DIR__ . '/../' . $path;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
        $stmt->execute([$id]);

        clearCache('rec_' . $id);
        $_SESSION['flash'] = 'Локация и все связанные файлы удалены.';
    }
    else {
        $_SESSION['flash'] = 'Неизвестное действие или недостаточно параметров.';
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = 'Ошибка БД: ' . $e->getMessage();
}

header('Location: /admin/index.php');
exit;