<?php
// ============================================
// КОНФИГУРАЦИЯ ПРОЕКТА RR (riveg-rent)
// ============================================

// Набор SVG-иконок взамен эмодзи — см. includes/icons.php
require_once __DIR__ . '/includes/icons.php';

// --- НАСТРОЙКИ БАЗЫ ДАННЫХ ---
// Значения берутся из переменных окружения, если заданы (на проде — обязательно
// через окружение), иначе используются дефолты локальной разработки в Open Server.
define('DB_HOST', getenv('DB_HOST') ?: 'MySQL-8.0');   // Имя модуля MySQL в Open Server
define('DB_NAME', getenv('DB_NAME') ?: 'riveg_rent');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');            // По умолчанию пароль пустой (только для локальной разработки)

// --- ГЛОБАЛЬНЫЕ ПАРАМЕТРЫ ---
define('SITE_NAME', 'RR - Riveg Rent');
define('SITE_URL', 'http://riveg-rent.local');

// --- РЕЖИМ РАЗРАБОТКИ ---
// true = показывать ошибки и стектрейсы, false = скрывать (для продакшена).
// Дефолт — false: боевой сервер не должен внезапно начать светить внутренние
// детали посетителям только потому, что кто-то забыл выставить переменную
// окружения. Включать явно через DEBUG_MODE=true только на деве.
define('DEBUG_MODE', filter_var(getenv('DEBUG_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// --- ЗАЩИТА ВХОДА ОТ ПОДБОРА ПАРОЛЯ ---
// После LOGIN_MAX_ATTEMPTS неудачных попыток подряд аккаунт временно
// блокируется на LOGIN_LOCKOUT_MINUTES минут (см. pages/login.php).
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// --- ВОССТАНОВЛЕНИЕ ПАРОЛЯ ---
// Пока нет настроенной отправки почты (проект на локалке) — ссылка для
// сброса пароля просто показывается на экране вместо письма (см.
// pages/forgot_password.php). PASSWORD_RESET_TTL_MINUTES — срок жизни
// токена сброса.
define('PASSWORD_RESET_TTL_MINUTES', 60);

// --- ПОДТВЕРЖДЕНИЕ EMAIL ---
// Та же временная схема, что и для сброса пароля — почты пока нет, ссылка
// подтверждения показывается на экране (см. pages/verify_email.php).
define('VERIFY_EMAIL_TTL_HOURS', 24);

/**
 * Выпускает новый токен подтверждения email для пользователя и возвращает
 * готовую ссылку — используется и при регистрации, и при повторной отправке
 * с profile.php, чтобы не дублировать логику генерации токена.
 */
function rr_issue_verify_link(PDO $pdo, $userId) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + VERIFY_EMAIL_TTL_HOURS * 3600);
    $pdo->prepare("UPDATE users SET verify_token = ?, verify_token_expires = ? WHERE id = ?")
        ->execute([$token, $expires, $userId]);
    return SITE_URL . '/pages/verify_email.php?token=' . $token;
}

// Путь к файлу водяного знака (PNG с прозрачностью)
define('WATERMARK_PATH', __DIR__ . '/assets/images/watermark.png');

// Через сколько дней без обслуживания точка считается "требующей внимания"
define('SERVICE_DUE_DAYS', 14);

/**
 * Порог даты (Y-m-d H:i:s) для SQL-условий вида
 * "COALESCE(last_service_at, installed_at) < ?" — единая точка правды для
 * SERVICE_DUE_DAYS, чтобы дашборд оператора, список его точек и cron-
 * напоминания не считали "просрочено" по-разному.
 */
function serviceDueCutoffDate() {
    return date('Y-m-d H:i:s', time() - SERVICE_DUE_DAYS * 86400);
}

/**
 * true, если число дней с последнего обслуживания/установки ($days,
 * либо null, если данных ещё нет) означает, что точка требует внимания.
 */
function isServiceOverdue($days) {
    return $days !== null && $days >= SERVICE_DUE_DAYS;
}

/**
 * Заглушка платной подписки (users.has_subscription, включается/выключается
 * на pages/subscription.php — без реальной оплаты; полноценная таблица
 * `subscriptions` с планами/датами уже есть в БД про запас, но пока не
 * используется — это сознательно самая простая версия, вкл/выкл).
 * От неё зависит доступ к карте с точками и к точному адресу локации.
 *
 * Админ считается имеющим полный доступ всегда — это внутренний
 * персонал, а не участник платной модели. Требует, чтобы session_start()
 * уже был вызван.
 */
function currentUserHasSubscription() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (($_SESSION['user_role'] ?? null) === 'admin') {
        return true;
    }
    return !empty($_SESSION['has_subscription']);
}

