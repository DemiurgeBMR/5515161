<?php
/**
 * Проверка установки на своём компьютере (Open Server): версия PHP,
 * расширения, база данных, миграции, справочник городов, администратор,
 * папки для загрузок. Ничего не меняет — только смотрит и подсказывает,
 * что сделать. Запускается только из командной строки.
 *
 *   php database/doctor.php
 *
 * Пошаговая установка с нуля — docs/OSPANEL.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$problems = 0;

function ok(string $msg): void
{
    echo "  [ок] $msg\n";
}

function bad(string $msg, string $hint): void
{
    global $problems;
    $problems++;
    echo "  [!]  $msg\n       → $hint\n";
}

function note(string $msg, string $hint): void
{
    echo "  [i]  $msg\n       → $hint\n";
}

// ------------------------------------------------------------------
echo "PHP\n";
// ------------------------------------------------------------------

$phpModule = 'PHP-' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (version_compare(PHP_VERSION, '8.2', '>=')) {
    ok('версия ' . PHP_VERSION);
} else {
    bad('версия ' . PHP_VERSION . ' — нужна 8.2 или новее',
        'в .osp/project.ini укажите php_engine = PHP-8.2 или новее и перезапустите Open Server');
}

$ini = php_ini_loaded_file() ?: 'не найден';
$extensions = [
    'pdo_mysql' => 'подключение к базе',
    'mbstring'  => 'работа с русским текстом',
    'gd'        => 'обработка фотографий',
    'curl'      => 'оплата ЮKassa и запросы к внешним сервисам',
    'openssl'   => 'отправка почты и оплата',
    'fileinfo'  => 'проверка загружаемых файлов',
];
foreach ($extensions as $ext => $why) {
    if (extension_loaded($ext)) {
        ok("расширение $ext");
    } else {
        bad("нет расширения $ext ($why)",
            "в Open Server откройте config\\$phpModule\\default\\templates\\php.ini, уберите «;» перед extension=$ext "
            . "и перезапустите модуль $phpModule (сейчас загружен: $ini)");
    }
}

// ------------------------------------------------------------------
echo "\nБаза данных\n";
// ------------------------------------------------------------------

$dbHost = getenv('DB_HOST') ?: 'MySQL-8.0';
$dbName = getenv('DB_NAME') ?: 'riveg_rent';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = null;
if (extension_loaded('pdo_mysql')) {
    try {
        $server = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0', '<')) {
            bad("сервер $dbHost: $version — нужен MySQL 8",
                'включите модуль MySQL-8.0: схема базы использует кодировку utf8mb4_0900_ai_ci, которой нет в MariaDB и MySQL 5.7');
        } else {
            ok("сервер $dbHost, MySQL $version");
        }

        $exists = $server->query('SHOW DATABASES LIKE ' . $server->quote($dbName))->fetchColumn();
        if ($exists) {
            ok("база $dbName есть");
            $pdo = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            bad("базы $dbName нет", 'php database/migrate.php — создаст базу и все таблицы');
        }
    } catch (PDOException $e) {
        bad("не удалось подключиться к $dbHost: " . $e->getMessage(),
            'включите модуль MySQL-8.0 в Open Server (в консоли Open Server: osp on MySQL-8.0)');
    }
} else {
    bad('проверка базы пропущена', 'сначала включите расширение pdo_mysql (см. выше)');
}

if ($pdo) {
    $hasTable = fn (string $t): bool => (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetchColumn();

    $files = array_map('basename', glob(__DIR__ . '/migrations/*.sql') ?: []);
    $applied = $hasTable('_schema_migrations')
        ? $pdo->query('SELECT filename FROM _schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $pending = array_diff($files, $applied);
    if (!$hasTable('users')) {
        bad('таблиц ещё нет', 'php database/migrate.php');
    } elseif ($pending) {
        bad('не применено миграций: ' . count($pending), 'php database/migrate.php');
    } else {
        ok('все миграции применены (' . count($files) . ')');
    }

    if ($hasTable('users')) {
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        if ($admins) {
            ok("пользователей: $users, администратор: " . implode(', ', $admins));
        } else {
            note("пользователей: $users, администратора нет — без него не открыть /admin",
                'зарегистрируйтесь на сайте и выполните: php database/admin_tool.php set-role ВАШ_EMAIL admin');
        }
    }

    if ($hasTable('_cities')) {
        $cities = (int) $pdo->query('SELECT COUNT(*) FROM _cities')->fetchColumn();
        if ($cities > 0) {
            ok('справочник городов: ' . number_format($cities, 0, ',', ' ') . ' записей');
        } else {
            note('справочник городов пуст — при добавлении места не будет выбора города',
                'загрузите города, см. docs/OSPANEL.md, раздел «Справочник городов»');
        }
    }
}

// ------------------------------------------------------------------
echo "\nПапки для файлов\n";
// ------------------------------------------------------------------

foreach (['public_html/uploads', 'private_uploads', 'cache'] as $dir) {
    $path = $root . '/' . $dir;
    $check = is_dir($path) ? $path : dirname($path);
    if (is_writable($check)) {
        ok($dir . (is_dir($path) ? '' : ' (создастся при первой загрузке)'));
    } else {
        bad("$dir: нет прав на запись", "разрешите запись в папку $check");
    }
}

// ------------------------------------------------------------------
echo "\n";
if ($problems === 0) {
    echo "Всё готово. Откройте сайт: http://riveg-rent/\n";
    if (is_dir($root . '/hotel')) {
        echo "Сайт гостиницы: http://hotel/ (проверка его настроек: php hotel/tools/check.php)\n";
    }
    exit(0);
}
echo "Нужно исправить: $problems. Если непонятно, что делать, пришлите этот текст в чат с Claude.\n";
exit(1);
