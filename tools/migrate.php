<?php
/**
 * Создать или обновить базу данных.
 *
 *     php tools/migrate.php
 *
 * Если базы ещё нет — создаёт её. Применяет новые файлы из
 * database/migrations по порядку (уже применённые помнит в таблице
 * schema_migrations) и в пустую базу кладёт начальные данные из
 * database/seed.php. Запускать после каждого обновления кода — безопасно.
 */

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$c = db_config();
try {
    db();
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '[1049]') === false) {
        fwrite(STDERR, "Не удалось подключиться к базе ({$c['host']}): " . $e->getMessage() . "\n");
        fwrite(STDERR, "В Open Server включите модуль MySQL-8.0 (osp on MySQL-8.0), на хостинге — проверьте config/secrets.php.\n");
        exit(1);
    }
    // 1049 — базы ещё нет: создаём.
    $server = db_connect(false);
    $server->exec('CREATE DATABASE `' . str_replace('`', '``', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "[создано] база данных {$c['name']}\n";
}

q('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = q('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(HOTEL_ROOT . '/database/migrations/*.sql');
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "==> $name\n";
    // Выражения разделены «;» в конце строки; комментарии «--» пропускаем.
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            db()->exec($statement);
        }
    }
    q('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)', [$name, now()]);
}

// Начальные данные — только в пустую базу.
if ((int) q_val('SELECT COUNT(*) FROM room_types') === 0) {
    $seed = require HOTEL_ROOT . '/database/seed.php';
    tx(function () use ($seed) {
        $sort = 0;
        foreach ($seed['room_types'] as $t) {
            $t['sort'] = ($sort += 10);
            $typeId = q_insert('room_types', $t);
            foreach ($seed['rooms'][$t['slug']] ?? [] as $i => $roomName) {
                q_insert('rooms', ['room_type_id' => $typeId, 'name' => $roomName, 'sort' => $i, 'ical_token' => bin2hex(random_bytes(16))]);
            }
        }
        foreach ($seed['stay_discounts'] as $d) {
            q_insert('stay_discounts', $d);
        }
        foreach ($seed['settings'] as $name => $value) {
            set_setting($name, $value);
        }
    });
    echo "[заполнено] типы номеров, комнаты и скидки — поправьте их в админке (Номера, Цены)\n";
}

echo "Готово. База {$c['name']} в актуальном состоянии.\n";
if (!q_val('SELECT id FROM staff LIMIT 1')) {
    echo "Сотрудников ещё нет. Создайте владельца: откройте /admin/ в браузере или выполните\n  php tools/admin.php add ВАША_ПОЧТА \"Ваше имя\"\n";
}
