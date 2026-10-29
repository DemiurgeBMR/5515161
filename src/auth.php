<?php
/**
 * Вход сотрудников и гостей, сессии, защита форм (CSRF) и ограничение
 * частоты попыток.
 *
 * Сессия заводится только там, где она нужна (админка, «Мои брони»):
 * обычные страницы сайта cookie не ставят.
 */

const STAFF_ROLES = ['owner' => 'Владелец', 'admin' => 'Администратор', 'housekeeper' => 'Горничная'];

/** Куда может заходить горничная: свой кабинет уборки и профиль (смена пароля). */
const HOUSEKEEPER_PAGES = ['cleaning.php', 'profile.php'];
const SESSION_LIFETIME = 30 * 24 * 3600; // 30 дней — чтобы администратор с телефона не входил заново каждый день

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = HOTEL_ROOT . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Своя папка сессий: на хостинге общая папка чистится по чужим правилам
    // и выкидывала бы администратора раньше времени.
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('hotel_sid');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// -------------------------------------------------------------------
// CSRF: у каждой формы скрытое поле с токеном сессии
// -------------------------------------------------------------------

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    session_boot();
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        echo 'Страница устарела. Вернитесь назад, обновите её и попробуйте ещё раз.';
        exit;
    }
}

// -------------------------------------------------------------------
// Ограничение частоты: не больше $max попыток за $window секунд
// -------------------------------------------------------------------

function rate_key(string $value): string
{
    return hash('sha256', $value . '|' . __DIR__);
}

function rate_limited(string $scope, string $key, int $max, int $window): bool
{
    $since = date('Y-m-d H:i:s', time() - $window);
    return (int) q_val('SELECT COUNT(*) FROM rate_limits WHERE scope = ? AND key_hash = ? AND created_at > ?', [$scope, rate_key($key), $since]) >= $max;
}

function rate_hit(string $scope, string $key): void
{
    q_insert('rate_limits', ['scope' => $scope, 'key_hash' => rate_key($key), 'created_at' => now()]);
}

function rate_clear(string $scope, string $key): void
{
    q('DELETE FROM rate_limits WHERE scope = ? AND key_hash = ?', [$scope, rate_key($key)]);
}

// -------------------------------------------------------------------
// Сотрудники
// -------------------------------------------------------------------

function current_staff(): ?array
{
    $cache = &app_cache();
    if (array_key_exists('staff', $cache)) {
        return $cache['staff'];
    }
    $cache['staff'] = null;
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['staff_id'])) {
        return null;
    }
    $s = q_one('SELECT * FROM staff WHERE id = ? AND is_active = 1', [(int) $_SESSION['staff_id']]);
    // Пароль сменили на другом устройстве — старые сессии больше не действуют.
    if (!$s || !hash_equals((string) ($_SESSION['staff_pw'] ?? ''), substr(hash('sha256', $s['password_hash']), 0, 16))) {
        unset($_SESSION['staff_id'], $_SESSION['staff_pw']);
        return null;
    }
    $s['id'] = (int) $s['id'];
    return $cache['staff'] = $s;
}

function is_owner(): bool
{
    return (current_staff()['role'] ?? '') === 'owner';
}

/** Страница только для сотрудников; $ownerOnly — только для владельца. */
function require_staff(bool $ownerOnly = false): array
{
    session_boot();
    $s = current_staff();
    if (!$s) {
        $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
        redirect('/admin/login.php?next=' . rawurlencode($next));
    }
    if ($s['must_change_password'] && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php') {
        redirect('/admin/profile.php?first=1');
    }
    // Горничная видит только уборку: ни броней, ни гостей, ни денег.
    if ($s['role'] === 'housekeeper' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), HOUSEKEEPER_PAGES, true)) {
        redirect('/admin/cleaning.php');
    }
    if ($ownerOnly && $s['role'] !== 'owner') {
        http_response_code(403);
        echo 'Этот раздел доступен только владельцу.';
        exit;
    }
    return $s;
}

