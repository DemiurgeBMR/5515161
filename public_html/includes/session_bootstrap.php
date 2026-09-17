<?php
/**
 * Единая точка настройки cookie-сессии и базовых security-заголовков —
 * подключается и вызывается ПЕРВОЙ строкой в каждой точке входа, до
 * config.php и до любого вывода. Cookie-флаги (httponly/samesite/secure)
 * можно выставить только до session_start(), поэтому этот файл намеренно
 * ничего не требует и не зависит от config.php.
 */

// На локалке сайт открывается по http — cookie с secure=true браузер просто
// не сохранил бы, и вход был бы всегда "неудачным". true только когда реально
// видим https (в т.ч. за прокси через X-Forwarded-Proto).
function rr_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Редирект на https — выключен по умолчанию. Без выпущенного SSL-сертификата
 * принудительный редирект на https оборвал бы соединение любому посетителю,
 * поэтому переменная окружения FORCE_HTTPS должна быть выставлена явно —
 * сделать это нужно сразу, как на сервере появится домен с сертификатом.
 */
function rr_enforce_https() {
    if (php_sapi_name() === 'cli') return;
    if (!filter_var(getenv('FORCE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN)) return;
    if (rr_is_https()) return;

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

/**
 * Базовые security-заголовки — против кликджекинга, MIME-sniffing и части
 * XSS-сценариев. CSP разрешает 'unsafe-inline' для script-src/style-src: сайт
 * весь построен на инлайновых <script>/<style> без nonce-инфраструктуры,
 * полный запрет сломал бы большинство страниц. Даже так CSP всё равно не
 * даёт подгружать чужой код/картинки с посторонних доменов — в списке
 * разрешённых только то, что реально используется (Leaflet, FullCalendar,
 * jQuery, тайлы карты OpenStreetMap).
 */
function rr_send_security_headers() {
    if (php_sapi_name() === 'cli' || headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
        "img-src 'self' data: https://*.tile.openstreetmap.org",
        "font-src 'self' data: https://cdn.jsdelivr.net https://unpkg.com",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'self'",
    ]));

    // HSTS имеет смысл только вместе с принудительным https — иначе браузер
    // запомнит требование https для домена раньше, чем на сервере появится
    // сертификат, и заблокирует себе доступ к сайту.
    if (filter_var(getenv('FORCE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN) && rr_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function rr_session_start() {
    rr_enforce_https();
    rr_send_security_headers();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => rr_is_https(),
    ]);
}
