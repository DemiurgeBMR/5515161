<?php
/**
 * Вебхук Telegram-бота: сюда Telegram присылает сообщения боту и нажатия
 * кнопок. Включается командой
 *     php tools/telegram.php webhook https://ваш-домен/api/telegram.php
 * (только https). Чужие запросы отсекаются по секрету из config/secrets.php
 * (telegram_webhook_secret) — Telegram присылает его в заголовке
 * X-Telegram-Bot-Api-Secret-Token.
 */

require __DIR__ . '/../../src/bootstrap.php';

if (!is_post()) {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!db_ready() || !tg_webhook_authorized($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input', false, null, 0, 1048576), true);
// Отвечаем сразу: Telegram не должен ждать, пока бот отправит ответ.
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (is_array($update)) {
    try {
        tg_handle_update($update);
        tg_flush();
    } catch (Throwable $e) {
        error_log('hotel telegram webhook: ' . $e->getMessage());
    }
}
