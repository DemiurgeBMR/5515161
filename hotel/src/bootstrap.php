<?php
/**
 * Общий код сайта гостиницы: загрузка конфига, хелперы для шаблонов и
 * расчёт стоимости. Подключается первой строкой каждой страницы.
 *
 * Расчёт стоимости живёт здесь в одном экземпляре для PHP (форма без JS,
 * письмо администратору) и повторяется в assets/js/main.js для живого
 * пересчёта в браузере. Правила скидок оба берут из config/hotel.php.
 */

declare(strict_types=1);

const HOTEL_ROOT = __DIR__ . '/..';
const HOTEL_TZ   = 'Europe/Simferopol';

date_default_timezone_set(HOTEL_TZ);

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
    $file = HOTEL_ROOT . '/config/secrets.php';
    $defaults = [
        'mail_to'            => '',
        'mail_from'          => '',
        'telegram_bot_token' => '',
        'telegram_chat_ids'  => [],
    ];
    return is_file($file) ? array_merge($defaults, require $file) : $defaults;
}

// -------------------------------------------------------------------
// Шаблоны
// -------------------------------------------------------------------

function e(?string $s): string
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

/** Заглушка вместо фотографии — пока в конфиге нет настоящих фото. */
function photo_placeholder(string $label, string $class = ''): string
{
    return '<div class="ph ' . e($class) . '" role="img" aria-label="Здесь будет фото: ' . e($label) . '">'
        . icon('camera', 'ph__icon')
        . '<span class="ph__label">[Фото: ' . e($label) . ']</span></div>';
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

function room_price(array $room): string
{
    $sum = number_format($room['price'], 0, ',', "\u{00A0}");
    return !empty($room['price_tbd']) ? '[' . $sum . "]\u{00A0}₽" : $sum . "\u{00A0}₽";
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

function tel_href(): string
{
    $d = phone_digits(hotel()['phone']);
    return $d !== '' ? 'tel:+' . $d : '#contacts';
}

function whatsapp_href(string $text = ''): string
{
    $m = hotel()['messengers'];
    $d = phone_digits(filled($m['whatsapp']) ? $m['whatsapp'] : hotel()['phone']);
    if ($d === '') {
        return '#contacts';
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
// Цены
// -------------------------------------------------------------------

/** Ступень скидки, которая действует при таком числе ночей, или null. */
function stay_tier(int $nights): ?array
{
    $found = null;
    foreach (hotel()['long_stay'] as $tier) {
        if ($nights >= $tier['nights'] && ($found === null || $tier['nights'] > $found['nights'])) {
            $found = $tier;
        }
    }
    return $found;
}

function discounted(int $price, int $percent): int
{
    return (int) round($price * (100 - $percent) / 100);
}

/**
 * Предварительная стоимость проживания.
 * total = null, если для такого срока цена считается индивидуально.
 */
function estimate(string $roomKey, int $nights): ?array
{
    $rooms = hotel()['rooms'];
    if (!isset($rooms[$roomKey]) || $nights < 1) {
        return null;
    }
    $price = (int) $rooms[$roomKey]['price'];
    $tier = stay_tier($nights);
    $base = $price * $nights;
    if ($tier === null) {
        return ['nights' => $nights, 'price' => $price, 'base' => $base, 'tier' => null, 'total' => $base];
    }
    if ($tier['percent'] === null) {
        return ['nights' => $nights, 'price' => $price, 'base' => $base, 'tier' => $tier, 'total' => null];
    }
    return ['nights' => $nights, 'price' => $price, 'base' => $base, 'tier' => $tier,
        'total' => discounted($price, (int) $tier['percent']) * $nights];
}

/** Данные для расчёта в браузере (main.js) — из того же конфига. */
function pricing_json(): string
{
    $h = hotel();
    $rooms = [];
    foreach ($h['rooms'] as $key => $r) {
        $rooms[$key] = ['name' => $r['name'], 'price' => (int) $r['price'], 'guests' => (int) $r['guests'], 'tbd' => !empty($r['price_tbd'])];
    }
    return json_encode([
        'rooms' => $rooms,
        'tiers' => $h['long_stay'],
        'phone' => $h['phone'],
        'whatsapp' => whatsapp_href(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

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
