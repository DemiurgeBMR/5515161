<?php
/**
 * Служебная команда для сервера: проверить базу и назначить роль пользователю
 * (в первую очередь — сделать первого администратора, без которого не открыть
 * /admin). Запускается только из командной строки, в браузере недоступна.
 *
 * В контейнере (кавычки не нужны):
 *   docker compose exec app php database/admin_tool.php status
 *   docker compose exec app php database/admin_tool.php set-role you@example.com admin
 *
 * Роли: admin, owner, operator.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'MySQL-8.0') . ';dbname=' . (getenv('DB_NAME') ?: 'riveg_rent') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$command = $argv[1] ?? '';

if ($command === 'status') {
    try {
        $last = $pdo->query("SELECT filename FROM _schema_migrations ORDER BY applied_at DESC, filename DESC LIMIT 1")->fetchColumn();
    } catch (PDOException $e) {
        $last = 'таблица миграций ещё не создана';
    }
    echo "Последняя применённая миграция: $last\n";
    foreach (['payments', 'price_overrides'] as $table) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        echo "Таблица $table: " . ($exists ? 'есть' : 'НЕТ') . "\n";
    }
    echo "Пользователей всего: " . $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() . "\n";
    $admins = $pdo->query("SELECT id, email FROM users WHERE role = 'admin'")->fetchAll();
    if (!$admins) {
        echo "Администраторов нет. Назначить: php database/admin_tool.php set-role EMAIL admin\n";
    }
    foreach ($admins as $a) {
        echo "Администратор: #{$a['id']} {$a['email']}\n";
    }
    exit(0);
}

if ($command === 'set-role') {
    $email = trim($argv[2] ?? '');
    $role = $argv[3] ?? '';
    if ($email === '' || !in_array($role, ['admin', 'owner', 'operator'], true)) {
        fwrite(STDERR, "Использование: php database/admin_tool.php set-role EMAIL admin|owner|operator\n");
        exit(1);
    }
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "Пользователь с email $email не найден.\n");
        exit(1);
    }
    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $user['id']]);
    echo "Готово: $email теперь «{$role}» (раньше: {$user['role']}). Выйдите с сайта и войдите заново.\n";
    exit(0);
}

fwrite(STDERR, "Команды: status | set-role EMAIL admin|owner|operator\n");
exit(1);
