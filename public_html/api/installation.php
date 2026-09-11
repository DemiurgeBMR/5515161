<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

// ========== ФОТО-ПОДТВЕРЖДЕНИЯ (service_photos) ==========
// Принимает массив файлов из $_FILES['photos'] (input type="file" multiple),
// сжимает через compressImage() из config.php, сохраняет в /uploads/service/,
// пишет по одной строке в service_photos на каждое успешно сохранённое фото.
// Возвращает количество успешно сохранённых файлов. Ошибки отдельных файлов
// не прерывают весь запрос — фото необязательны, лучше сохранить сколько
// получилось, чем откатить всё событие целиком из-за одной битой картинки.
function uploadErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'файл больше, чем разрешает сервер (upload_max_filesize/post_max_size в php.ini)';
        case UPLOAD_ERR_PARTIAL:
            return 'файл загрузился не полностью (обрыв соединения)';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'ошибка сервера при сохранении файла';
        default:
            return 'ошибка загрузки (код ' . $code . ')';
    }
}

// Возвращает ['saved' => int, 'errors' => [ 'имя_файла: причина', ... ]]
function saveServicePhotos($sourceType, $sourceId, $filesField) {
    global $pdo;

    $result = ['saved' => 0, 'errors' => []];

    if (empty($_FILES[$filesField]) || empty($_FILES[$filesField]['tmp_name'])) {
        return $result;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxBytes = 20 * 1024 * 1024; // 20 МБ на файл — с запасом под фото с современных телефонов;
                                    // сразу после загрузки файл всё равно пережимается compressImage()
    $uploadDir = __DIR__ . '/../uploads/service/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tmpNames = (array)$_FILES[$filesField]['tmp_name'];
    $origNames = (array)$_FILES[$filesField]['name'];
    $errors = (array)$_FILES[$filesField]['error'];
    $sizes = (array)$_FILES[$filesField]['size'];

    foreach ($tmpNames as $i => $tmpPath) {
        $name = $origNames[$i] ?? ('файл ' . ($i + 1));
        $errCode = $errors[$i] ?? UPLOAD_ERR_NO_FILE;

        if ($errCode === UPLOAD_ERR_NO_FILE) {
            continue; // пустой input — не ошибка, просто нечего сохранять
        }
        if ($errCode !== UPLOAD_ERR_OK) {
            $result['errors'][] = $name . ': ' . uploadErrorMessage($errCode);
            continue;
        }
        if (($sizes[$i] ?? 0) > $maxBytes) {
            $result['errors'][] = $name . ': больше ' . round($maxBytes / 1024 / 1024) . ' МБ';
            continue;
        }
        if (!getimagesize($tmpPath)) {
            $result['errors'][] = $name . ': не похоже на изображение';
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $result['errors'][] = $name . ': формат не поддерживается (нужен jpg/png/webp)';
            continue;
        }

        $newName = uniqid('svc_', true) . '.' . $ext;
        $destPath = $uploadDir . $newName;

        // applyWatermark = false — это фото-подтверждение, а не публичный листинг локации
        if (compressImage($tmpPath, $destPath, 1600, 1600, 82, false, false)) {
            $relativePath = 'uploads/service/' . $newName;
            $stmt = $pdo->prepare("INSERT INTO service_photos (source_type, source_id, photo_path) VALUES (?, ?, ?)");
            $stmt->execute([$sourceType, $sourceId, $relativePath]);
            $result['saved']++;
        } else {
            $result['errors'][] = $name . ': не удалось обработать изображение на сервере';
        }
    }

    return $result;
}

// Добавляет поле 'photos' (массив путей) к каждой строке из $rows,
// одним запросом на весь набор id — вызывать после основной выборки.
function attachServicePhotos(&$rows, $sourceType) {
    global $pdo;
    if (empty($rows)) {
        return;
    }
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT source_id, photo_path FROM service_photos
        WHERE source_type = ? AND source_id IN ($placeholders)
        ORDER BY created_at ASC
    ");
    $stmt->execute(array_merge([$sourceType], $ids));
    $photosBySourceId = [];
    foreach ($stmt->fetchAll() as $p) {
        $photosBySourceId[$p['source_id']][] = $p['photo_path'];
    }
    foreach ($rows as &$row) {
        $row['photos'] = $photosBySourceId[$row['id']] ?? [];
    }
    unset($row);
}
// =============================================

// Проверка прав на заявку (используется там, где событие всё ещё привязано к чату)
function checkApplicationAccess($app_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT operator_id, owner_id, location_id FROM applications WHERE id = ?");
    $stmt->execute([$app_id]);
    $app = $stmt->fetch();
    if (!$app || ($app['operator_id'] != $user_id && $app['owner_id'] != $user_id)) {
        return false;
    }
    return $app;
}

// Проверка прав на закрепление (основной источник правды для выездов и вендингов)
function checkLocationOperatorAccess($location_operator_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT lo.*, l.title as location_title
        FROM location_operators lo
        JOIN locations l ON l.id = lo.location_id
        WHERE lo.id = ? AND lo.status = 'active'
    ");
    $stmt->execute([$location_operator_id]);
    $lo = $stmt->fetch();
    if (!$lo || ($lo['operator_id'] != $user_id && $lo['owner_id'] != $user_id)) {
        return false;
    }
    return $lo;
}

$eventTypeLabels = [
    'installation' => 'установка',
    'maintenance'  => 'плановое обслуживание',
    'restock'      => 'пополнение товара',
    'repair'       => 'ремонт',
    'removal'      => 'демонтаж',
];

switch ($action) {
    // 1. Запросить визит (обе стороны равноправны — и оператор, и владелец)
    case 'request':
        $location_operator_id = (int)($_POST['location_operator_id'] ?? 0);
        $datetime = $_POST['datetime'] ?? '';
        $is_emergency = isset($_POST['is_emergency']) ? (int)$_POST['is_emergency'] : 0;
        $comment = trim($_POST['comment'] ?? '');
        $event_type = $_POST['event_type'] ?? 'maintenance';
        // application_id необязателен — передаётся, только если запрос создан из чата заявки
        $application_id = isset($_POST['application_id']) && (int)$_POST['application_id'] > 0
            ? (int)$_POST['application_id']
            : null;

        $allowed_event_types = ['installation', 'maintenance', 'restock', 'repair', 'removal'];
        if (!in_array($event_type, $allowed_event_types, true)) {
            echo json_encode(['error' => 'Invalid event type']);
            exit;
        }

        // Из чата заявки (application_chat.php) location_operator_id не передаётся —
        // там известен только application_id. Резолвим закрепление по паре
        // (location_id, operator_id) самой заявки: без активного закрепления
        // назначать реальный выезд физически некому, поэтому явно объясняем
        // это отдельной ошибкой, а не молчим про "Invalid parameters".
        if ($location_operator_id <= 0 && $application_id) {
            $stmt = $pdo->prepare("
                SELECT lo.id
                FROM applications a
                JOIN location_operators lo ON lo.location_id = a.location_id
                    AND lo.operator_id = a.operator_id AND lo.status = 'active'
                WHERE a.id = ? AND (a.operator_id = ? OR a.owner_id = ?)
            ");
            $stmt->execute([$application_id, $user_id, $user_id]);
            $location_operator_id = (int)($stmt->fetchColumn() ?: 0);

            if ($location_operator_id <= 0) {
                echo json_encode(['error' => 'Нельзя запланировать выезд: владелец ещё не закрепил оператора за этой локацией']);
                exit;
            }
        }

        if ($location_operator_id <= 0 || empty($datetime)) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }

        $lo = checkLocationOperatorAccess($location_operator_id, $user_id);
        if (!$lo) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        // Проверка на двойное бронирование и вставка — в одной транзакции с
        // блокировкой строк (FOR UPDATE), как в reschedule ниже: без этого
        // два параллельных запроса на одно и то же время у одного оператора
        // могли оба пройти проверку до того, как первый успеет вставить свою
        // запись.
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT e.id
                FROM installation_events e
                JOIN location_operators lo2 ON lo2.id = e.location_operator_id
                WHERE lo2.operator_id = ?
                  AND e.status NOT IN ('completed','cancelled')
                  AND (e.confirmed_datetime = ? OR e.proposed_datetime = ?)
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$lo['operator_id'], $datetime, $datetime]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                echo json_encode(['error' => 'У оператора уже запланирован другой выезд на это время']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO installation_events
                    (application_id, location_operator_id, event_type, proposed_datetime, status, requested_by, is_emergency, emergency_comment)
                VALUES (?, ?, ?, ?, 'requested', ?, ?, ?)
            ");
            $stmt->execute([$application_id, $location_operator_id, $event_type, $datetime, $user_id, $is_emergency, $comment ?: null]);
            $event_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO installation_event_log (event_id, action, new_datetime, user_id) VALUES (?, 'created', ?, ?)");
            $stmt->execute([$event_id, $datetime, $user_id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('installation.php request error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось создать заявку на выезд']);
            break;
        }

        // ===== УВЕДОМЛЕНИЕ =====
        $receiver_id = ($user_id == $lo['operator_id']) ? $lo['owner_id'] : $lo['operator_id'];
        $link = $application_id
            ? '/pages/application_chat.php?application_id=' . $application_id
            : '/pages/location.php?id=' . $lo['location_id'];
        $type = $is_emergency ? 'emergency_event' : 'event_requested';
        $message = $is_emergency
            ? '🚨 Срочный выезд запрошен для точки ' . $lo['location_title']
            : '📅 Запрошен визит (' . ($eventTypeLabels[$event_type] ?? $event_type) . ') для точки ' . $lo['location_title'];
        notify($pdo, $receiver_id, $type, $message, $link, ['event_id' => $event_id]);
        // =========================

        echo json_encode(['success' => true, 'event_id' => $event_id]);
        break;

    // 2. Подтвердить дату (любой участник)
    case 'confirm':
        $event_id = (int)($_POST['event_id'] ?? 0);
        $confirmed_datetime = $_POST['confirmed_datetime'] ?? null;

        if ($event_id <= 0) {
            echo json_encode(['error' => 'Invalid event ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT e.*, lo.operator_id, lo.owner_id, lo.location_id, l.title as location_title
            FROM installation_events e
            JOIN location_operators lo ON lo.id = e.location_operator_id
            JOIN locations l ON l.id = lo.location_id
            WHERE e.id = ?
        ");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch();
        if (!$event || ($event['operator_id'] != $user_id && $event['owner_id'] != $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        // Подтвердить дату должна вторая сторона — иначе автор предложения
        // мог бы в одиночку «закрепить» дату, которую сам же и предложил,
        // без реального согласия второго участника.
        if ($event['requested_by'] == $user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Дождитесь подтверждения от второй стороны — вы не можете подтвердить собственное предложение.']);
            exit;
        }

        $confirm_datetime = $confirmed_datetime ?? $event['proposed_datetime'];
        $stmt = $pdo->prepare("UPDATE installation_events SET status = 'confirmed', confirmed_datetime = ?, last_modified_by = ? WHERE id = ?");
        $stmt->execute([$confirm_datetime, $user_id, $event_id]);

        $stmt = $pdo->prepare("INSERT INTO installation_event_log (event_id, action, new_datetime, user_id) VALUES (?, 'confirmed', ?, ?)");
        $stmt->execute([$event_id, $confirm_datetime, $user_id]);

        // ===== УВЕДОМЛЕНИЕ =====
        $receiver_id = ($user_id == $event['operator_id']) ? $event['owner_id'] : $event['operator_id'];
        $link = $event['application_id']
            ? '/pages/application_chat.php?application_id=' . $event['application_id']
            : '/pages/location.php?id=' . $event['location_id'];
        notify($pdo, $receiver_id, 'event_confirmed', '✅ Дата выезда подтверждена', $link, ['event_id' => $event_id]);
        // =========================

        echo json_encode(['success' => true]);
        break;

    // 3. Предложить другую дату (reschedule)
    case 'reschedule':
        $event_id = (int)($_POST['event_id'] ?? 0);
        $new_datetime = trim($_POST['datetime'] ?? '');

        if ($event_id <= 0 || empty($new_datetime)) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }

        // Проверяем формат даты, чтобы в БД не ушло произвольное значение.
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $new_datetime);
        $dateErrors = DateTime::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            echo json_encode(['error' => 'Invalid datetime format']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    e.id,
                    e.application_id,
                    e.status,
                    e.proposed_datetime,
                    e.confirmed_datetime,
                    lo.operator_id,
                    lo.owner_id,
                    lo.location_id
                FROM installation_events e
                JOIN location_operators lo ON lo.id = e.location_operator_id
                WHERE e.id = ?
                  AND e.status NOT IN ('completed','cancelled')
                FOR UPDATE
            ");
            $stmt->execute([$event_id]);
            $event = $stmt->fetch();

            if (!$event || ($event['operator_id'] != $user_id && $event['owner_id'] != $user_id)) {
                $pdo->rollBack();
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            // Реальное текущее время — подтверждённое, если оно есть, иначе предложенное.
            $old_datetime = $event['confirmed_datetime'] ?: $event['proposed_datetime'];

            // Один и тот же оператор не должен получить два активных выезда на одну дату/время.
            $stmt = $pdo->prepare("
                SELECT e.id
                FROM installation_events e
                JOIN location_operators lo2 ON lo2.id = e.location_operator_id
                WHERE lo2.operator_id = ?
                  AND e.id <> ?
                  AND e.status NOT IN ('completed','cancelled')
                  AND (
                      e.confirmed_datetime = ?
                      OR e.proposed_datetime = ?
                  )
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                $event['operator_id'],
                $event_id,
                $new_datetime,
                $new_datetime
            ]);

            if ($stmt->fetch()) {
                $pdo->rollBack();
                echo json_encode([
                    'error' => 'На это время у оператора уже запланирован другой активный выезд'
                ]);
                exit;
            }

            // Перенос подтверждённого события превращает его в новое предложение.
            $stmt = $pdo->prepare("
                UPDATE installation_events
                SET proposed_datetime = ?,
                    confirmed_datetime = NULL,
                    status = 'reviewing',
                    last_modified_by = ?,
                    requested_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $new_datetime,
                $user_id,
                $user_id,
                $event_id
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO installation_event_log
                    (event_id, action, old_datetime, new_datetime, user_id)
                VALUES (?, 'rescheduled', ?, ?, ?)
            ");
            $stmt->execute([
                $event_id,
                $old_datetime,
                $new_datetime,
                $user_id
            ]);

            $pdo->commit();

            // Уведомление
            $receiver_id = ($user_id == $event['operator_id'])
                ? $event['owner_id']
                : $event['operator_id'];

            $link = $event['application_id']
                ? '/pages/application_chat.php?application_id=' . $event['application_id']
                : '/pages/location.php?id=' . $event['location_id'];
            notify(
                $pdo,
                $receiver_id,
                'event_rescheduled',
                '🔄 Дата выезда изменена',
                $link,
                ['event_id' => $event_id]
            );

            echo json_encode([
                'success' => true,
                'event_id' => $event_id,
                'old_datetime' => $old_datetime,
                'new_datetime' => $new_datetime,
                'status' => 'reviewing'
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('installation.php reschedule error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось перенести выезд']);
        }
        break;

    // 4. Отменить событие
    case 'cancel':
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) {
            echo json_encode(['error' => 'Invalid event ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT lo.operator_id, lo.owner_id, lo.location_id, e.application_id
            FROM installation_events e
            JOIN location_operators lo ON lo.id = e.location_operator_id
            WHERE e.id = ? AND e.status NOT IN ('completed')
        ");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch();
        if (!$event || ($event['operator_id'] != $user_id && $event['owner_id'] != $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE installation_events SET status = 'cancelled', last_modified_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $event_id]);

        $stmt = $pdo->prepare("INSERT INTO installation_event_log (event_id, action, user_id) VALUES (?, 'cancelled', ?)");
        $stmt->execute([$event_id, $user_id]);

        // ===== УВЕДОМЛЕНИЕ =====
        $receiver_id = ($user_id == $event['operator_id']) ? $event['owner_id'] : $event['operator_id'];
        $link = $event['application_id']
            ? '/pages/application_chat.php?application_id=' . $event['application_id']
            : '/pages/location.php?id=' . $event['location_id'];
        notify($pdo, $receiver_id, 'event_cancelled', '❌ Выезд отменён', $link, ['event_id' => $event_id]);
        // =========================

        echo json_encode(['success' => true]);
        break;

    // 5. Завершить событие (completed)
    case 'complete':
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) {
            echo json_encode(['error' => 'Invalid event ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT lo.operator_id, lo.owner_id, lo.location_id, e.application_id
            FROM installation_events e
            JOIN location_operators lo ON lo.id = e.location_operator_id
            WHERE e.id = ? AND e.status = 'confirmed'
        ");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch();
        if (!$event || ($event['operator_id'] != $user_id && $event['owner_id'] != $user_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE installation_events SET status = 'completed', last_modified_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $event_id]);

        $stmt = $pdo->prepare("INSERT INTO installation_event_log (event_id, action, user_id) VALUES (?, 'completed', ?)");
        $stmt->execute([$event_id, $user_id]);

        // Фото-подтверждения (необязательно)
        $photosResult = saveServicePhotos('event', $event_id, 'photos');

        // ===== УВЕДОМЛЕНИЕ =====
        $receiver_id = ($user_id == $event['operator_id']) ? $event['owner_id'] : $event['operator_id'];
        $link = $event['application_id']
            ? '/pages/application_chat.php?application_id=' . $event['application_id']
            : '/pages/location.php?id=' . $event['location_id'];
        notify($pdo, $receiver_id, 'event_completed', '✅ Выезд завершён', $link, ['event_id' => $event_id]);
        // =========================

        echo json_encode(['success' => true, 'photos_saved' => $photosResult['saved'], 'photo_errors' => $photosResult['errors']]);
        break;

    // 6. Получить события для заявки (используется в чате — там, где событие реально привязано к чату)
    case 'get_for_application':
        $application_id = (int)($_GET['application_id'] ?? 0);
        if ($application_id <= 0) {
            echo json_encode(['error' => 'Invalid application ID']);
            exit;
        }

        $app = checkApplicationAccess($application_id, $user_id);
        if (!$app) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT e.*, u.full_name as requested_by_name
            FROM installation_events e
            JOIN users u ON e.requested_by = u.id
            WHERE e.application_id = ?
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([$application_id]);
        $events = $stmt->fetchAll();

        echo json_encode(['events' => $events]);
        break;

    // 7. Получить все события + постфактум-отметки для пользователя (единый календарь)
    case 'get_for_user':
        $role = $_GET['role'] ?? '';
        if (!in_array($role, ['operator', 'owner'])) {
            echo json_encode(['error' => 'Invalid role']);
            exit;
        }

        $field = ($role === 'operator') ? 'lo.operator_id' : 'lo.owner_id';

        // Запланированные/подтверждённые визиты
        $stmt = $pdo->prepare("
            SELECT e.id, e.location_operator_id, e.application_id, e.event_type,
                   e.proposed_datetime, e.confirmed_datetime, e.status,
                   e.is_emergency, e.emergency_comment, e.requested_by,
                   l.id as location_id, l.title as location_title, l.city,
                   op.full_name as operator_name, ow.full_name as owner_name
            FROM installation_events e
            JOIN location_operators lo ON lo.id = e.location_operator_id
            JOIN locations l ON l.id = lo.location_id
            JOIN users op ON lo.operator_id = op.id
            JOIN users ow ON lo.owner_id = ow.id
            WHERE $field = ? AND lo.status = 'active'
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $events = $stmt->fetchAll();

        // Постфактум-отметки обслуживания (для того же единого календаря)
        $stmt = $pdo->prepare("
            SELECT sl.id, sl.machine_id, sl.event_type, sl.comment, sl.performed_at, sl.performed_by,
                   lo.id as location_operator_id,
                   l.id as location_id, l.title as location_title, l.city,
                   op.full_name as operator_name, ow.full_name as owner_name
            FROM machine_service_log sl
            JOIN location_machines m ON m.id = sl.machine_id
            JOIN location_operators lo ON lo.id = m.location_operator_id
            JOIN locations l ON l.id = lo.location_id
            JOIN users op ON lo.operator_id = op.id
            JOIN users ow ON lo.owner_id = ow.id
            WHERE $field = ? AND lo.status = 'active'
            ORDER BY sl.performed_at DESC
        ");
        $stmt->execute([$user_id]);
        $quick_logs = $stmt->fetchAll();

        // Фото-подтверждения — одним запросом на каждый источник, без N+1
        attachServicePhotos($events, 'event');
        attachServicePhotos($quick_logs, 'log');

        echo json_encode(['events' => $events, 'quick_logs' => $quick_logs]);
        break;


    // 8. Быстрая отметка обслуживания (привязана к закреплению, не к заявке)
    case 'quick_service':
        $location_operator_id = (int)($_POST['location_operator_id'] ?? 0);
        $event_type = $_POST['event_type'] ?? 'maintenance';
        $comment = trim($_POST['comment'] ?? '');

        $allowed_types = ['maintenance', 'restock', 'repair'];
        if (!in_array($event_type, $allowed_types, true)) {
            echo json_encode(['error' => 'Invalid event type']);
            exit;
        }
        if ($location_operator_id <= 0) {
            echo json_encode(['error' => 'Invalid location_operator_id']);
            exit;
        }

        $role = $_SESSION['user_role'] ?? '';
        if ($role !== 'operator') {
            http_response_code(403);
            echo json_encode(['error' => 'Only the assigned operator can log service']);
            exit;
        }

        // Проверяем, что это активное закрепление именно текущего оператора
        $stmt = $pdo->prepare("
            SELECT lo.*, l.title as location_title
            FROM location_operators lo
            JOIN locations l ON l.id = lo.location_id
            WHERE lo.id = ? AND lo.operator_id = ? AND lo.status = 'active'
        ");
        $stmt->execute([$location_operator_id, $user_id]);
        $lo = $stmt->fetch();
        if (!$lo) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Находим (или создаём) карточку машины на этой точке
            $stmt = $pdo->prepare("SELECT id FROM location_machines WHERE location_operator_id = ?");
            $stmt->execute([$location_operator_id]);
            $machine = $stmt->fetch();

            if ($machine) {
                $machine_id = $machine['id'];
                $stmt = $pdo->prepare("UPDATE location_machines SET last_service_at = NOW(), status = 'active' WHERE id = ?");
                $stmt->execute([$machine_id]);
            } else {
                // Машина ещё не описана — создаём минимальную карточку
                $stmt = $pdo->prepare("
                    INSERT INTO location_machines (location_operator_id, machine_type, last_service_at, status)
                    VALUES (?, 'other', NOW(), 'active')
                ");
                $stmt->execute([$location_operator_id]);
                $machine_id = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("
                INSERT INTO machine_service_log (machine_id, event_type, comment, performed_by, performed_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$machine_id, $event_type, $comment ?: null, $user_id]);
            $log_id = $pdo->lastInsertId();

            $pdo->commit();

            // Фото-подтверждения (необязательно) — сохраняются уже после commit,
            // чтобы не держать транзакцию открытой на время работы с файлами
            $photosResult = saveServicePhotos('log', $log_id, 'photos');

            // Уведомление владельцу — постфактум, без запроса на подтверждение
            $typeLabels = ['maintenance' => 'обслуживание', 'restock' => 'пополнение товара', 'repair' => 'ремонт'];
            $link = '/pages/location.php?id=' . $lo['location_id'];
            $message = '🔧 Оператор отметил: ' . ($typeLabels[$event_type] ?? $event_type) . ' на точке ' . $lo['location_title'];
            notify($pdo, $lo['owner_id'], 'quick_service', $message, $link, ['log_id' => $log_id]);

            echo json_encode(['success' => true, 'log_id' => $log_id, 'machine_id' => $machine_id, 'photos_saved' => $photosResult['saved'], 'photo_errors' => $photosResult['errors']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('installation.php quick_service error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось сохранить отметку обслуживания']);
        }
        break;

    // 9. Сохранить данные вендинга на точке (привязано к закреплению, не к заявке)
    case 'save_machine':
        $location_operator_id = (int)($_POST['location_operator_id'] ?? 0);
        $machine_type = trim($_POST['machine_type'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $serial_number = trim($_POST['serial_number'] ?? '');
        $installed_at = trim($_POST['installed_at'] ?? '');

        $allowed_machine_types = ['snacks', 'drinks', 'coffee', 'combo', 'other'];
        if (!in_array($machine_type, $allowed_machine_types, true)) {
            echo json_encode(['error' => 'Invalid machine type']);
            exit;
        }
        if ($location_operator_id <= 0) {
            echo json_encode(['error' => 'Invalid location_operator_id']);
            exit;
        }

        $role = $_SESSION['user_role'] ?? '';
        if ($role !== 'operator') {
            http_response_code(403);
            echo json_encode(['error' => 'Only operators can manage machine info']);
            exit;
        }

        // Только своё активное закрепление
        $stmt = $pdo->prepare("SELECT id FROM location_operators WHERE id = ? AND operator_id = ? AND status = 'active'");
        $stmt->execute([$location_operator_id, $user_id]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        // Валидация даты установки (необязательное поле)
        $installedAtValue = null;
        if ($installed_at !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $installed_at);
            if (!$d) {
                echo json_encode(['error' => 'Invalid installed_at format']);
                exit;
            }
            $installedAtValue = $d->format('Y-m-d');
        }

        $stmt = $pdo->prepare("
            INSERT INTO location_machines (location_operator_id, machine_type, model, serial_number, installed_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                machine_type = VALUES(machine_type),
                model = VALUES(model),
                serial_number = VALUES(serial_number),
                installed_at = VALUES(installed_at)
        ");
        $stmt->execute([
            $location_operator_id,
            $machine_type,
            $model ?: null,
            $serial_number ?: null,
            $installedAtValue
        ]);

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}