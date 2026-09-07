<?php
// ============================================
// КОНФИГУРАЦИЯ ПРОЕКТА RR (riveg-rent)
// ============================================

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
// true = показывать ошибки, false = скрывать (для продакшена)
define('DEBUG_MODE', true);

// Путь к файлу водяного знака (PNG с прозрачностью)
define('WATERMARK_PATH', __DIR__ . '/assets/images/watermark.png');

// Через сколько дней без обслуживания точка считается "требующей внимания"
define('SERVICE_DUE_DAYS', 14);

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
    error_reporting(0);
    ini_set('display_errors', 0);
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
        die('Ошибка подключения к базе данных: ' . $e->getMessage());
    }
}

// --- ФУНКЦИЯ ДЛЯ ПРОВЕРКИ ПОДКЛЮЧЕНИЯ ---
function testDbConnection() {
    try {
        $pdo = getDbConnection();
        return true;
    } catch (Exception $e) {
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
 * @return array{lat: float, lng: float}|null
 */
function geocodeAddress($address, $city) {
    $query = trim(trim($city) . ', ' . trim($address), ', ');
    if ($query === '') return null;

    $cacheKey = 'geo_' . md5(mb_strtolower($query, 'UTF-8'));
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

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $query,
        'format' => 'json',
        'limit'  => 1,
    ]);

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

    $result = [
        'lat' => (float) $data[0]['lat'],
        'lng' => (float) $data[0]['lon'],
    ];
    setCache($cacheKey, $result);
    return $result;
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