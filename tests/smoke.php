<?php
/**
 * Smoke-тест для CI — не полноценный набор тестов (PHPUnit/composer в
 * проекте нет и не планируется, хостинг без шага сборки), а быстрая
 * проверка "не сломали ли явно что-то важное": ключевые страницы
 * открываются под нужной ролью с ожидаемым кодом ответа, а несколько
 * центральных функций из config.php ведут себя как ожидается.
 *
 * Требует уже поднятый PHP-сервер (см. tests/smoke.sh) и БД со схемой +
 * минимальными тестовыми данными (сеются прямо здесь, идемпотентно).
 * Печатает PASS/FAIL по каждой проверке и завершается ненулевым кодом,
 * если хоть одна провалилась — этого достаточно, чтобы CI increment
 * упал на регрессии, а не только на синтаксической ошибке.
 */

$baseUrl = getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8899';
$failures = 0;

function check($condition, $label) {
    global $failures;
    echo ($condition ? "PASS" : "FAIL") . ": $label\n";
    if (!$condition) $failures++;
}

// ---------- 1. Сеем минимальные тестовые данные ----------
putenv('DB_HOST=' . (getenv('DB_HOST') ?: 'localhost'));
putenv('DB_NAME=' . (getenv('DB_NAME') ?: 'riveg_rent_ci'));
require_once __DIR__ . '/../public_html/config.php';
$pdo = getDbConnection();

$passwordHash = password_hash('smoketest123', PASSWORD_DEFAULT);
$pdo->exec("DELETE FROM applications WHERE id = 90001");
$pdo->exec("DELETE FROM location_operators WHERE id = 90001");
$pdo->exec("DELETE FROM locations WHERE id = 90001");
$pdo->exec("DELETE FROM users WHERE id IN (90001, 90002, 90003)");

