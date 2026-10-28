<?php
/**
 * Общий код сайта гостиницы: конфиг, хелперы для шаблонов, даты, ссылки.
 * Подключается первой строкой каждой страницы и подтягивает остальные
 * модули: база, цены, занятость, брони, почта, вход, площадки.
 */

declare(strict_types=1);

const HOTEL_ROOT = __DIR__ . '/..';
const HOTEL_TZ   = 'Europe/Simferopol';

date_default_timezone_set(HOTEL_TZ);
mb_internal_encoding('UTF-8');

function hotel(): array
{
    static $config = null;
    if ($config === null) {
        $config = require HOTEL_ROOT . '/config/hotel.php';
    }
    return $config;
}

function hotel_secrets(): array
{
    static $secrets = null;
    if ($secrets === null) {
        $file = HOTEL_ROOT . '/config/secrets.php';
        $defaults = [
            'db_host' => 'MySQL-8.0', 'db_port' => 3306, 'db_name' => 'hotel', 'db_user' => 'root', 'db_pass' => '',
            'mail_transport' => 'log', 'mail_from' => '', 'mail_from_name' => '',
            'smtp_host' => '', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '',
            'notify_extra' => '', 'setup_key' => '',
        ];
        $secrets = is_file($file) ? array_merge($defaults, require $file) : $defaults;
        // Для тестов: письма в лог, что бы ни было в secrets.php.
        if (getenv('HOTEL_MAIL_TRANSPORT')) {
            $secrets['mail_transport'] = getenv('HOTEL_MAIL_TRANSPORT');
        }
    }
    return $secrets;
}

/** Кеш на время одного запроса; app_cache_clear() — после изменений в админке. */
function &app_cache(): array
{
    static $cache = [];
    return $cache;
}

function app_cache_clear(): void
{
    $cache = &app_cache();
    $cache = [];
}

// -------------------------------------------------------------------
// Шаблоны
// -------------------------------------------------------------------

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Заполнено ли значение настоящими данными (а не пусто и не «[заглушка]»). */
function filled($value): bool
{
    return is_string($value) && trim($value) !== '' && !preg_match('/\[[^\]]*\]/u', $value);
}

function icon(string $name, string $class = ''): string
{
    $cls = trim('i ' . $class);
    return '<svg class="' . e($cls) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
}

/** Заглушка вместо фотографии — пока фото не загружено в админке. */
function photo_placeholder(string $label, string $class = ''): string
{
    return '<div class="ph ' . e($class) . '" role="img" aria-label="Здесь будет фото: ' . e($label) . '">'
        . icon('camera', 'ph__icon')
        . '<span class="ph__label">[Фото: ' . e($label) . ']</span></div>';
}

/** Фото из админки (Настройки → Фото на главной) или заглушка. */
function site_photo(string $slot, string $label, string $class = ''): string
{
    $path = setting('photo_' . $slot);
    if ($path && is_file(HOTEL_ROOT . '/public/' . $path)) {
        return '<img class="' . e($class) . ' is-photo" src="/' . e($path) . '" alt="' . e($label) . '" loading="lazy" decoding="async">';
    }
    return photo_placeholder($label, $class);
}

/** Ссылка на файл из public/assets с меткой версии, чтобы браузер не держал старый CSS/JS. */
function asset(string $path): string
{
    $file = HOTEL_ROOT . '/public/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return '/assets/' . $path . '?v=' . $v;
}

function money(int $rub): string
{
    return number_format($rub, 0, ',', "\u{00A0}") . "\u{00A0}₽";
}

/** 1 ночь, 2 ночи, 5 ночей. */
function plural(int $n, string $one, string $few, string $many): string
{
    $n10 = $n % 10;
    $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) {
        return $one;
    }
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
        return $few;
    }
    return $many;
}

function nights_label(int $n): string
{
    return $n . "\u{00A0}" . plural($n, 'ночь', 'ночи', 'ночей');
}

/** У ИП — ОГРНИП, у организации — ОГРН. */
function ogrn_label(): string
{
    return preg_match('/^\[?ИП(\s|$)/u', hotel()['legal']['operator']) ? 'ОГРНИП' : 'ОГРН';
}

function full_address(): string
{
    $a = hotel()['address'];
    return $a['locality'] . ', ' . $a['street'];
}

// -------------------------------------------------------------------
// Даты
// -------------------------------------------------------------------

const RU_MONTHS_GEN = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const RU_MONTHS = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const RU_WEEKDAYS_SHORT = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone(HOTEL_TZ));
}

/** Дата из формы (ГГГГ-ММ-ДД) или null. */
function parse_date(string $s): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, new DateTimeZone(HOTEL_TZ));
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

/** «5 ноября» (с годом, если он не текущий или $withYear). */
function ru_date(string $date, bool $withYear = false): string
{
    $d = parse_date(substr($date, 0, 10));
    if (!$d) {
        return $date;
    }
    $s = (int) $d->format('j') . "\u{00A0}" . RU_MONTHS_GEN[(int) $d->format('n')];
    if ($withYear || $d->format('Y') !== today()->format('Y')) {
        $s .= ' ' . $d->format('Y');
    }
    return $s;
}

