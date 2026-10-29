<?php
/**
 * Telegram-бот из командной строки.
 *
 *     php tools/telegram.php check               — проверить токен и связь, узнать имя бота
 *     php tools/telegram.php webhook https://ваш-домен/api/telegram.php
 *                                                — на хостинге: Telegram сам присылает события
 *     php tools/telegram.php unhook              — выключить вебхук (вернуться к опросу)
 *     php tools/telegram.php poll                — на своём компьютере: слушать бота,
 *                                                  пока окно открыто (Ctrl+C — стоп)
 *     php tools/telegram.php test почта@сотрудника — отправить сотруднику пробное сообщение
 *
 * Токен — в config/secrets.php (telegram_bot_token), для вебхука там же
 * telegram_webhook_secret. Как получить токен — в README, «Бот в Telegram».
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$cmd = $argv[1] ?? '';
$say = function (string $s): void {
    echo $s . "\n";
};

if (!db_ready()) {
    fwrite(STDERR, "Нет подключения к базе. Проверьте config/secrets.php и выполните php tools/migrate.php\n");
    exit(1);
}
if (tg_token() === '' && tg_transport() !== 'log') {
    fwrite(STDERR, "В config/secrets.php не задан telegram_bot_token. Как получить токен — в README, раздел «Бот в Telegram».\n");
    exit(1);
}

switch ($cmd) {
    case 'check':
        $bot = tg_bot_info(true);
        if (!$bot) {
            fwrite(STDERR, 'Telegram не ответил: ' . (setting('tg_last_error') ?: 'нет ответа') . "\n");
            exit(1);
        }
        $say('Бот работает: ' . $bot['name'] . ' (https://t.me/' . $bot['username'] . ')');
        [, $info] = tg_api('getWebhookInfo');
        $hook = is_array($info) ? (string) ($info['url'] ?? '') : '';
        $say($hook !== '' ? 'События приходят на вебхук: ' . $hook . (!empty($info['last_error_message']) ? ' (последняя ошибка: ' . $info['last_error_message'] . ')' : '')
            : 'Вебхук не включён — события забирают фоновые задачи или команда poll.');
        $say(tg_personal() ? 'Имена и телефоны гостей в сообщениях: ВКЛЮЧЕНЫ (нужно уведомление в Роскомнадзор о трансграничной передаче).' : 'Имена и телефоны гостей в сообщения не попадают.');
        $linked = q_all('SELECT name, tg_notify FROM staff WHERE tg_user_id IS NOT NULL AND is_active = 1 ORDER BY name');
        $say('Подключили Telegram: ' . ($linked ? implode(', ', array_map(fn ($s) => $s['name'] . ($s['tg_notify'] ? '' : ' (уведомления выключены)'), $linked)) : 'пока никто — Профиль → Уведомления в Telegram'));
        exit(0);

    case 'webhook':
        $url = $argv[2] ?? '';
        if (!preg_match('#^https://[^\s]+$#', $url)) {
            fwrite(STDERR, "Нужен адрес на https, например: php tools/telegram.php webhook https://ваш-домен.ru/api/telegram.php\n");
            exit(1);
        }
        $secret = (string) hotel_secrets()['telegram_webhook_secret'];
        if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            fwrite(STDERR, "Задайте в config/secrets.php telegram_webhook_secret — от 16 символов: латиница, цифры, _ и -. По нему сайт отличает Telegram от чужих запросов.\n");
            exit(1);
        }
        [$ok, $res] = tg_api('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => TG_UPDATE_TYPES]);
        if (!$ok) {
            fwrite(STDERR, 'Telegram не принял вебхук: ' . $res . "\n");
            exit(1);
        }
        set_setting('tg_webhook_url', $url);
        $say('Готово: Telegram будет присылать события на ' . $url);
        exit(0);

    case 'unhook':
        tg_api('deleteWebhook');
        set_setting('tg_webhook_url', null);
        $say('Вебхук выключен. Теперь события забирают фоновые задачи или команда poll.');
        exit(0);

    case 'poll':
        // С включённым вебхуком Telegram не отдаёт события опросом.
        [, $info] = tg_api('getWebhookInfo');
        if (is_array($info) && !empty($info['url'])) {
            fwrite(STDERR, 'Включён вебхук (' . $info['url'] . "). Опрос с ним не работает — сначала php tools/telegram.php unhook\n");
            exit(1);
        }
        $bot = tg_bot_info(true);
        $say('Слушаю бота' . ($bot ? ' @' . $bot['username'] : '') . '. Напишите ему в Telegram. Остановить — Ctrl+C.');
        while (true) {
            $n = tg_poll(25);
            if ($n > 0) {
                $say(date('H:i:s') . '  событий: ' . $n);
            } elseif ($n < 0) {
                $say(date('H:i:s') . '  Telegram не ответил: ' . setting('tg_last_error') . ' — пробую снова через 10 секунд');
                sleep(10);
            }
            app_cache_clear(); // настройки и сотрудники могли измениться в админке
        }

    case 'test':
        $s = q_one('SELECT * FROM staff WHERE email = ?', [mb_strtolower(trim($argv[2] ?? ''))]);
        if (!$s || !$s['tg_chat_id']) {
            fwrite(STDERR, "Сотрудник не найден или ещё не подключил Telegram (Профиль → Уведомления в Telegram).\n");
            exit(1);
        }
        $ok = tg_send((int) $s['tg_chat_id'], 'Проверка связи: бот «' . e(mail_hotel_name()) . '» работает.');
        $say($ok ? 'Отправлено.' : 'Не отправилось: ' . setting('tg_last_error'));
        exit($ok ? 0 : 1);
}

$say("Команды:\n  php tools/telegram.php check\n  php tools/telegram.php webhook https://ваш-домен/api/telegram.php\n  php tools/telegram.php unhook\n  php tools/telegram.php poll\n  php tools/telegram.php test почта@сотрудника");
exit(1);