/**
 * Куда отправить пользователя сразу после успешного входа — общая точка
 * правды для pages/login.php и pages/verify_2fa.php (второй нужен, когда
 * у аккаунта включена двухфакторная аутентификация), чтобы они не могли
 * разойтись между собой.
 */
function rr_login_redirect_url($role) {
    if ($role === 'admin') {
        return '/admin/index.php';
    }
    if ($role === 'operator') {
        return '/pages/operator_dashboard.php';
    }
    return '/pages/profile.php';
}

// Единственно допустимые цвета аватара — используется и для валидации при
// сохранении, и для отрисовки палитры выбора, чтобы эти два места не разъезжались.
define('ALLOWED_AVATAR_COLORS', [
    '#ff617b', '#3498db', '#2ecc71', '#f39c12', '#3f0058',
    '#1abc9c', '#e67e22', '#e74c3c', '#2c3e50', '#8e44ad',
    '#006954', '#fd79a8', '#6c5ce7', '#fdcb6e', '#00cec9',
]);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // error_reporting(0) раньше означал не просто "не показывать" — PHP
    // вообще переставал что-либо логировать, включая необработанные фатальные
    // ошибки. В проде ошибки по-прежнему нужно видеть в логе сервера, просто
    // не показывать посетителям.
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// --- ПОДКЛЮЧЕНИЕ К БД (функция) ---
function getDbConnection() {
    try {
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS
);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log('getDbConnection: ' . $e->getMessage());
        // Раньше это всегда показывало сырой текст исключения посетителю,
        // независимо от DEBUG_MODE, — потенциальная утечка деталей о БД.
        die(DEBUG_MODE ? 'Ошибка подключения к базе данных: ' . $e->getMessage() : 'Сервис временно недоступен. Попробуйте немного позже.');
    }
}

// --- ФУНКЦИЯ ДЛЯ ПРОВЕРКИ ПОДКЛЮЧЕНИЯ ---
function testDbConnection() {
    try {
        $pdo = getDbConnection();
        return true;
    } catch (Exception $e) {
        error_log('testDbConnection: ' . $e->getMessage());
        return false;
    }
}

// --- КЕШИРОВАНИЕ ---
function getCachePath($key, $ttl = 3600) {
    $cacheDir = __DIR__ . '/../cache/recommendations/';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    return $cacheDir . md5($key) . '.cache';
}

function getCached($key, $ttl = 3600) {
    $file = getCachePath($key, $ttl);
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > $ttl) {
        unlink($file);
        return null;
    }
    return unserialize(file_get_contents($file));
}

function setCache($key, $data) {
    $file = getCachePath($key);
    file_put_contents($file, serialize($data));
}

function clearCache($key = null) {
    if ($key) {
        $file = getCachePath($key);
        if (file_exists($file)) unlink($file);
    } else {
        // Очистка всей папки (на случай, если понадобится)
        $dir = __DIR__ . '/../cache/recommendations/';
        if (is_dir($dir)) {
            foreach (glob($dir . '*') as $f) unlink($f);
        }
    }
}

/**
 * Сжатие изображения и наложение PNG-водяного знака (с отладкой)
 */
