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
[, $guestCatalog] = httpGet($baseUrl . '/pages/catalog.php', $guestJar);
check(strpos($guestCatalog, 'rr-tour.js') === false, 'guest: interactive tour script is not loaded');
[$code] = httpPost($baseUrl . '/api/onboarding.php', ['action' => 'start'], $guestJar);
check($code === 403, "guest POST /api/onboarding.php -> 403 (got $code)");

// --- Регистрация: подтверждение пароля и стартовый статус обучения ---
// У register.php свой rate-limit по IP (5 попыток за 5 минут) — чистим ключ,
// чтобы повторные локальные прогоны смока не упирались в него.
$pdo->exec("DELETE FROM rate_limits WHERE rate_key LIKE 'register:%'");
$pdo->exec("DELETE FROM users WHERE email = 'smoke_register@example.test'");
[, $registerPage] = httpGet($baseUrl . '/pages/register.php', $guestJar);
check(strpos($registerPage, 'name="password_confirm"') !== false, 'register form has the password confirmation field');
$registerFields = [
    'csrf_token' => extractCsrf($registerPage),
    'role' => 'operator',
    'full_name' => 'Smoke Register',
    'email' => 'smoke_register@example.test',
    'phone' => '',
    'password' => 'Smoke123!pass',
    'privacy_consent' => '1',
    'terms_consent' => '1',
];
[, $mismatchBody] = httpPost($baseUrl . '/pages/register.php', $registerFields + ['password_confirm' => 'Smoke123!other'], $guestJar);
check(strpos($mismatchBody, 'Пароли не совпадают') !== false, 'register rejects a mismatching password confirmation');
check(strpos($mismatchBody, 'name="email"') !== false, 'register stays on the form after a mismatch');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$stmt->execute(['smoke_register@example.test']);
check((int) $stmt->fetchColumn() === 0, 'register: no account is created on a password mismatch');
[, $missingConfirmBody] = httpPost($baseUrl . '/pages/register.php', $registerFields, $guestJar);
check(strpos($missingConfirmBody, 'Пароли не совпадают') !== false, 'register rejects a request with no confirmation field at all');
httpPost($baseUrl . '/pages/register.php', $registerFields + ['password_confirm' => 'Smoke123!pass'], $guestJar);
$stmt = $pdo->prepare("SELECT onboarding_status FROM users WHERE email = ?");
$stmt->execute(['smoke_register@example.test']);
check($stmt->fetchColumn() === 'pending', 'register: matching passwords create the account with onboarding_status = pending');
$pdo->exec("DELETE FROM users WHERE email = 'smoke_register@example.test'");
$pdo->exec("DELETE FROM rate_limits WHERE rate_key LIKE 'register:%'");
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

// --- Интерактивное обучение: состояние и API (api/onboarding.php) ---
function onboardingCall($baseUrl, $jar, $csrf, $fields) {
    [$code, $body] = httpPost($baseUrl . '/api/onboarding.php', $fields + ['csrf_token' => $csrf], $jar);
    return [$code, json_decode($body, true)];
}
[, $ownerPage] = httpGet($baseUrl . '/pages/profile.php', $ownerJar);
check(strpos($ownerPage, 'window.rrOnboarding') !== false && strpos($ownerPage, 'rr-tour.js') !== false, 'owner: tour config and script are on the page');
check(strpos($ownerPage, '"status":"pending"') !== false, 'owner: a new account starts with onboarding_status = pending');
check(strpos($ownerPage, 'data-rr-tour-start') !== false, 'owner: account menu has the «Обучение» entry');
$ownerCsrf = preg_match('/window\.csrfToken = "([^"]+)"/', $ownerPage, $m) ? $m[1] : '';

[$code] = httpPost($baseUrl . '/api/onboarding.php', ['action' => 'skip'], $ownerJar);
check($code === 403, "onboarding API without a CSRF token -> 403 (got $code)");
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'nonsense']);
check($code === 400, "onboarding API rejects an unknown action (got $code)");
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'start']);
check($code === 200 && $res['status'] === 'in_progress' && $res['chapter'] === 0, 'onboarding start -> in_progress, chapter 0');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'progress', 'chapter' => '1']);
check($res['status'] === 'in_progress' && $res['chapter'] === 1, 'onboarding progress -> chapter 1');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'progress', 'chapter' => '9999']);
check($res['chapter'] === ONBOARDING_MAX_CHAPTER, 'onboarding progress clamps an out-of-range chapter');
[, $ownerPage] = httpGet($baseUrl . '/pages/profile.php', $ownerJar);
check(strpos($ownerPage, '"status":"in_progress"') !== false, 'in-progress state is passed to the page');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'skip']);
check($res['status'] === 'skipped', 'onboarding skip -> skipped');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'progress', 'chapter' => '1']);
check($res['status'] === 'skipped', 'a stale tab cannot revive a skipped tour via progress');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'start']);
check($res['status'] === 'in_progress', 'restart after skip -> in_progress');
[$code, $res] = onboardingCall($baseUrl, $ownerJar, $ownerCsrf, ['action' => 'complete']);
check($res['status'] === 'completed', 'onboarding complete -> completed');
$stmt = $pdo->prepare("SELECT onboarding_status FROM users WHERE id = 90001");
$stmt->execute();
check($stmt->fetchColumn() === 'completed', 'onboarding state is persisted in the database');
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
[, $adminCatalog] = httpGet($baseUrl . '/pages/catalog.php', $adminJar);
check(strpos($adminCatalog, 'rr-tour.js') === false && strpos($adminCatalog, 'data-rr-tour-start') === false, 'admin: no tour script or menu entry');
$adminCsrf = preg_match('/window\.csrfToken = "([^"]+)"/', $adminCatalog, $m) ? $m[1] : '';
[$code] = httpPost($baseUrl . '/api/onboarding.php', ['action' => 'start', 'csrf_token' => $adminCsrf], $adminJar);
check($code === 403, "admin POST /api/onboarding.php -> 403 (got $code)");
unlink($adminJar);

// ---------- 4. Убираем тестовые данные ----------
$pdo->exec("DELETE FROM applications WHERE id = 90001");
$pdo->exec("DELETE FROM location_operators WHERE id = 90001");
$pdo->exec("DELETE FROM locations WHERE id = 90001");
$pdo->exec("DELETE FROM users WHERE id IN (90001, 90002, 90003)");

echo "\n" . ($failures === 0 ? "ALL SMOKE CHECKS PASSED" : "$failures SMOKE CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
