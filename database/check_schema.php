<?php
/**
 * Безопасный диагностический скрипт для регистрации, которая падает с
 * "Произошла ошибка. Попробуйте ещё раз позже.".
 *
 * НЕ показывает никаких личных данных пользователей — только структуру
 * базы (какие таблицы/колонки есть) и, если что-то не так, ТОЧНЫЙ текст
 * ошибки PDO. Ничего не сохраняет: тестовая вставка делается внутри
 * транзакции с ROLLBACK в конце.
 *
 * Запуск:
 *   php database/check_schema.php
 * либо открыть в браузере (если PHP настроен как веб-сервер), но лучше
 * через SSH/консоль хостинга — и обязательно удалить файл после проверки,
 * он открыт без авторизации.
 */

require_once __DIR__ . '/../public_html/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    die("Не удалось подключиться к базе: " . $e->getMessage() . "\n");
}

echo "=== Проверка структуры базы данных (" . DB_NAME . ") ===\n\n";

function tableExists(PDO $pdo, $table) {
    return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
}

function columnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

$checks = [
    ['table' => 'users', 'column' => 'privacy_consent_at', 'migration' => '2026_09_17_add_privacy_consent_to_users.sql'],
    ['table' => 'users', 'column' => 'failed_login_attempts', 'migration' => '2026_09_11_add_login_security_fields_to_users.sql'],
    ['table' => 'users', 'column' => 'reset_token', 'migration' => '2026_09_11_add_login_security_fields_to_users.sql'],
    ['table' => 'users', 'column' => 'verify_token', 'migration' => '2026_09_11_add_verification_2fa_ban_to_users.sql'],
    ['table' => 'users', 'column' => 'two_factor_enabled', 'migration' => '2026_09_11_add_verification_2fa_ban_to_users.sql'],
    ['table' => 'users', 'column' => 'is_banned', 'migration' => '2026_09_11_add_verification_2fa_ban_to_users.sql'],
    ['table' => 'applications', 'column' => 'assignment_requested', 'migration' => '2026_09_08_add_assignment_requested_to_applications.sql'],
    ['table' => 'service_photos', 'column' => 'uploaded_by', 'migration' => '2026_09_15_add_uploaded_by_to_service_photos.sql'],
    ['table' => 'rate_limits', 'column' => null, 'migration' => '2026_09_15_add_rate_limits_table.sql'],
    ['table' => 'subscriptions', 'column' => 'price_paid', 'migration' => '2026_09_19_rework_subscriptions_plans.sql'],
    ['table' => 'credit_purchases', 'column' => null, 'migration' => '2026_09_20_credit_unlock_system.sql'],
    ['table' => 'location_unlocks', 'column' => null, 'migration' => '2026_09_20_credit_unlock_system.sql'],
    ['table' => 'service_orders', 'column' => null, 'migration' => '2026_09_20_credit_unlock_system.sql'],
];

$missing = [];

foreach ($checks as $check) {
    $table = $check['table'];
    if (!tableExists($pdo, $table)) {
        echo "[ОТСУТСТВУЕТ] таблица `$table` (нужна миграция {$check['migration']})\n";
        $missing[] = $check['migration'];
        continue;
    }
    if ($check['column'] !== null && !columnExists($pdo, $table, $check['column'])) {
        echo "[ОТСУТСТВУЕТ] `$table`.`{$check['column']}` (нужна миграция {$check['migration']})\n";
        $missing[] = $check['migration'];
        continue;
    }
    echo "[OK] $table" . ($check['column'] ? ".{$check['column']}" : '') . "\n";
}

// Отдельно проверяем enum applications.status и subscriptions.plan — ALTER
// с MODIFY не ловится простой проверкой "колонка есть".
$statusCol = $pdo->query("SHOW COLUMNS FROM `applications` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
if ($statusCol && strpos($statusCol['Type'], 'approved') === false) {
    echo "[ОТСУТСТВУЕТ] applications.status не содержит 'approved'/'rejected' (нужна миграция 2026_09_08_add_approved_rejected_to_applications_status.sql)\n";
    $missing[] = '2026_09_08_add_approved_rejected_to_applications_status.sql';
} else {
    echo "[OK] applications.status enum\n";
}

if (tableExists($pdo, 'subscriptions')) {
    $planCol = $pdo->query("SHOW COLUMNS FROM `subscriptions` LIKE 'plan'")->fetch(PDO::FETCH_ASSOC);
    if ($planCol && strpos($planCol['Type'], 'operator_monthly') === false) {
        echo "[ОТСУТСТВУЕТ] subscriptions.plan — старый enum, нужна миграция 2026_09_20_credit_unlock_system.sql\n";
        $missing[] = '2026_09_20_credit_unlock_system.sql';
    } else {
        echo "[OK] subscriptions.plan enum\n";
    }
}

echo "\n=== Пробная регистрация (внутри транзакции, откатывается — ничего не сохранится) ===\n\n";

$pdo->beginTransaction();
$registrationError = null;
try {
    $testEmail = 'schema_check_' . bin2hex(random_bytes(4)) . '@example.invalid';
    $hashed = password_hash('TestPass123!', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, full_name, phone, role, privacy_consent_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$testEmail, $hashed, 'Schema Check', '+70000000000', 'operator']);
    $userId = $pdo->lastInsertId();

    rr_grant_credits($pdo, $userId, 'free_grant', 1);

    echo "[OK] Полный сценарий регистрации оператора выполнился без ошибок.\n";
    echo "Если сайт всё равно показывает ошибку — проблема не в структуре базы\n";
    echo "(смотрите PHP error_log хостинга на точный текст).\n";
} catch (Throwable $e) {
    $registrationError = $e->getMessage();
    echo "[ОШИБКА] Именно эта ошибка сейчас ломает регистрацию:\n\n";
    echo "    " . $registrationError . "\n\n";
    echo "Отправьте мне текст выше (он не содержит личных данных) — по нему я скажу точную причину.\n";
} finally {
    $pdo->rollBack();
}

echo "\n=== Итог ===\n";
if ($missing) {
    echo "Не хватает миграций: " . implode(', ', array_unique($missing)) . "\n";
    echo "Выполните: php database/migrate.php\n";
} elseif ($registrationError) {
    echo "Структура таблиц по отдельным проверкам выглядит нормально, но пробная\n";
    echo "регистрация всё равно упала с ошибкой выше — пришлите её мне.\n";
} else {
    echo "Структура базы в порядке, пробная регистрация прошла успешно.\n";
    echo "Если реальная регистрация на сайте всё равно ошибается — проверьте PHP\n";
    echo "error_log хостинга на точный текст в момент ошибки.\n";
}