function compressImage($sourcePath, $destinationPath, $maxWidth = 1200, $maxHeight = 1200, $quality = 80, $debug = false, $applyWatermark = true)
{
    // Включаем вывод ошибок, если debug
    if ($debug) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        echo "<!-- DEBUG: compressImage started -->\n";
    }

    // 1. Загружаем исходное изображение
    if (!file_exists($sourcePath)) {
        if ($debug) echo "<!-- ERROR: source file not found: $sourcePath -->\n";
        return false;
    }

    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        if ($debug) echo "<!-- ERROR: getimagesize failed for $sourcePath -->\n";
        return false;
    }

    $mimeType = $imageInfo['mime'];
    $width = $imageInfo[0];
    $height = $imageInfo[1];

    if ($debug) echo "<!-- Source: $sourcePath, $width x $height, $mimeType -->\n";

    // Создаём ресурс исходного изображения
    switch ($mimeType) {
        case 'image/jpeg': $srcImage = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $srcImage = @imagecreatefrompng($sourcePath);  break;
        case 'image/webp': $srcImage = @imagecreatefromwebp($sourcePath); break;
        case 'image/gif':  $srcImage = @imagecreatefromgif($sourcePath);  break;
        default:
            if ($debug) echo "<!-- ERROR: unsupported mime type: $mimeType -->\n";
            return false;
    }

    if (!$srcImage) {
        if ($debug) echo "<!-- ERROR: failed to create image from source (GD error) -->\n";
        return false;
    }

    // 2. Определяем новые размеры
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    if ($ratio < 1) {
        $newWidth  = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
    } else {
        $newWidth  = $width;
        $newHeight = $height;
    }

    if ($debug) echo "<!-- New size: $newWidth x $newHeight (ratio: $ratio) -->\n";

    // 3. Создаём пустой ресурс
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Поддержка прозрачности
    if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // 4. Копируем с масштабированием
    imagecopyresampled($newImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // 5. НАЛОЖЕНИЕ ВОДЯНОГО ЗНАКА
    if ($applyWatermark && defined('WATERMARK_PATH') && file_exists(WATERMARK_PATH)) {
        if ($debug) echo "<!-- Watermark file found: " . WATERMARK_PATH . " -->\n";

        $watermark = @imagecreatefrompng(WATERMARK_PATH);
        if ($watermark) {
            if ($debug) echo "<!-- Watermark loaded successfully -->\n";
            $wmWidth  = imagesx($watermark);
            $wmHeight = imagesy($watermark);

            $newWmWidth  = (int)($newWidth * 0.15);
            $newWmHeight = (int)($wmHeight * ($newWmWidth / $wmWidth));

            if ($newWmWidth < 50) $newWmWidth = 50;
            if ($newWmWidth > 300) $newWmWidth = 300;
            $newWmHeight = (int)($wmHeight * ($newWmWidth / $wmWidth));

            if ($debug) echo "<!-- Watermark resized to $newWmWidth x $newWmHeight -->\n";

            $wmResized = imagecreatetruecolor($newWmWidth, $newWmHeight);
            imagealphablending($wmResized, false);
            imagesavealpha($wmResized, true);
            $transparentWm = imagecolorallocatealpha($wmResized, 0, 0, 0, 127);
            imagefilledrectangle($wmResized, 0, 0, $newWmWidth, $newWmHeight, $transparentWm);
            imagecopyresampled($wmResized, $watermark, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);

            $dstX = $newWidth - $newWmWidth - 10;
            $dstY = $newHeight - $newWmHeight - 10;
            if ($dstX < 0) $dstX = 0;
            if ($dstY < 0) $dstY = 0;

            imagealphablending($newImage, true);
            imagecopy($newImage, $wmResized, $dstX, $dstY, 0, 0, $newWmWidth, $newWmHeight);

            if ($debug) echo "<!-- Watermark applied at ($dstX, $dstY) -->\n";
        } else {
            if ($debug) echo "<!-- ERROR: failed to load watermark from PNG -->\n";
        }
    } else {
        if ($debug) echo "<!-- Watermark file NOT found or constant not defined -->\n";
    }

    // 6. Сохраняем результат
    $result = false;
    switch ($mimeType) {
        case 'image/jpeg': $result = imagejpeg($newImage, $destinationPath, $quality); break;
        case 'image/png':  $result = imagepng($newImage, $destinationPath, 8); break;
        case 'image/webp': $result = imagewebp($newImage, $destinationPath, $quality); break;
        case 'image/gif':  $result = imagegif($newImage, $destinationPath); break;
    }

    if ($debug) {
        echo "<!-- Save result: " . ($result ? 'SUCCESS' : 'FAILED') . " -->\n";
        if (!$result) {
            echo "<!-- Check destination path: $destinationPath and permissions -->\n";
        }
    }

    return $result;
}

// --- ФУНКЦИИ ДЛЯ ФОРМАТИРОВАНИЯ ДАТЫ ---
function formatDate($date) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date('d.m.Y', $timestamp);
}

