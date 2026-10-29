<?php
/**
 * Вебхук бота MAX: сюда MAX присылает сообщения боту и нажатия кнопок.
 * Регистрируется командой php tools/max.php webhook https://ваш-домен/api/max.php
 * (только https). Чужие запросы отсекаются по секрету из config/secrets.php
 * (max_webhook_secret) — MAX присылает его в заголовке X-Max-Bot-Api-Secret.
 */

require __DIR__ . '/../../src/bootstrap.php';

if (!is_post()) {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!db_ready() || !max_webhook_authorized($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? null)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
// Отвечаем сразу: MAX не должен ждать, пока бот отправит ответ.
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (is_array($update)) {
    try {
        max_handle_update($update);
        max_flush();
    } catch (Throwable $e) {
        error_log('hotel max webhook: ' . $e->getMessage());
    }
}