/** «5–12 ноября», «28 октября – 4 ноября». */
function ru_date_range(string $from, string $to): string
{
    $a = parse_date($from);
    $b = parse_date($to);
    if (!$a || !$b) {
        return $from . ' – ' . $to;
    }
    if ($a->format('Y-m') === $b->format('Y-m')) {
        return $a->format('j') . '–' . ru_date($to);
    }
    return ru_date($from) . ' – ' . ru_date($to);
}

function ru_datetime(string $dt): string
{
    return ru_date($dt) . ', ' . substr($dt, 11, 5);
}

// -------------------------------------------------------------------
// Запросы и ответы
// -------------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim(str_replace("\0", '', $v)) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Запрос с этого же компьютера (Open Server) или с локального адреса. */
function is_local_request(): bool
{
    $ip = client_ip();
    return $ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '127.') === 0;
}

/**
 * Адрес сайта для ссылок в письмах. Берётся из config/hotel.php; адрес из
 * запроса — только для локальной разработки (http://hotel/), иначе в
 * письмо администратору можно было бы подсунуть ссылку на чужой сайт.
 */
function base_url(): string
{
    $configured = rtrim(hotel()['site_url'], '/');
    if (filled($configured)) {
        return $configured;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^(localhost|127\.0\.0\.1|[a-z0-9-]+|[a-z0-9.-]+\.(local|test|localhost))(:\d+)?$/i', $host)) {
        return 'http://' . $host;
    }
    return 'http://hotel';
}

// -------------------------------------------------------------------
// Связь
// -------------------------------------------------------------------

/** Телефон в формате 7XXXXXXXXXX или пустая строка, если номер ещё не задан. */
function phone_digits(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '8') {
        $d = '7' . substr($d, 1);
    }
    return strlen($d) === 11 ? $d : '';
}

function tel_href(?string $phone = null): string
{
    $d = phone_digits($phone ?? hotel()['phone']);
    return $d !== '' ? 'tel:+' . $d : ($phone === null ? '#contacts' : '');
}

function whatsapp_href(string $text = '', ?string $phone = null): string
{
    $m = hotel()['messengers'];
    $d = phone_digits($phone ?? (filled($m['whatsapp']) ? $m['whatsapp'] : hotel()['phone']));
    if ($d === '') {
        return $phone === null ? '#contacts' : '';
    }
    return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/**
 * Кнопки мессенджеров для блока контактов. Пустая строка в конфиге —
 * кнопки нет; заглушка в скобках — кнопка есть, но ведёт к контактам.
 */
function messenger_links(): array
{
    $m = hotel()['messengers'];
    $links = [];
    if ($m['telegram'] !== '') {
        $links[] = ['key' => 'telegram', 'label' => 'Telegram', 'icon' => 'send',
            'href' => filled($m['telegram']) ? 'https://t.me/' . ltrim($m['telegram'], '@') : '#contacts'];
    }
    if ($m['whatsapp'] !== '') {
        $links[] = ['key' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'message-circle', 'href' => whatsapp_href()];
    }
    if ($m['max'] !== '') {
        $links[] = ['key' => 'max', 'label' => 'MAX', 'icon' => 'message-circle',
            'href' => filled($m['max']) ? $m['max'] : '#contacts'];
    }
    return $links;
}

// -------------------------------------------------------------------
// Карта
// -------------------------------------------------------------------

function has_coords(): bool
{
    $a = hotel()['address'];
    return is_numeric($a['lat']) && is_numeric($a['lon']);
}

function map_href(): string
{
    $a = hotel()['address'];
    if (has_coords()) {
        return 'https://yandex.ru/maps/?pt=' . $a['lon'] . ',' . $a['lat'] . '&z=17&l=map';
    }
    return 'https://yandex.ru/maps/?text=' . rawurlencode(full_address());
}

function route_href(): string
{
    $a = hotel()['address'];
    if (has_coords()) {
        return 'https://yandex.ru/maps/?rtext=~' . $a['lat'] . ',' . $a['lon'] . '&rtt=auto';
    }
    return map_href();
}

function map_widget_src(): ?string
{
    if (!has_coords()) {
        return null;
    }
    $a = hotel()['address'];
    return 'https://yandex.ru/map-widget/v1/?ll=' . $a['lon'] . ',' . $a['lat'] . '&z=16&pt=' . $a['lon'] . ',' . $a['lat'] . ',pm2rdm';
}

function twogis_href(): string
{
    $l = hotel()['listings']['2gis'];
    return filled($l) ? $l : 'https://2gis.ru/simferopol/search/' . rawurlencode(hotel()['address']['street']);
}

// -------------------------------------------------------------------
// Модули
// -------------------------------------------------------------------

require __DIR__ . '/db.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/availability.php';
require __DIR__ . '/bookings.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/uploads.php';
require __DIR__ . '/ical.php';
require __DIR__ . '/cron.php';