/**
 * Геокодирование адреса через OpenStreetMap Nominatim (бесплатно, без ключей API).
 * Nominatim Usage Policy разрешает и прямо требует кешировать результат —
 * поэтому он сохраняется в файловый кеш на 30 дней, а после одобрения ревизии
 * координаты остаются в locations.latitude/longitude навсегда (повторный запрос
 * к геокодеру для той же локации больше не нужен).
 *
 * Соблюдает лимит Nominatim в 1 запрос/сек через файл с меткой времени последнего запроса.
 *
 * Заодно возвращает нормализованное имя населённого пункта из разбора адреса
 * Nominatim (city — единая классификация вместо свободного текста от
 * пользователя, включая опечатки и разное написание одного и того же города).
 * null в 'city', если Nominatim не смог определить населённый пункт —
 * вызывающий код в этом случае просто оставляет то, что ввёл пользователь.
 *
 * Город и адрес отправляются раздельными полями структурного запроса (city=,
 * street=), а не одной строкой в q= — свободный текст вида "Симферополь, ул.
 * Полюсная, д. 49" Nominatim иногда разбирает неверно и уверенно подставляет
 * координаты совсем другой улицы. Дополнительно сверяем распознанную улицу с
 * тем, что ввёл пользователь (rr_geocode_street_matches) — если общих слов
 * нет вообще, это тот самый случай подмены адреса, и координаты не отдаём
 * (город при этом всё равно можно использовать, он определяется надёжнее).
 *
 * @return array{lat: float, lng: float, city: ?string}|null
 */
function rr_geocode_street_words($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $stopWords = ['ул', 'улица', 'пр', 'пр-т', 'проспект', 'пер', 'переулок',
        'д', 'дом', 'кв', 'квартира', 'корп', 'корпус', 'стр', 'строение', 'г'];
    $words = preg_split('/[^a-zа-яё0-9]+/iu', $text) ?: [];
    return array_values(array_filter($words, function ($w) use ($stopWords) {
        return mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, $stopWords, true) && !ctype_digit($w);
    }));
}

function rr_geocode_street_matches($inputAddress, $resolvedRoad) {
    if (empty($resolvedRoad)) {
        // Nominatim вообще не вернул название улицы (например, нашёл только
        // город) — сверять не с чем, но и подменять адрес тут нечем, поэтому
        // не блокируем: доверяем координатам как есть.
        return true;
    }
    $inputWords = rr_geocode_street_words($inputAddress);
    if (empty($inputWords)) {
        return true;
    }
    $resolvedWords = rr_geocode_street_words($resolvedRoad);
    foreach ($inputWords as $w) {
        foreach ($resolvedWords as $rw) {
            if ($w === $rw || mb_strpos($w, $rw, 0, 'UTF-8') !== false || mb_strpos($rw, $w, 0, 'UTF-8') !== false) {
                return true;
            }
        }
    }
    return false;
}

function geocodeAddress($address, $city) {
    $city = trim($city);
    $address = trim($address);
    if ($city === '' && $address === '') return null;

    // geo2_ вместо geo_ — старый кеш строился на свободнотекстовом запросе и
    // мог содержать неверно подобранные координаты; префикс сменён, чтобы
    // после этого фикса такие записи не отдавались повторно ещё месяц.
    $cacheKey = 'geo2_' . md5(mb_strtolower($city . '|' . $address, 'UTF-8'));
    $cached = getCached($cacheKey, 30 * 24 * 3600);
    if ($cached !== null) {
        return $cached ?: null;
    }

    $lockFile = sys_get_temp_dir() . '/rr_nominatim_last_request.lock';
    $lastRequestTime = file_exists($lockFile) ? (float) file_get_contents($lockFile) : 0;
    $elapsed = microtime(true) - $lastRequestTime;
    if ($elapsed < 1.1) {
        usleep((int) ((1.1 - $elapsed) * 1000000));
    }
    file_put_contents($lockFile, microtime(true));

    $params = [
        'format'         => 'json',
        'addressdetails' => 1,
        'limit'          => 1,
    ];
    if ($address !== '') $params['street'] = $address;
    if ($city !== '') $params['city'] = $city;
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['User-Agent: RivegRent/1.0 (' . SITE_URL . ')'],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);

    if ($curlError || !$response) {
        setCache($cacheKey, false);
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
        setCache($cacheKey, false);
        return null;
    }

    // Разные теги в зависимости от типа населённого пункта — Nominatim не
    // всегда присылает именно 'city' (для посёлков и сёл это 'town'/'village').
    $addr = $data[0]['address'] ?? [];
    $resolvedCity = $addr['city'] ?? $addr['town'] ?? $addr['village']
        ?? $addr['municipality'] ?? $addr['county'] ?? null;

    $resolvedRoad = $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null;
    $streetMatches = $address === '' || rr_geocode_street_matches($address, $resolvedRoad);

    $result = [
        'lat'  => $streetMatches ? (float) $data[0]['lat'] : null,
        'lng'  => $streetMatches ? (float) $data[0]['lon'] : null,
        'city' => $resolvedCity,
    ];
    setCache($cacheKey, $result);
    return $result;
}