/** Вход по почте и паролю. Возвращает сотрудника или текст ошибки. */
function staff_login(string $email, string $password)
{
    $email = mb_strtolower(trim($email));
    $ip = client_ip();
    if (rate_limited('staff_ip', $ip, 20, 900) || rate_limited('staff_email', $email, 8, 900)) {
        return 'Слишком много попыток. Подождите 15 минут.';
    }
    $s = q_one('SELECT * FROM staff WHERE email = ?', [$email]);
    // password_verify считаем и для несуществующего адреса — чтобы по времени
    // ответа нельзя было узнать, какие адреса зарегистрированы.
    $hash = $s['password_hash'] ?? password_hash('not-a-real-password', PASSWORD_DEFAULT);
    $ok = password_verify($password, $hash);
    if (!$s || !$ok || !$s['is_active']) {
        rate_hit('staff_ip', $ip);
        rate_hit('staff_email', $email);
        return 'Неверная почта или пароль.';
    }
    rate_clear('staff_email', $email);
    if (password_needs_rehash($s['password_hash'], PASSWORD_DEFAULT)) {
        $s['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        q('UPDATE staff SET password_hash = ? WHERE id = ?', [$s['password_hash'], $s['id']]);
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['staff_id'] = (int) $s['id'];
    $_SESSION['staff_pw'] = substr(hash('sha256', $s['password_hash']), 0, 16);
    q('UPDATE staff SET last_login_at = ? WHERE id = ?', [now(), $s['id']]);
    app_cache_clear();
    audit('staff', (int) $s['id'], 'login', null, 'staff');
    return $s;
}

function staff_logout(): void
{
    session_boot();
    unset($_SESSION['staff_id'], $_SESSION['staff_pw']);
    session_regenerate_id(true);
    app_cache_clear();
}

function staff_create(string $email, string $name, string $role, ?string $password = null): array
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new BookingError('Проверьте адрес почты', 'email');
    }
    if (trim($name) === '') {
        throw new BookingError('Укажите имя', 'name');
    }
    if (!isset(STAFF_ROLES[$role])) {
        throw new BookingError('Неизвестная роль', 'role');
    }
    if (q_val('SELECT id FROM staff WHERE email = ?', [$email])) {
        throw new BookingError('Сотрудник с такой почтой уже есть', 'email');
    }
    $temporary = $password === null;
    $password = $password ?? temp_password();
    if (mb_strlen($password) < 8) {
        throw new BookingError('Пароль — не короче 8 символов', 'password');
    }
    $id = q_insert('staff', [
        'email' => $email, 'name' => trim($name), 'role' => $role,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'must_change_password' => $temporary ? 1 : 0,
        'created_at' => now(),
    ]);
    audit('staff', $id, 'created', $email . ', ' . STAFF_ROLES[$role]);
    return ['id' => $id, 'email' => $email, 'password' => $password];
}

function staff_set_password(int $id, string $password, bool $temporary = false): void
{
    if (mb_strlen($password) < 8) {
        throw new BookingError('Пароль — не короче 8 символов', 'password');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    q('UPDATE staff SET password_hash = ?, must_change_password = ? WHERE id = ?', [$hash, $temporary ? 1 : 0, $id]);
    // Текущая сессия этого сотрудника остаётся рабочей, остальные выходят.
    if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['staff_id'] ?? 0) === $id) {
        $_SESSION['staff_pw'] = substr(hash('sha256', $hash), 0, 16);
    }
    app_cache_clear();
}

/** Временный пароль без похожих символов (0/O, 1/l) — его диктуют по телефону. */
function temp_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $p;
}

// -------------------------------------------------------------------
// Гости: вход в «Мои брони» по коду из письма
// -------------------------------------------------------------------

const LOGIN_CODE_TTL = 15 * 60;

function current_guest_email(): ?string
{
    session_boot();
    return $_SESSION['guest_email'] ?? null;
}

/**
 * Отправить код на почту. Код уходит, только если на эту почту есть брони,
 * но ответ одинаковый — чтобы через форму нельзя было проверять чужие адреса.
 */
function guest_send_code(string $email): ?string
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Проверьте адрес почты';
    }
    // На одну почту — не больше 3 кодов за 15 минут и 6 за сутки: по 5 попыток
    // на код подобрать 6 цифр перебором практически невозможно.
    if (rate_limited('code_ip', client_ip(), 10, 3600) || rate_limited('code_email', $email, 3, 900)) {
        return 'Слишком много запросов. Подождите 15 минут.';
    }
    if (rate_limited('code_email_day', $email, 6, 86400)) {
        return 'Слишком много запросов за сутки. Позвоните нам — администратор поможет.';
    }
    rate_hit('code_ip', client_ip());
    rate_hit('code_email', $email);
    rate_hit('code_email_day', $email);
    $has = q_val('SELECT b.id FROM bookings b JOIN guests g ON g.id = b.guest_id WHERE g.email = ? LIMIT 1', [$email]);
    if (!$has) {
        return null;
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE login_codes SET used_at = ? WHERE email = ? AND used_at IS NULL', [now(), $email]);
    q_insert('login_codes', [
        'email' => $email, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => date('Y-m-d H:i:s', time() + LOGIN_CODE_TTL), 'created_at' => now(),
    ]);
    [$subject, $text] = mail_login_code($code);
    send_mail($email, $subject, $text);
    return null;
}

/** Проверить код. Возвращает текст ошибки или null при успешном входе. */
function guest_verify_code(string $email, string $code): ?string
{
    $email = mb_strtolower(trim($email));
    $code = preg_replace('/\D+/', '', $code);
    $row = q_one('SELECT * FROM login_codes WHERE email = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$email, now()]);
    if (!$row) {
        return 'Код устарел — запросите новый.';
    }
    if ((int) $row['attempts'] >= 5) {
        return 'Слишком много неверных попыток — запросите новый код.';
    }
    q('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
    if (!password_verify($code, $row['code_hash'])) {
        return 'Неверный код. Проверьте письмо — код из 6 цифр.';
    }
    q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now(), $row['id']]);
    session_boot();
    session_regenerate_id(true);
    $_SESSION['guest_email'] = $email;
    return null;
}

function guest_logout(): void
{
    session_boot();
    unset($_SESSION['guest_email']);
    session_regenerate_id(true);
}