$pdo->prepare("
    INSERT INTO users (id, email, password, full_name, role, is_verified, has_subscription)
    VALUES (90001, 'smoke_owner@example.test', ?, 'Smoke Owner', 'owner', 1, 1),
           (90002, 'smoke_operator@example.test', ?, 'Smoke Operator', 'operator', 1, 0),
           (90003, 'smoke_admin@example.test', ?, 'Smoke Admin', 'admin', 1, 0)
")->execute([$passwordHash, $passwordHash, $passwordHash]);

$pdo->prepare("
    INSERT INTO locations (id, owner_id, title, address, city, price_month, is_active, is_moderated)
    VALUES (90001, 90001, 'Smoke Test Location', 'Test St 1', 'Москва', 5000, 1, 1)
")->execute();

$pdo->prepare("
    INSERT INTO location_operators (id, location_id, operator_id, owner_id, status)
    VALUES (90001, 90001, 90002, 90001, 'active')
")->execute();

$pdo->prepare("
    INSERT INTO applications (id, location_id, operator_id, owner_id, status, initial_message)
    VALUES (90001, 90001, 90002, 90001, 'negotiating', 'Smoke test application')
")->execute();

echo "Seeded smoke-test fixtures (users 90001-90003, location 90001, application 90001).\n\n";

// ---------- 2. Прямые проверки функций из config.php ----------
check(function_exists('csrf_token'), 'csrf_token() is defined');
check(function_exists('getUserUploadedBytes'), 'getUserUploadedBytes() is defined');
check(function_exists('rr_check_rate_limit'), 'rr_check_rate_limit() is defined');

check(isServiceOverdue(SERVICE_DUE_DAYS) === true, 'isServiceOverdue() true at exactly the threshold');
check(isServiceOverdue(SERVICE_DUE_DAYS - 1) === false, 'isServiceOverdue() false just under the threshold');
check(isServiceOverdue(null) === false, 'isServiceOverdue() false when no data yet (null)');

check(getUserUploadedBytes($pdo, 90001) === 0, 'getUserUploadedBytes() is 0 for a user with no uploads');

$pdo->exec("DELETE FROM rate_limits WHERE rate_key = 'smoke_test_key'");
$allowed = 0;
for ($i = 0; $i < 5; $i++) {
    if (rr_check_rate_limit($pdo, 'smoke_test_key', 3, 60)) $allowed++;
}
check($allowed === 3, "rr_check_rate_limit() allows exactly 3 of 5 requests under a limit of 3 (got $allowed)");
$pdo->exec("DELETE FROM rate_limits WHERE rate_key = 'smoke_test_key'");

// ---------- 3. HTTP-проверки через реально поднятый сервер ----------
function httpGet($url, $cookieJar = null, $followRedirects = true) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function httpPost($url, $fields, $cookieJar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function extractCsrf($html) {
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    return null;
}

function loginAs($baseUrl, $email, $password, $cookieJar) {
    [, $loginPage] = httpGet("$baseUrl/pages/login.php", $cookieJar);
    $csrf = extractCsrf($loginPage);
    if (!$csrf) return false;
    [$code, $body] = httpPost("$baseUrl/pages/login.php", [
        'csrf_token' => $csrf,
        'email' => $email,
        'password' => $password,
    ], $cookieJar);
    // После успешного логина редиректит на дашборд/профиль, а не обратно на форму входа
    return $code === 200 && strpos($body, 'name="password"') === false;
}

// --- Гость: публичные страницы открываются, защищённые — редиректят на логин ---
$guestJar = tempnam(sys_get_temp_dir(), 'smoke_guest_');
foreach (['/index.php', '/pages/catalog.php', '/pages/map.php', '/pages/login.php', '/pages/register.php', '/pages/how_it_works.php'] as $path) {
    [$code] = httpGet($baseUrl . $path, $guestJar);
    check($code === 200, "guest GET $path -> 200 (got $code)");
}
foreach (['/pages/add_location.php', '/pages/profile.php', '/admin/index.php'] as $path) {
    [$code] = httpGet($baseUrl . $path, $guestJar, false);
    check($code === 302, "guest GET $path -> 302 redirect to login (got $code)");
}
unlink($guestJar);

// --- Оператор: логин + свои страницы ---
$opJar = tempnam(sys_get_temp_dir(), 'smoke_operator_');
check(loginAs($baseUrl, 'smoke_operator@example.test', 'smoketest123', $opJar), 'operator login succeeds');
foreach ([
    '/pages/operator_dashboard.php',
    '/pages/operator_locations.php',
    '/pages/operator_applications.php',
    '/pages/application_chat.php?application_id=90001',
    '/pages/events_calendar.php',
    '/pages/documents.php',
] as $path) {
    [$code] = httpGet($baseUrl . $path, $opJar);
    check($code === 200, "operator GET $path -> 200 (got $code)");
}
unlink($opJar);

// --- Владелец: логин + свои страницы ---
$ownerJar = tempnam(sys_get_temp_dir(), 'smoke_owner_');
check(loginAs($baseUrl, 'smoke_owner@example.test', 'smoketest123', $ownerJar), 'owner login succeeds');
foreach ([
    '/pages/profile.php',
    '/pages/add_location.php',
    '/pages/owner_applications.php',
    '/pages/owner_operators.php',
    '/pages/location.php?id=90001',
] as $path) {
    [$code] = httpGet($baseUrl . $path, $ownerJar);
    check($code === 200, "owner GET $path -> 200 (got $code)");
}
unlink($ownerJar);

// --- Админ: логин + панель ---
$adminJar = tempnam(sys_get_temp_dir(), 'smoke_admin_');
check(loginAs($baseUrl, 'smoke_admin@example.test', 'smoketest123', $adminJar), 'admin login succeeds');
foreach ([
    '/admin/index.php',
    '/admin/locations.php',
    '/admin/users.php',
    '/admin/geocode_backfill.php',
] as $path) {
    [$code] = httpGet($baseUrl . $path, $adminJar);
    check($code === 200, "admin GET $path -> 200 (got $code)");
}
unlink($adminJar);

// ---------- 4. Убираем тестовые данные ----------
$pdo->exec("DELETE FROM applications WHERE id = 90001");
$pdo->exec("DELETE FROM location_operators WHERE id = 90001");
$pdo->exec("DELETE FROM locations WHERE id = 90001");
$pdo->exec("DELETE FROM users WHERE id IN (90001, 90002, 90003)");

echo "\n" . ($failures === 0 ? "ALL SMOKE CHECKS PASSED" : "$failures SMOKE CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
