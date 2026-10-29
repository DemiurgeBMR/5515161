<?php
/**
 * Бот MAX из командной строки.
 *
 *     php tools/max.php check               — проверить токен, узнать имя бота
 *     php tools/max.php webhook https://ваш-домен/api/max.php
 *                                           — на хостинге: MAX сам присылает события
 *     php tools/max.php unhook              — выключить вебхук (вернуться к опросу)
 *     php tools/max.php poll                — на своём компьютере: слушать бота,
 *                                             пока окно открыто (Ctrl+C — стоп)
 *     php tools/max.php test почта@сотрудника — отправить сотруднику пробное сообщение
 *
 * Токен — в config/secrets.php (max_bot_token), для вебхука там же
 * max_webhook_secret. Как получить токен — в README, раздел «Бот в MAX».
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
if (trim((string) hotel_secrets()['max_bot_token']) === '' && max_transport() !== 'log') {
    fwrite(STDERR, "В config/secrets.php не задан max_bot_token. Как получить токен — в README, раздел «Бот в MAX».\n");
    exit(1);
}

switch ($cmd) {
    case 'check':
        $bot = max_bot_info(true);
        if (!$bot) {
            fwrite(STDERR, 'MAX не принял токен: ' . (setting('max_last_error') ?: 'нет ответа') . "\n");
            exit(1);
        }
        $say('Бот работает: ' . $bot['name'] . ' (https://max.ru/' . $bot['username'] . ')');
        $say(setting('max_webhook_url') ? 'События приходят на вебхук: ' . setting('max_webhook_url') : 'Вебхук не включён — события забирают фоновые задачи или команда poll.');
        $linked = q_all('SELECT name, role, max_name, max_notify FROM staff WHERE max_user_id IS NOT NULL AND is_active = 1 ORDER BY name');
        $say('Подключили MAX: ' . ($linked ? implode(', ', array_map(fn ($s) => $s['name'] . ($s['max_notify'] ? '' : ' (уведомления выключены)'), $linked)) : 'пока никто — Профиль → Уведомления в MAX'));
        exit(0);

    case 'webhook':
        $url = $argv[2] ?? '';
        if (!preg_match('#^https://[^\s]+$#', $url)) {
            fwrite(STDERR, "Нужен адрес на https, например: php tools/max.php webhook https://ваш-домен.ru/api/max.php\n");
            exit(1);
        }
        $secret = (string) hotel_secrets()['max_webhook_secret'];
        if (strlen($secret) < 16) {
            fwrite(STDERR, "Задайте в config/secrets.php max_webhook_secret — любую строку от 16 символов (латиница и цифры). По ней сайт отличает MAX от чужих запросов.\n");
            exit(1);
        }
        [$code, $data] = max_api('POST', 'subscriptions', [], ['url' => $url, 'update_types' => MAX_UPDATE_TYPES, 'secret' => $secret]);
        if ($code !== 200 || empty($data['success'])) {
            fwrite(STDERR, 'MAX не принял вебхук: ' . ($data['message'] ?? $code) . "\n");
            exit(1);
        }
        set_setting('max_webhook_url', $url);
        $say('Готово: MAX будет присылать события на ' . $url);
        exit(0);

    case 'unhook':
        [, $data] = max_api('GET', 'subscriptions');
        foreach ($data['subscriptions'] ?? [] as $sub) {
            max_api('DELETE', 'subscriptions', ['url' => $sub['url']]);
            $say('Выключен вебхук ' . $sub['url']);
        }
        set_setting('max_webhook_url', null);
        $say('Теперь события забирают фоновые задачи или команда poll.');
        exit(0);

    case 'poll':
        if (setting('max_webhook_url')) {
            fwrite(STDERR, 'Включён вебхук (' . setting('max_webhook_url') . "). Опрос с ним не работает — сначала php tools/max.php unhook\n");
            exit(1);
        }
        $bot = max_bot_info(true);
        $say('Слушаю бота' . ($bot ? ' ' . $bot['name'] : '') . '. Напишите ему в MAX. Остановить — Ctrl+C.');
        while (true) {
            $n = max_poll(25);
            if ($n > 0) {
                $say(date('H:i:s') . '  событий: ' . $n);
            } elseif ($n < 0) {
                $say(date('H:i:s') . '  MAX не ответил: ' . setting('max_last_error') . ' — пробую снова через 10 секунд');
                sleep(10);
            }
            app_cache_clear(); // настройки и сотрудники могли измениться в админке
        }

    case 'test':
        $s = q_one('SELECT * FROM staff WHERE email = ?', [mb_strtolower(trim($argv[2] ?? ''))]);
        if (!$s || !$s['max_chat_id']) {
            fwrite(STDERR, "Сотрудник не найден или ещё не подключил MAX (Профиль → Уведомления в MAX).\n");
            exit(1);
        }
        $ok = max_send((int) $s['max_chat_id'], 'Проверка связи: бот «' . e(mail_hotel_name()) . '» работает.');
        $say($ok ? 'Отправлено.' : 'Не отправилось: ' . setting('max_last_error'));
        exit($ok ? 0 : 1);
}

$say("Команды:\n  php tools/max.php check\n  php tools/max.php webhook https://ваш-домен/api/max.php\n  php tools/max.php unhook\n  php tools/max.php poll\n  php tools/max.php test почта@сотрудника");
exit(1);