// --- КВОТА НА ЗАГРУЗКУ ФАЙЛОВ ---
// Размер и число файлов раньше ограничивались только на один запрос — ничто
// не мешало настойчивому пользователю копить фото годами и постепенно
// заполнить диск сервера. USER_UPLOAD_QUOTA_BYTES — суммарный лимит на
// пользователя по всем его фото (локации + ожидающие модерации ревизии +
// фото подтверждения обслуживания).
define('USER_UPLOAD_QUOTA_BYTES', 200 * 1024 * 1024); // 200 МБ на пользователя

/**
 * Реальный размер файла на диске по пути, как он хранится в БД (относительно
 * корня public_html, без ведущего слэша, например "uploads/service/x.jpg").
 * 0, если файла уже нет на диске — не даём отсутствующему файлу молча ломать
 * подсчёт квоты.
 */
function rr_upload_file_size($relativePath) {
    $fullPath = __DIR__ . '/' . $relativePath;
    return is_file($fullPath) ? (int) filesize($fullPath) : 0;
}

/**
 * Суммарный объём (в байтах), который пользователь уже занимает на диске:
 * фото активных локаций (владелец), фото в ещё не одобренных ревизиях
 * (владелец) и фото подтверждения обслуживания (кто именно загрузил —
 * uploaded_by, см. миграцию 2026_09_15_add_uploaded_by_to_service_photos.sql;
 * у фото, загруженных до этой миграции, атрибуции нет, и в подсчёт для
 * конкретного пользователя они не попадают).
 */
function getUserUploadedBytes(PDO $pdo, $userId) {
    $total = 0;

    $stmt = $pdo->prepare("
        SELECT lp.photo_path
        FROM location_photos lp
        JOIN locations l ON l.id = lp.location_id
        WHERE l.owner_id = ?
    ");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $total += rr_upload_file_size($path);
    }

    $stmt = $pdo->prepare("
        SELECT lr.data
        FROM location_revisions lr
        JOIN locations l ON l.id = lr.location_id
        WHERE l.owner_id = ? AND lr.status = 'pending'
    ");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $data = json_decode($json, true);
        foreach ($data['new_photos'] ?? [] as $path) {
            $total += rr_upload_file_size($path);
        }
    }

    $stmt = $pdo->prepare("SELECT photo_path FROM service_photos WHERE uploaded_by = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $total += rr_upload_file_size($path);
    }

    return $total;
}

/**
 * true, если у пользователя ещё есть место в его личной квоте (с запасом
 * $additionalBytes под файл, который вот-вот сохранят) И на диске сервера в
 * целом остаётся разумный запас — вторая проверка не даёт исчерпать диск
 * даже если бы много разных пользователей одновременно уложились каждый в
 * свою квоту (задел на будущее, сейчас маловероятно, но дёшево проверить).
 */
function rr_has_upload_room(PDO $pdo, $userId, $additionalBytes = 0) {
    $free = @disk_free_space(__DIR__);
    if ($free !== false && $free < 500 * 1024 * 1024) { // <500 МБ свободно на диске
        return false;
    }
    return (getUserUploadedBytes($pdo, $userId) + $additionalBytes) <= USER_UPLOAD_QUOTA_BYTES;
}

