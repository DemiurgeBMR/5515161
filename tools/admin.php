<?php
/**
 * Сотрудники из командной строки — на случай, если забыли пароль или
 * нужно создать первого владельца без браузера.
 *
 *     php tools/admin.php list
 *     php tools/admin.php add почта@пример.ру "Имя" [owner|admin]
 *     php tools/admin.php password почта@пример.ру
 *
 * Новый пароль печатается на экране один раз — после входа смените его.
 */

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$cmd = $argv[1] ?? '';
try {
    if ($cmd === 'list') {
        foreach (q_all('SELECT * FROM staff ORDER BY id') as $s) {
            echo str_pad($s['email'], 32) . ' ' . str_pad(STAFF_ROLES[$s['role']] ?? $s['role'], 16) . ($s['is_active'] ? '' : ' (отключён)') . "\n";
        }
        exit(0);
    }
    if ($cmd === 'add' && isset($argv[2], $argv[3])) {
        $hasOwner = (bool) q_val("SELECT id FROM staff WHERE role = 'owner' LIMIT 1");
        $r = staff_create($argv[2], $argv[3], $argv[4] ?? ($hasOwner ? 'admin' : 'owner'));
        echo "Создан: {$r['email']}\nВременный пароль: {$r['password']}\nВойдите в /admin/ — система попросит сменить пароль.\n";
        exit(0);
    }
    if ($cmd === 'password' && isset($argv[2])) {
        $id = q_val('SELECT id FROM staff WHERE email = ?', [mb_strtolower(trim($argv[2]))]);
        if (!$id) {
            fwrite(STDERR, "Сотрудника с такой почтой нет.\n");
            exit(1);
        }
        $password = temp_password();
        staff_set_password((int) $id, $password, true);
        q('UPDATE staff SET is_active = 1 WHERE id = ?', [$id]);
        audit('staff', (int) $id, 'password_reset', 'из командной строки', 'system');
        echo "Новый временный пароль: $password\n";
        exit(0);
    }
} catch (BookingError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Команды:\n  php tools/admin.php list\n  php tools/admin.php add почта \"Имя\" [owner|admin]\n  php tools/admin.php password почта\n";
exit(1);
