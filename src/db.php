<?php
/**
 * Подключение к базе данных и мелкие помощники для запросов.
 *
 * Все запросы — только через подготовленные выражения (q, q_all, q_one,
 * q_val): значения не склеиваются в SQL-строку, поэтому SQL-инъекций нет.
 */

function db_config(): array
{
    $s = hotel_secrets();
    // Переменные окружения HOTEL_DB_* — для тестов и CI, на сайте не нужны.
    return [
        'host' => getenv('HOTEL_DB_HOST') ?: $s['db_host'],
        'port' => (int) (getenv('HOTEL_DB_PORT') ?: $s['db_port']),
        'name' => getenv('HOTEL_DB_NAME') ?: $s['db_name'],
        'user' => getenv('HOTEL_DB_USER') ?: $s['db_user'],
        'pass' => getenv('HOTEL_DB_PASS') !== false ? (string) getenv('HOTEL_DB_PASS') : $s['db_pass'],
    ];
}

function db_connect(bool $withDatabase = true): PDO
{
    $c = db_config();
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ($withDatabase ? ';dbname=' . $c['name'] : '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    // Время в базе — то же, что у гостиницы (Симферополь, UTC+3, без перехода на летнее).
    $pdo->exec("SET time_zone = '+03:00'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect();
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Первое поле первой строки или null. */
function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function q_insert(string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($row));
    return (int) db()->lastInsertId();
}

function q_update(string $table, int $id, array $row): void
{
    if (!$row) {
        return;
    }
    $set = implode(', ', array_map(fn ($c) => '`' . $c . '` = ?', array_keys($row)));
    q('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ?', array_merge(array_values($row), [$id]));
}

/**
 * Выполнить функцию в транзакции. READ COMMITTED — чтобы после блокировки
 * строк (SELECT … FOR UPDATE) обычные запросы видели брони, которые только
 * что сохранила соседняя транзакция. При взаимной блокировке повторяет
 * попытку — такое бывает, когда двое бронируют в одну секунду.
 */
function tx(callable $fn)
{
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $deadlock = $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
            if ($deadlock && $attempt < 3) {
                usleep(100000 * $attempt);
                continue;
            }
            throw $e;
        }
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// -------------------------------------------------------------------
// Настройки, которые меняются из админки
// -------------------------------------------------------------------

function setting(string $name, ?string $default = null): ?string
{
    $v = q_val('SELECT value FROM settings WHERE name = ?', [$name]);
    return $v === null ? $default : (string) $v;
}

function set_setting(string $name, ?string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

/** Отметка «данные изменились» — по ней открытая шахматка понимает, что пора обновиться. */
function touch_data_version(): void
{
    set_setting('data_version', (string) microtime(true));
}

// -------------------------------------------------------------------
// Журнал действий
// -------------------------------------------------------------------

function audit(string $entity, ?int $entityId, string $action, ?string $details = null, ?string $actor = null): void
{
    $staff = function_exists('current_staff') ? current_staff() : null;
    q_insert('audit_log', [
        'created_at' => now(),
        'actor'      => $actor ?? ($staff ? 'staff' : 'guest'),
        'staff_id'   => $staff['id'] ?? null,
        'entity'     => $entity,
        'entity_id'  => $entityId,
        'action'     => $action,
        'details'    => $details,
    ]);
}

/** Есть ли подключение и таблицы — для страницы «сайт настраивается». */
function db_ready(): bool
{
    try {
        db();
        return (bool) q_val("SHOW TABLES LIKE 'bookings'");
    } catch (Throwable $e) {
        error_log('hotel: база недоступна: ' . $e->getMessage());
        return false;
    }
}