// --- CSRF-ЗАЩИТА ---
// Требует, чтобы session_start() уже был вызван к моменту обращения.

/**
 * Возвращает CSRF-токен текущей сессии, создавая его при первом обращении.
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Готовый экранированный <input type="hidden"> с токеном — для вставки в форму.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Сверяет переданный токен с токеном сессии constant-time сравнением.
 */
function csrf_verify($token) {
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * То же самое, но сама достаёт токен из тела POST-запроса (csrf_token) или
 * из заголовка X-CSRF-Token — этим заголовком пользуются fetch()/XHR/$.ajax
 * запросы, которым неудобно класть токен в тело (см. includes/footer.php,
 * где токен подставляется в такие запросы автоматически на клиенте).
 * Все API-эндпоинты, принимающие POST, должны проверять токен через неё.
 */
function csrf_verify_request() {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return csrf_verify($token);
}

// --- RATE LIMITING ---
// Раньше ограничение по частоте было только на логине (блокировка аккаунта
// после серии неудачных попыток). Остальные API-эндпоинты можно было дёргать
// без остановки. Простой rate-limiter с фиксированным окном на базе таблицы
// `rate_limits` (см. миграцию 2026_09_15_add_rate_limits_table.sql) — одна
// строка на ключ, окно и счётчик сбрасываются при переходе в новое окно,
// поэтому таблица не растёт бесконечно.

/**
 * true, если запрос с этим ключом ещё укладывается в лимит $maxRequests за
 * последние $windowSeconds секунд (и инкрементирует счётчик), false — если
 * лимит уже превышен. Ключ должен однозначно определять "кого" ограничиваем
 * для конкретного действия, например "send_message:42" (эндпоинт + user_id)
 * или "cities:203.0.113.5" (эндпоинт + IP — для запросов без авторизации).
 */
function rr_check_rate_limit(PDO $pdo, $key, $maxRequests, $windowSeconds) {
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO rate_limits (rate_key, window_start, request_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE
                request_count = IF(window_start = VALUES(window_start), request_count + 1, 1),
                window_start = VALUES(window_start)
        ");
        $stmt->execute([$key, $windowStart]);

        $stmt = $pdo->prepare("SELECT request_count FROM rate_limits WHERE rate_key = ?");
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn() <= $maxRequests;
    } catch (PDOException $e) {
        // Лимит — защита от злоупотреблений, а не граница безопасности: если
        // сама проверка не выполнилась (например, таблица rate_limits ещё не
        // накатилась миграцией), пропускаем запрос, а не роняем весь эндпоинт.
        error_log('rr_check_rate_limit(' . $key . '): ' . $e->getMessage());
        return true;
    }
}

/**
 * Готовый вызов для начала API-эндпоинта: если лимит превышен — сразу
 * отвечает 429 в том же JSON-формате, что и остальные ошибки эндпоинтов,
 * и завершает скрипт. Иначе просто возвращается, не мешая обработке дальше.
 */
function rr_enforce_rate_limit(PDO $pdo, $key, $maxRequests, $windowSeconds) {
    if (!rr_check_rate_limit($pdo, $key, $maxRequests, $windowSeconds)) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        echo json_encode(['error' => 'Слишком много запросов. Попробуйте немного позже.']);
        exit;
    }
}

/**
 * IP клиента с учётом X-Forwarded-For от прокси/балансировщика (берём первый
 * адрес в цепочке — исходный клиент) — для rate-limit ключей у эндпоинтов без
 * авторизации, где нет user_id для идентификации запрашивающего.
 */
