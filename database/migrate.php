<?php
/**
 * Безопасный раннер миграций для существующей (боевой) базы данных.
 *
 * В отличие от tests/smoke.sh (который перед прогоном миграций удаляет базу
 * целиком — только для CI), этот скрипт ничего не удаляет и не трогает уже
 * применённые изменения. Он ведёт таблицу `_schema_migrations` со списком
 * применённых файлов и прогоняет по порядку только те .sql из
 * database/migrations/, которых там ещё нет.
 *
 * Запуск (из корня проекта, там же где public_html/):
 *   php database/migrate.php
 *
 * Если переменные окружения DB_HOST/DB_NAME/DB_USER/DB_PASS не заданы —
 * используются те же значения по умолчанию, что и в public_html/config.php.
 */

$dbHost = getenv('DB_HOST') ?: 'MySQL-8.0';
$dbName = getenv('DB_NAME') ?: 'riveg_rent';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // 1049 «Unknown database» — первый запуск на новом компьютере (Open Server),
    // базы ещё нет. Создаём пустую и подключаемся заново. На сервере база уже
    // создана docker-compose, сюда не попадаем — права на CREATE DATABASE
    // там и не нужны.
    if (strpos($e->getMessage(), '[1049]') === false) {
        fwrite(STDERR, "Не удалось подключиться к базе данных ($dbHost/$dbName): " . $e->getMessage() . "\n");
        fwrite(STDERR, "Проверьте переменные окружения DB_HOST, DB_NAME, DB_USER, DB_PASS.\n");
        fwrite(STDERR, "В Open Server: включён ли модуль MySQL-8.0? Подробнее — docs/OSPANEL.md.\n");
        exit(1);
    }
    try {
        $server = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass);
        $server->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $server->exec('CREATE DATABASE `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        echo "[создано] база данных $dbName\n";
        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        fwrite(STDERR, "Базы $dbName нет, и создать её не получилось: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `_schema_migrations` (
        `filename` VARCHAR(255) NOT NULL PRIMARY KEY,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$migrationsDir = __DIR__ . '/migrations';
$baselineFile = '0000_00_00_initial_schema.sql';

$stmt = $pdo->query("SELECT filename FROM `_schema_migrations`");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Базовый дамп 0000_00_00 описывает схему уже существующей рабочей базы —
// это не миграция "с нуля". Если в базе уже есть таблица users, считаем
// его примененным автоматически и не прогоняем заново.
if (!in_array($baselineFile, $applied, true)) {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if ($tableExists) {
        $pdo->prepare("INSERT INTO `_schema_migrations` (filename) VALUES (?)")->execute([$baselineFile]);
        $applied[] = $baselineFile;
        echo "[пропущено] $baselineFile — таблицы уже существуют, отмечено как применённое.\n";
    }
}

$files = glob($migrationsDir . '/*.sql');
sort($files);

// Коды ошибок MySQL, означающие "уже применено" — таблица/колонка/индекс
// уже существуют, либо колонки для DROP уже нет. Пропускаем такой файл
// вместо того чтобы останавливать весь прогон.
$alreadyAppliedErrorCodes = [1050, 1060, 1061, 1091];

$hadError = false;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "==> Миграция: $name\n";
    $sql = file_get_contents($file);

    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO `_schema_migrations` (filename) VALUES (?)")->execute([$name]);
        echo "    OK\n";
    } catch (PDOException $e) {
        $errorCode = (int) $e->errorInfo[1];
        // Базовую схему прогоняем только в пустую базу (см. выше), поэтому
        // «таблица уже есть» здесь значит, что прошлый прогон оборвался на
        // середине. Считать её применённой нельзя — половины таблиц не будет.
        if ($name === $baselineFile) {
            fwrite(STDERR, "    ОШИБКА в $name: " . $e->getMessage() . "\n");
            fwrite(STDERR, "Базовая схема создалась не полностью. Удалите базу $dbName и запустите скрипт снова —\n");
            fwrite(STDERR, "он создаст её заново. Нужен MySQL 8 (в Open Server — модуль MySQL-8.0), MariaDB не подойдёт.\n");
            $hadError = true;
            break;
        }
        if (in_array($errorCode, $alreadyAppliedErrorCodes, true)) {
            echo "    [пропущено] похоже, уже применено ранее (" . $e->getMessage() . ")\n";
            $pdo->prepare("INSERT INTO `_schema_migrations` (filename) VALUES (?)")->execute([$name]);
            continue;
        }

        fwrite(STDERR, "    ОШИБКА в $name: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Прогон остановлен. Исправьте файл миграции или примените его вручную, затем запустите скрипт снова.\n");
        $hadError = true;
        break;
    }
}

if ($hadError) {
    exit(1);
}

echo "Готово. База данных обновлена до последней версии.\n";
