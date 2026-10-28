<?php
/**
 * Фоновые задачи: обновление календарей площадок, напоминания гостям
 * накануне заезда, удаление устаревших данных.
 *
 *     php tools/cron.php
 *
 * Добавьте в планировщик хостинга (cron) каждые 10–15 минут:
 *
 *     0,15,30,45 * * * * php /путь/к/сайту/tools/cron.php > /dev/null 2>&1
 *
 * Без планировщика задачи тоже выполняются — сами, пока кто-то работает
 * в админке, но тогда напоминания и календари обновляются реже.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if (!db_ready()) {
    fwrite(STDERR, "Нет подключения к базе. Проверьте config/secrets.php и выполните php tools/migrate.php\n");
    exit(1);
}

foreach (cron_run() as $line) {
    echo date('Y-m-d H:i') . '  ' . $line . "\n";
}