function rr_client_ip() {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        return trim(explode(',', $forwarded)[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function formatDateRu($date) {
    if (empty($date)) return '';
    $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
               'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $months[date('n', $timestamp) - 1];
    $year = date('Y', $timestamp);
    return $day . ' ' . $month . ' ' . $year;
}

// --- УВЕДОМЛЕНИЯ ---
// Единая точка правды для типов уведомлений: категория (для фильтров и
// настроек), иконка и подпись (для UI) — раньше эмодзи вручную вписывались
// в текст сообщения при создании и жили в 3 разных файлах, из-за чего часть
// типов не имела иконки вообще. Добавляя новый тип уведомления — сначала
// впиши его сюда, notify() откажет на неизвестном типе.
// 'icon' — имя из includes/icons.php (RR_ICONS), а не эмодзи: значение уходит
// и в PHP-рендер (через rr_icon()), и в JSON для notifications.js, где ему
// соответствует свой мини-набор SVG-путей (ICON_PATHS) — держать оба в
// синхроне при добавлении новой иконки сюда.
const NOTIFICATION_CATEGORIES = [
    'chat'        => ['icon' => 'message-circle', 'label' => 'Сообщения'],
    'visits'      => ['icon' => 'calendar',       'label' => 'Визиты и обслуживание'],
    'assignment'  => ['icon' => 'check',          'label' => 'Заявки и закрепления'],
    'maintenance' => ['icon' => 'wrench',         'label' => 'Обслуживание точек'],
    'moderation'  => ['icon' => 'shield',         'label' => 'Модерация'],
    'system'      => ['icon' => 'info-circle',    'label' => 'Системные'],
];

const NOTIFICATION_META = [
    'new_message'          => ['category' => 'chat',        'icon' => 'message-circle'],
    'event_requested'      => ['category' => 'visits',      'icon' => 'calendar'],
    'emergency_event'      => ['category' => 'visits',      'icon' => 'warning'],
    'event_confirmed'      => ['category' => 'visits',      'icon' => 'check'],
    'event_rescheduled'    => ['category' => 'visits',      'icon' => 'refresh'],
    'event_cancelled'      => ['category' => 'visits',      'icon' => 'x'],
    'event_completed'      => ['category' => 'visits',      'icon' => 'check'],
    'quick_service'        => ['category' => 'maintenance', 'icon' => 'wrench'],
    'maintenance_due'      => ['category' => 'maintenance', 'icon' => 'warning'],
    'maintenance_due_owner'=> ['category' => 'maintenance', 'icon' => 'info-circle'],
    'operator_assigned'    => ['category' => 'assignment',  'icon' => 'check'],
    'assignment_request'   => ['category' => 'assignment',  'icon' => 'mail'],
    'assignment_approved'  => ['category' => 'assignment',  'icon' => 'check'],
    'assignment_rejected'  => ['category' => 'assignment',  'icon' => 'x'],
    'revision_approved'    => ['category' => 'moderation',  'icon' => 'check'],
    'revision_rejected'    => ['category' => 'moderation',  'icon' => 'x'],
];

/**
 * true, если пользователь не отключал уведомления этой категории —
 * отсутствие строки в notification_preferences значит "включено" (значение
 * по умолчанию), поэтому явно выключать нужно только то, что не нужно.
 */
function notify_category_enabled(PDO $pdo, $userId, $category) {
    $stmt = $pdo->prepare("SELECT enabled FROM notification_preferences WHERE user_id = ? AND category = ?");
    $stmt->execute([$userId, $category]);
    $val = $stmt->fetchColumn();
    return $val === false ? true : (bool)$val;
}

/**
 * Единая точка создания уведомления — раньше INSERT INTO notifications был
 * скопипащен в трёх файлах напрямую. Молча ничего не делает (не бросает и не
 * пишет), если пользователь отключил уведомления этой категории — вызывающему
 * коду не нужно знать о настройках, чтобы решить, создавать запись или нет.
 *
 * @param array|null $data Структурированные метаданные (например
 *        ['application_id' => 5]) — используются, чтобы позже можно было
 *        точечно погасить связанные уведомления (см. notify_mark_link_read()).
 */
function notify(PDO $pdo, $userId, $type, $message, $link = null, $data = null) {
    if (!isset(NOTIFICATION_META[$type])) {
        throw new InvalidArgumentException("Unknown notification type: $type");
    }
    $category = NOTIFICATION_META[$type]['category'];
    if (!notify_category_enabled($pdo, $userId, $category)) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, category, message, link, data)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $type,
        $category,
        $message,
        $link,
        $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/**
 * Гасит (read_at = NOW()) непрочитанные уведомления пользователя с заданной
 * ссылкой — используется, когда пользователь открывает конкретный чат/заявку
 * напрямую (не через сам список уведомлений), чтобы бейдж не копился за то,
 * что человек и так только что увидел на странице.
 */
function notify_mark_link_read(PDO $pdo, $userId, $link) {
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND link = ? AND read_at IS NULL")
        ->execute([$userId, $link]);
}