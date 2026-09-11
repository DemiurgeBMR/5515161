<?php
/**
 * Единая точка настройки cookie-сессии — подключается и вызывается ПЕРВОЙ
 * строкой в каждой точке входа, до config.php и до любого вывода. Cookie-флаги
 * (httponly/samesite/secure) можно выставить только до session_start(), поэтому
 * этот файл намеренно ничего не требует и не зависит от config.php.
 */
function rr_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // На локалке сайт открывается по http — cookie с secure=true браузер
    // просто не сохранил бы, и вход был бы всегда "неудачным". Включаем
    // secure только когда реально видим https (в т.ч. за прокси).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => $isHttps,
    ]);
}
