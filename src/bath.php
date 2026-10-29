<?php
/**
 * Баня и хамам: статус, описание, цены, часы работы и запись на время.
 *
 * Статус меняет только владелец (админка → Баня → Настройки):
 *  • «Скоро открытие» — на сайте фото, описание и «Сообщить об открытии»;
 *  • «Работает» — на сайте цены и запись на свободное время;
 *  • «Временно закрыта» — запись не принимается, на сайте пояснение.
 *
 * Баня одна: в каждый момент в ней может быть только одна запись, а между
 * сеансами нужен перерыв на уборку и протопку. Запись идёт в транзакции с
 * блокировкой (как бронь номера), поэтому двое не займут одно время.
 * После каждого сеанса горничной приходит задача «Уборка бани».
 */

const BATH_STATES = [
    'soon'   => 'Скоро открытие',
    'open'   => 'Работает',
    'closed' => 'Временно закрыта',
];

const BATH_STATUSES = [
    'new'       => 'Заявка',
    'confirmed' => 'Подтверждена',
    'done'      => 'Состоялась',
    'cancelled' => 'Отменена',
    'no_show'   => 'Не пришли',
];

/** Статусы, при которых время занято. */
const BATH_OCCUPYING = ['new', 'confirmed', 'done'];

const BATH_WEEKDAYS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

/** На сколько дней вперёд можно записаться с сайта. */
const BATH_AHEAD_DAYS = 90;

// -------------------------------------------------------------------
// Настройки
// -------------------------------------------------------------------

/** Есть ли баня вообще (config/hotel.php → bath.enabled). */
function bath_enabled(): bool
{
    return !empty(hotel()['bath']['enabled']);
}

/** Строки текста без пустых. */
function bath_lines(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), fn ($l) => $l !== ''));
}

/** «Веник берёзовый — 300» по строкам → [['name' => …, 'price' => 300], …]. */
function bath_parse_extras(?string $text): array
{
    $out = [];
    foreach (bath_lines($text) as $line) {
        if (preg_match('/^(.+?)\s*[—–:|-]\s*(\d[\d\s]*)\s*(?:₽|р\.?|руб\.?)?$/u', $line, $m)) {
            $out[] = ['name' => clean_line($m[1], 80), 'price' => (int) preg_replace('/\D+/', '', $m[2])];
        } else {
            $out[] = ['name' => clean_line($line, 80), 'price' => 0];
        }
    }
    return array_slice($out, 0, 20);
}

/** Все настройки бани со значениями по умолчанию. */
function bath_settings(): array
{
    $int = fn (string $k, int $default, int $min, int $max) => max($min, min($max, (int) setting('bath_' . $k, (string) $default)));
    $state = (string) setting('bath_state', 'soon');
    $s = [
        'state'           => isset(BATH_STATES[$state]) ? $state : 'soon',
        'title'           => (string) setting('bath_title', 'Баня и хамам'),
        'lead'            => (string) setting('bath_lead', 'Место, где можно выдохнуть после долгого дня. Для гостей гостиницы и жителей города — с отдельным входом, так что в гостинице остаётся тихо.'),
        'features_text'   => (string) setting('bath_features', "Русская парная\nХамам\nКомната отдыха с чаем\nДуш, полотенца и простыни"),
        'rules_text'      => (string) setting('bath_rules', "Приходите за 5–10 минут до начала — время сеанса начинается по записи\nДети — только вместе со взрослыми\nВ парную и хамам — без алкоголя\nСвою еду и напитки можно, кроме стеклянной посуды у купели"),
        'opening'         => (string) setting('bath_opening', (string) (hotel()['bath']['opening'] ?? '')),
        'closed_note'     => (string) setting('bath_closed_note', ''),
        'price'           => $int('price', 0, 0, 1000000),
        'price_weekend'   => $int('price_weekend', 0, 0, 1000000),
        'guests_included' => $int('guests_included', 4, 1, 50),
        'extra_guest'     => $int('extra_guest', 0, 0, 100000),
        'max_guests'      => $int('max_guests', 8, 1, 50),
        'min_hours'       => $int('min_hours', 2, 1, 12),
        'max_hours'       => $int('max_hours', 6, 1, 24),
        'buffer'          => $int('buffer', 60, 0, 240),
        'lead_hours'      => $int('lead_hours', 2, 0, 72),
        'hotel_discount'  => $int('hotel_discount', 10, 0, 100),
        'extras_text'     => (string) setting('bath_extras', "Веник берёзовый — 300\nВеник дубовый — 350\nЧай на травах — 200"),
    ];
    $s['features'] = bath_lines($s['features_text']);
    $s['rules'] = bath_lines($s['rules_text']);
    $s['extras'] = bath_parse_extras($s['extras_text']);
    $s['max_hours'] = max($s['min_hours'], $s['max_hours']);
    $s['max_guests'] = max($s['guests_included'], $s['max_guests']);

    // Время работы: своё на каждый день недели и особые дни (праздники,
    // санитарные дни). Пока расписание недели не сохраняли, оно собирается
    // из прежних настроек «дни работы + с/до».
    $from = $int('open_from', 10, 0, 23);
    $s['week'] = bath_parse_week(setting('bath_week'), (string) setting('bath_days', '1234567'), $from, max($from + 1, $int('open_to', 23, 1, 24)));
    $s['special'] = bath_parse_special(setting('bath_special'));
    $working = array_filter($s['week']);
    $s['days'] = implode('', array_keys($working));
    $s['open_from'] = $working ? min(array_column($working, 'from')) : 10;
    $s['open_to'] = $working ? max(array_column($working, 'to')) : 23;
    return $s;
}

/** Самое позднее закрытие — 04:00 следующего дня (28 «часов» от начала дня). */
const BATH_LATEST_CLOSE = 28;

/**
 * Расписание недели из строк «1=10-23» (день недели 1–7 = пн–вс, часы с–до;
 * «до» больше 24 — после полуночи, 26 = 02:00), «7=» — выходной.
 * Возвращает [1 => ['from' => 10, 'to' => 23], …, 7 => null].
 */
function bath_parse_week(?string $text, string $legacyDays, int $legacyFrom, int $legacyTo): array
{
    $week = [];
    for ($d = 1; $d <= 7; $d++) {
        $week[$d] = str_contains($legacyDays, (string) $d) ? ['from' => $legacyFrom, 'to' => $legacyTo] : null;
    }
    if ($text === null || trim($text) === '') {
        return $week;
    }
    foreach (bath_lines($text) as $line) {
        if (preg_match('/^([1-7])=(?:(\d{1,2})-(\d{1,2}))?$/', $line, $m)) {
            $f = isset($m[2]) ? (int) $m[2] : -1;
            $t = isset($m[3]) ? (int) $m[3] : -1;
            $week[(int) $m[1]] = $f >= 0 && $f <= 23 && $t > $f && $t <= BATH_LATEST_CLOSE ? ['from' => $f, 'to' => $t] : null;
        }
    }
    return $week;
}

function bath_week_encode(array $week): string
{
    $lines = [];
    for ($d = 1; $d <= 7; $d++) {
        $lines[] = $d . '=' . (!empty($week[$d]) ? $week[$d]['from'] . '-' . $week[$d]['to'] : '');
    }
    return implode("\n", $lines);
}

/**
 * Особые дни из строк «2026-12-31|10|18|Предпраздничный день»; без часов —
 * выходной. Возвращает [дата => ['from' => ?int, 'to' => ?int, 'note' => …]].
 */
function bath_parse_special(?string $text): array
{
    $out = [];
    foreach (bath_lines($text) as $line) {
        $p = explode('|', $line, 4) + ['', '', '', ''];
        if (!parse_date($p[0])) {
            continue;
        }
        $f = $p[1] === '' ? null : (int) $p[1];
        $t = $p[2] === '' ? null : (int) $p[2];
        $open = $f !== null && $t !== null && $f >= 0 && $f <= 23 && $t > $f && $t <= BATH_LATEST_CLOSE;
        $out[$p[0]] = ['from' => $open ? $f : null, 'to' => $open ? $t : null, 'note' => $p[3]];
    }
    ksort($out);
    return $out;
}

function bath_special_encode(array $special): string
{
    ksort($special);
    $lines = [];
    foreach ($special as $date => $x) {
        $lines[] = $date . '|' . ($x['from'] ?? '') . '|' . ($x['to'] ?? '') . '|' . str_replace(["\n", '|'], ' ', (string) $x['note']);
    }
    return implode("\n", $lines);
}

/**
 * Часы работы в эту дату: ['from' => 10, 'to' => 23, 'special' => bool, 'note' => …]
 * или null — баня не работает (выходной по расписанию или особый день).
 */
function bath_hours_on(string $date): ?array
{
    $d = parse_date($date);
    if (!$d) {
        return null;
    }
    $s = bath_settings();
    if (isset($s['special'][$date])) {
        $x = $s['special'][$date];
        return $x['from'] === null ? null : ['from' => $x['from'], 'to' => $x['to'], 'special' => true, 'note' => $x['note']];
    }
    $w = $s['week'][(int) $d->format('N')] ?? null;
    return $w ? $w + ['special' => false, 'note' => ''] : null;
}

/** Особый день в эту дату (выходной или своё время) или null. */
function bath_special_on(string $date): ?array
{
    return bath_settings()['special'][$date] ?? null;
}

/** Ближайшие особые дни: [дата => …] начиная с сегодняшнего. */
function bath_special_upcoming(int $days = 60): array
{
    $from = today()->format('Y-m-d');
    $to = today()->modify('+' . $days . ' days')->format('Y-m-d');
    return array_filter(bath_settings()['special'], fn ($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
}

/** «10:00–23:00» для часов работы. */
function bath_range(array $hrs): string
{
    return bath_hour($hrs['from']) . '–' . bath_hour($hrs['to']);
}

function bath_state(): string
{
    return bath_settings()['state'];
}

/** Принимает ли баня записи с сайта. */
function bath_is_open(): bool
{
    return bath_enabled() && bath_state() === 'open';
}

/** «10:00»; 24 — «24:00»; после полуночи (25–28) — «01:00»…«04:00». */
function bath_hour(int $h): string
{
    return sprintf('%02d:00', $h > 24 ? $h - 24 : $h);
}

/** «ежедневно», «пн–пт», «пт, сб, вс». */
function bath_days_text(?string $days = null): string
{
    $days = $days ?? bath_settings()['days'];
    $list = array_values(array_unique(array_map('intval', str_split($days))));
    sort($list);
    if (count($list) === 7) {
        return 'ежедневно';
    }
    if (!$list) {
        return 'по договорённости';
    }
    $parts = [];
    $start = $prev = $list[0];
    foreach (array_merge(array_slice($list, 1), [99]) as $d) {
        if ($d === $prev + 1) {
            $prev = $d;
            continue;
        }
        $parts[] = $prev - $start >= 2 ? BATH_WEEKDAYS[$start] . '–' . BATH_WEEKDAYS[$prev]
            : implode(', ', array_map(fn ($x) => BATH_WEEKDAYS[$x], range($start, $prev)));
        $start = $prev = $d;
    }
    return implode(', ', $parts);
}

/** «ежедневно, 10:00–23:00» или «пн–пт, 14:00–23:00; сб, вс, 10:00–02:00». */
function bath_hours_text(): string
{
    $groups = [];
    foreach (bath_settings()['week'] as $d => $hrs) {
        if ($hrs) {
            $key = $hrs['from'] . '-' . $hrs['to'];
            $groups[$key] = ($groups[$key] ?? ['days' => '', 'hrs' => $hrs]);
            $groups[$key]['days'] .= $d;
        }
    }
    if (!$groups) {
        return 'по договорённости';
    }
    return implode('; ', array_map(fn ($g) => bath_days_text($g['days']) . ', ' . bath_range($g['hrs']), $groups));
}

/** Расписание недели для страницы: [['понедельник', '10:00–23:00' | null], …]. */
function bath_week_rows(): array
{
    $rows = [];
    foreach (bath_settings()['week'] as $d => $hrs) {
        $rows[] = [RU_WEEKDAYS[$d % 7], $hrs ? bath_range($hrs) : null];
    }
    return $rows;
}

/** Сменить статус бани — только владелец. Открыть запись без цены нельзя. */
function bath_set_state(string $state): void
{
    if (!is_owner()) {
        throw new BookingError('Открыть или закрыть баню может только владелец');
    }
    if (!isset(BATH_STATES[$state])) {
        throw new BookingError('Неизвестный статус');
    }
    $s = bath_settings();
    if ($state === 'open') {
        if ($s['price'] <= 0) {
            throw new BookingError('Сначала укажите цену за час — без неё гости не увидят стоимость');
        }
        if ($s['days'] === '') {
            throw new BookingError('Отметьте дни, когда баня работает');
        }
    }
    if ($state === $s['state']) {
        return;
    }
    set_setting('bath_state', $state);
    audit('bath', null, 'state', BATH_STATES[$s['state']] . ' → ' . BATH_STATES[$state]);
    touch_data_version();
}

/** Что проверить перед открытием: [[готово?, текст], …]. */
function bath_checklist(): array
{
    $s = bath_settings();
    $photos = count(bath_photos());
    return [
        [$s['price'] > 0, $s['price'] > 0 ? 'Цена: ' . money($s['price']) . ' в час' . ($s['price_weekend'] > 0 ? ', в выходные ' . money($s['price_weekend']) : '') : 'Не указана цена за час'],
        [$s['days'] !== '', 'Время работы: ' . bath_hours_text()],
        [$photos > 0, $photos > 0 ? 'Фото: ' . $photos : 'Нет ни одного фото — на сайте будет заглушка'],
        [trim($s['lead']) !== '' && $s['features'], 'Описание и что входит'],
        [(bool) cleaning_recipients(), cleaning_recipients() ? 'Горничной будут приходить задачи «Уборка бани»' : 'Нет горничной с включёнными письмами — уборку бани придётся отмечать администратору'],
    ];
}

// -------------------------------------------------------------------
// Фото
// -------------------------------------------------------------------

function bath_photos(): array
{
    $cache = &app_cache();
    return $cache['bath_photos'] ??= q_all('SELECT * FROM bath_photos ORDER BY sort, id');
}

/** Фото бани тегом img (с лёгкой копией для телефонов). */
function bath_photo_img(array $p, string $alt, string $class = '', string $sizes = '(max-width: 760px) 100vw, 50vw', bool $first = false): string
{
    return '<img class="' . e($class) . ' is-photo" src="/' . e($p['path']) . '"' . img_srcset($p['path'], $sizes)
        . ' width="' . (int) $p['width'] . '" height="' . (int) $p['height'] . '" alt="' . e($alt) . '"'
        . ($first ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async">';
}

/** Заголовок с выделением: «Баня <em>и хамам</em>». */
function bath_title_html(?string $title = null): string
{
    $title = trim($title ?? bath_settings()['title']);
    $parts = preg_split('/\s+/u', $title, 2);
    return e($parts[0]) . (isset($parts[1]) ? ' <em>' . e($parts[1]) . '</em>' : '');
}

/** Подпись над заголовком: «Работает · ежедневно, 10:00–23:00». */
function bath_eyebrow(): string
{
    $s = bath_settings();
    return match ($s['state']) {
        'open'   => 'Работает · ' . bath_hours_text(),
        'closed' => 'Временно закрыта',
        default  => 'Скоро открытие' . (filled($s['opening']) ? ' · ' . $s['opening'] : ''),
    };
}

/** «от 2 000 ₽ в час». */
function bath_price_from(): string
{
    $s = bath_settings();
    $min = $s['price_weekend'] > 0 ? min($s['price'], $s['price_weekend']) : $s['price'];
    return ($s['price_weekend'] > 0 && $s['price_weekend'] !== $s['price'] ? 'от ' : '') . money($min) . ' в час';
}

// -------------------------------------------------------------------
// Цена
// -------------------------------------------------------------------

/** Цена часа в этот день: в субботу и воскресенье — цена выходного, если задана. */
function bath_hour_price(string $date): int
{
    $s = bath_settings();
    $d = parse_date($date);
    return $d && (int) $d->format('N') >= 6 && $s['price_weekend'] > 0 ? $s['price_weekend'] : $s['price'];
}

/** Услуги из формы (extras[номер] = количество) → [['name', 'qty', 'price'], …] по текущему списку. */
function bath_extras_from_input(array $input): array
{
    $out = [];
    foreach (bath_settings()['extras'] as $i => $x) {
        $qty = max(0, min(20, (int) ($input[$i] ?? 0)));
        if ($qty > 0) {
            $out[] = ['name' => $x['name'], 'qty' => $qty, 'price' => $x['price']];
        }
    }
    return $out;
}

/** Услуги записи — в базе по строкам «название|количество|цена». */
function bath_extras_encode(array $extras): ?string
{
    return $extras ? implode("\n", array_map(fn ($x) => str_replace(['|', "\n"], ' ', $x['name']) . '|' . (int) $x['qty'] . '|' . (int) $x['price'], $extras)) : null;
}

function bath_extras_decode(?string $text): array
{
    $out = [];
    foreach (bath_lines($text) as $line) {
        $p = explode('|', $line);
        if (count($p) === 3) {
            $out[] = ['name' => $p[0], 'qty' => (int) $p[1], 'price' => (int) $p[2]];
        }
    }
    return $out;
}

/**
 * Стоимость сеанса: часы × цена часа + доплата за гостей сверх включённых,
 * минус скидка проживающим в гостинице (на сеанс, не на услуги), плюс услуги.
 */
function bath_quote(string $date, int $hours, int $guests, bool $hotelGuest, array $extras): array
{
    $s = bath_settings();
    $hourPrice = bath_hour_price($date);
    $base = $hourPrice * $hours;
    $extraGuests = max(0, $guests - $s['guests_included']);
    $extraGuestsSum = $extraGuests * $s['extra_guest'] * $hours;
    $discount = $hotelGuest ? (int) round(($base + $extraGuestsSum) * $s['hotel_discount'] / 100) : 0;
    $extrasSum = array_sum(array_map(fn ($x) => $x['qty'] * $x['price'], $extras));
    return [
        'hour_price'       => $hourPrice,
        'hours'            => $hours,
        'base'             => $base,
        'extra_guests'     => $extraGuests,
        'extra_guests_sum' => $extraGuestsSum,
        'discount_percent' => $hotelGuest ? $s['hotel_discount'] : 0,
        'discount'         => $discount,
        'extras'           => $extras,
        'extras_sum'       => $extrasSum,
        'total'            => $base + $extraGuestsSum - $discount + $extrasSum,
    ];
}

// -------------------------------------------------------------------
// Свободное время
// -------------------------------------------------------------------

/** Сеансы, которые задевают этот день (с запасом на перерыв), — для расчёта свободного времени. */
function bath_day_sessions(string $date, ?int $excludeId = null): array
{
    $buf = bath_settings()['buffer'] * 60;
    $from = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - $buf);
    $to = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') + BATH_LATEST_CLOSE * 3600 + $buf);
    $in = "'" . implode("', '", BATH_OCCUPYING) . "'";
    return q_all("SELECT id, starts_at, ends_at, source FROM bath_bookings WHERE status IN ($in) AND starts_at < ? AND ends_at > ? AND id <> ? ORDER BY starts_at",
        [$to, $from, $excludeId ?? 0]);
}

/** Пересекается ли [start, end) с сеансом из списка с учётом перерыва между сеансами. */
function bath_overlaps(string $start, string $end, array $sessions): ?array
{
    $buf = bath_settings()['buffer'] * 60;
    $s = strtotime($start);
    $e = strtotime($end);
    foreach ($sessions as $x) {
        if ($s < strtotime($x['ends_at']) + $buf && strtotime($x['starts_at']) < $e + $buf) {
            return $x;
        }
    }
    return null;
}

/** Занятое время по базе (с блокировкой — вызывать в транзакции после bath_lock()). */
function bath_conflict(string $start, string $end, ?int $excludeId = null): ?array
{
    $buf = bath_settings()['buffer'] * 60;
    $in = "'" . implode("', '", BATH_OCCUPYING) . "'";
    return q_one("SELECT * FROM bath_bookings WHERE status IN ($in) AND id <> ? AND starts_at < ? AND ends_at > ? ORDER BY starts_at LIMIT 1",
        [$excludeId ?? 0, date('Y-m-d H:i:s', strtotime($end) + $buf), date('Y-m-d H:i:s', strtotime($start) - $buf)]);
}

/** Заблокировать баню до конца транзакции: пока одна запись проверяет время, вторая ждёт. */
function bath_lock(): void
{
    q("SELECT value FROM settings WHERE name = 'bath_lock' FOR UPDATE");
}

/** Текст о занятом времени: «занято 14:00–17:00 (запись №3)». */
function bath_conflict_text(array $x): string
{
    return 'занято ' . substr($x['starts_at'], 11, 5) . '–' . substr($x['ends_at'], 11, 5)
        . ($x['source'] === 'block' ? ' (время закрыто)' : ' (запись №' . $x['id'] . ')');
}

/**
 * Свободное время на дату: с какого часа можно начать и сколько часов
 * максимум. Для сайта — только будущее время с запасом на протопку.
 */
function bath_slots(string $date, bool $public = true, ?int $excludeId = null): array
{
    $s = bath_settings();
    $d = parse_date($date);
    $result = ['date' => $date, 'working_day' => false, 'slots' => [], 'busy' => []];
    if (!$d) {
        return $result;
    }
    $hrs = bath_hours_on($date);
    $result['working_day'] = $hrs !== null;
    $result['hours'] = $hrs ? bath_range($hrs) : null;
    $result['note'] = $hrs['note'] ?? (bath_special_on($date)['note'] ?? '');
    if (!$hrs) {
        return $result;
    }
    if ($public && ($date < today()->format('Y-m-d') || $date > today()->modify('+' . BATH_AHEAD_DAYS . ' days')->format('Y-m-d'))) {
        return $result;
    }
    $sessions = bath_day_sessions($date, $excludeId);
    foreach ($sessions as $x) {
        if (substr($x['starts_at'], 0, 10) === $date || substr($x['ends_at'], 0, 10) === $date) {
            $result['busy'][] = ['from' => substr($x['starts_at'], 11, 5), 'to' => substr($x['ends_at'], 11, 5)];
        }
    }
    $earliest = time() + $s['lead_hours'] * 3600;
    for ($h = $hrs['from']; $h <= 23 && $h + $s['min_hours'] <= $hrs['to']; $h++) {
        $start = $date . ' ' . bath_hour($h) . ':00';
        if ($public && strtotime($start) < $earliest) {
            continue;
        }
        $max = 0;
        for ($len = $s['min_hours']; $len <= $s['max_hours'] && $h + $len <= $hrs['to']; $len++) {
            if (bath_overlaps($start, date('Y-m-d H:i:s', strtotime($start) + $len * 3600), $sessions)) {
                break;
            }
            $max = $len;
        }
        if ($max >= $s['min_hours']) {
            $result['slots'][] = ['time' => bath_hour($h), 'max_hours' => $max];
        }
    }
    return $result;
}

/**
 * Проверить дату, время и длительность. Возвращает [начало, конец].
 * Сайт: только целый час, в часы работы, не раньше чем через lead_hours.
 * Администратор: любое время с шагом 30 минут, до 12 часов.
 */
function bath_validate_time(string $date, string $time, int $hours, bool $byStaff): array
{
    $s = bath_settings();
    $d = parse_date($date);
    if (!$d) {
        throw new BookingError('Выберите дату', 'date');
    }
    if (!preg_match($byStaff ? '/^([01]\d|2[0-3]):(00|30)$/' : '/^([01]\d|2[0-3]):00$/', $time)) {
        throw new BookingError('Выберите время начала', 'time');
    }
    $start = $date . ' ' . $time . ':00';
    if ($byStaff) {
        if ($hours < 1 || $hours > 12) {
            throw new BookingError('Длительность — от 1 до 12 часов', 'hours');
        }
    } else {
        if ($hours < $s['min_hours'] || $hours > $s['max_hours']) {
            throw new BookingError('Можно записаться на ' . $s['min_hours'] . '–' . $s['max_hours'] . ' ' . plural($s['max_hours'], 'час', 'часа', 'часов'), 'hours');
        }
        $hrs = bath_hours_on($date);
        if (!$hrs) {
            $note = bath_special_on($date)['note'] ?? '';
            throw new BookingError('В этот день баня не работает' . ($note !== '' ? ' (' . $note . ')' : '') . '. Расписание: ' . bath_hours_text(), 'date');
        }
        $h = (int) substr($time, 0, 2);
        if ($h < $hrs['from'] || $h + $hours > $hrs['to']) {
            throw new BookingError('В этот день баня работает ' . bath_range($hrs) . ' — выберите время внутри', 'time');
        }
        if (strtotime($start) < time() + $s['lead_hours'] * 3600) {
            throw new BookingError($s['lead_hours'] > 0 ? 'Баню нужно протопить — записывайтесь хотя бы за ' . $s['lead_hours'] . ' ' . plural($s['lead_hours'], 'час', 'часа', 'часов') . ', или позвоните нам' : 'Это время уже прошло', 'time');
        }
        if ($date > today()->modify('+' . BATH_AHEAD_DAYS . ' days')->format('Y-m-d')) {
            throw new BookingError('Записаться можно не дальше чем на ' . BATH_AHEAD_DAYS . ' дней вперёд', 'date');
        }
    }
    return [$start, date('Y-m-d H:i:s', strtotime($start) + $hours * 3600)];
}

// -------------------------------------------------------------------
// Записи: чтение
// -------------------------------------------------------------------

function bath_select_sql(): string
{
    return 'SELECT x.*, g.name AS guest_name, g.phone AS guest_phone, g.email AS guest_email,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.bath_booking_id = x.id AND p.voided_at IS NULL) AS paid
            FROM bath_bookings x LEFT JOIN guests g ON g.id = x.guest_id';
}

function bath_hydrate(?array $b): ?array
{
    if (!$b) {
        return null;
    }
    foreach (['id', 'guest_id', 'guests_count', 'hotel_guest', 'price_manual', 'paid'] as $k) {
        if ($b[$k] !== null) {
            $b[$k] = (int) $b[$k];
        }
    }
    $b['total_price'] = $b['total_price'] === null ? null : (int) $b['total_price'];
    $b['date'] = substr($b['starts_at'], 0, 10);
    $b['time_from'] = substr($b['starts_at'], 11, 5);
    $b['time_to'] = substr($b['ends_at'], 11, 5);
    $b['minutes'] = (int) ((strtotime($b['ends_at']) - strtotime($b['starts_at'])) / 60);
    $b['hours'] = (int) round($b['minutes'] / 60);
    $b['is_block'] = $b['source'] === 'block';
    $b['status_label'] = BATH_STATUSES[$b['status']] ?? $b['status'];
    $b['extras_list'] = bath_extras_decode($b['extras']);
    $b['balance'] = $b['total_price'] !== null ? $b['total_price'] - $b['paid'] : null;
    return $b;
}

function bath_get(int $id): ?array
{
    return bath_hydrate(q_one(bath_select_sql() . ' WHERE x.id = ?', [$id]));
}

function bath_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    return bath_hydrate(q_one(bath_select_sql() . ' WHERE x.token = ?', [$token]));
}

/** Записи, которые начинаются в [from, to) — даты ГГГГ-ММ-ДД. */
function bath_list(string $from, string $to, bool $withCancelled = false): array
{
    $where = 'x.starts_at >= ? AND x.starts_at < ?' . ($withCancelled ? '' : " AND x.status IN ('new', 'confirmed', 'done')");
    return array_map('bath_hydrate', q_all(bath_select_sql() . ' WHERE ' . $where . ' ORDER BY x.starts_at', [$from . ' 00:00:00', $to . ' 00:00:00']));
}

function bath_number(array $b): string
{
    return 'Баня №' . $b['id'];
}

function bath_url(array $b): string
{
    return base_url() . '/bath_booking.php?t=' . $b['token'];
}

/** «12 ноября, 14:00–17:00 (3 часа)». */
function bath_when(array $b): string
{
    return ru_date($b['date']) . ', ' . $b['time_from'] . '–' . $b['time_to'] . ' (' . bath_duration($b['minutes']) . ')';
}

function bath_duration(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return ($h ? $h . ' ' . plural($h, 'час', 'часа', 'часов') : '') . ($m ? ($h ? ' ' : '') . $m . ' мин' : '');
}

// -------------------------------------------------------------------
// Записи: создание и изменение
// -------------------------------------------------------------------

/**
 * Записать в баню. Поля: date, time (ЧЧ:ММ), hours, guests, name, phone,
 * email, contact_pref, hotel_guest, extras (из bath_extras_from_input),
 * comment; для администратора ещё status, source, price_manual, total_price,
 * admin_note.
 */
function bath_create(array $d, bool $byStaff = false): array
{
    $s = bath_settings();
    if (!$byStaff && !bath_is_open()) {
        throw new BookingError('Запись в баню пока не открыта — позвоните нам');
    }
    $hours = (int) ($d['hours'] ?? 0);
    [$start, $end] = bath_validate_time((string) ($d['date'] ?? ''), (string) ($d['time'] ?? ''), $hours, $byStaff);
    $guests = (int) ($d['guests'] ?? 1);
    if ($guests < 1 || $guests > ($byStaff ? 50 : $s['max_guests'])) {
        throw new BookingError('Гостей — от 1 до ' . $s['max_guests'], 'guests');
    }
    $name = clean_line((string) ($d['name'] ?? ''), 80);
    $phone = clean_line((string) ($d['phone'] ?? ''), 25);
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    if ($name === '') {
        throw new BookingError('Как к вам обращаться?', 'name');
    }
    $digits = normalize_phone($phone)[1];
    if (!$byStaff && (strlen($digits) < 10 || strlen($digits) > 15)) {
        throw new BookingError('Проверьте номер телефона', 'phone');
    }
    if ($byStaff && $digits === '' && $email === '') {
        throw new BookingError('Нужен телефон или почта гостя', 'phone');
    }
    if (!valid_email($email)) {
        throw new BookingError('Проверьте адрес почты', 'email');
    }
    $status = $byStaff ? (string) ($d['status'] ?? 'confirmed') : 'new';
    if (!in_array($status, ['new', 'confirmed', 'done'], true)) {
        throw new BookingError('Неизвестный статус', 'status');
    }
    $hotelGuest = !empty($d['hotel_guest']);
    $extras = $d['extras'] ?? [];
    $quote = bath_quote(substr($start, 0, 10), $hours, $guests, $hotelGuest, $extras);
    $manual = $byStaff && !empty($d['price_manual']);
    $total = $manual ? max(0, (int) preg_replace('/\D+/', '', (string) ($d['total_price'] ?? '0'))) : $quote['total'];
    $source = $byStaff ? (isset(BOOKING_SOURCES[$d['source'] ?? '']) ? $d['source'] : 'phone') : 'site';
    $contact = isset(CONTACT_PREFS[$d['contact_pref'] ?? '']) ? $d['contact_pref'] : 'call';

    $id = tx(function () use ($start, $end, $byStaff, $name, $phone, $email, $guests, $status, $hotelGuest, $extras, $total, $manual, $source, $contact, $d) {
        bath_lock();
        if ($x = bath_conflict($start, $end)) {
            throw new BookingError('Это время уже занято — выберите другое (' . bath_conflict_text($x) . ')', 'time');
        }
        $guestId = guest_upsert($name, $phone, $email, $byStaff);
        $id = q_insert('bath_bookings', [
            'token'         => bin2hex(random_bytes(20)),
            'guest_id'      => $guestId,
            'starts_at'     => $start,
            'ends_at'       => $end,
            'guests_count'  => $guests,
            'status'        => $status,
            'source'        => $source,
            'contact_pref'  => $contact,
            'hotel_guest'   => $hotelGuest ? 1 : 0,
            'extras'        => bath_extras_encode($extras),
            'total_price'   => $total,
            'price_manual'  => $manual ? 1 : 0,
            'guest_comment' => mb_substr(trim((string) ($d['comment'] ?? '')), 0, 1000) ?: null,
            'admin_note'    => $byStaff ? (mb_substr(trim((string) ($d['admin_note'] ?? '')), 0, 2000) ?: null) : null,
            'created_by'    => $byStaff ? (current_staff()['id'] ?? null) : null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        audit('bath', $id, 'created', ru_date(substr($start, 0, 10)) . ', ' . substr($start, 11, 5) . '–' . substr($end, 11, 5) . ', ' . $guests . ' ' . plural($guests, 'гость', 'гостя', 'гостей'), $byStaff ? 'staff' : 'guest');
        return $id;
    });
    touch_data_version();
    cleaning_sync(substr($end, 0, 10));
    return bath_get($id);
}

/** Закрыть время (ремонт, частное мероприятие, санитарный день) — только сотрудник. */
function bath_block(string $date, string $from, string $to, string $reason): int
{
    if (!parse_date($date)) {
        throw new BookingError('Выберите дату', 'date');
    }
    $re = '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/';
    if (!preg_match($re, $from) || !preg_match($re, $to) || $to <= $from) {
        throw new BookingError('Укажите время: «с» раньше, чем «до»', 'time');
    }
    $start = $date . ' ' . $from . ':00';
    $end = $to === '24:00' ? parse_date($date)->modify('+1 day')->format('Y-m-d') . ' 00:00:00' : $date . ' ' . $to . ':00';
    $reason = clean_line($reason, 200);
    $id = tx(function () use ($start, $end, $reason) {
        bath_lock();
        $in = "'" . implode("', '", BATH_OCCUPYING) . "'";
        // Перерыв здесь не нужен: закрываем ровно указанное время.
        if ($x = q_one("SELECT * FROM bath_bookings WHERE status IN ($in) AND starts_at < ? AND ends_at > ? LIMIT 1", [$end, $start])) {
            throw new BookingError('На это время уже есть запись: ' . bath_conflict_text($x) . '. Сначала перенесите или отмените её');
        }
        $id = q_insert('bath_bookings', [
            'token' => bin2hex(random_bytes(20)), 'guest_id' => null, 'starts_at' => $start, 'ends_at' => $end,
            'guests_count' => 0, 'status' => 'confirmed', 'source' => 'block', 'total_price' => null,
            'admin_note' => $reason !== '' ? $reason : null,
            'created_by' => current_staff()['id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        audit('bath', $id, 'blocked', ru_date(substr($start, 0, 10)) . ', ' . substr($start, 11, 5) . '–' . substr($end, 11, 5) . ($reason !== '' ? ' — ' . $reason : ''));
        return $id;
    });
    touch_data_version();
    return $id;
}

/** Изменить запись (администратор): время, гостей, услуги, цену, контакты, заметку. */
function bath_update(int $id, array $d): array
{
    $old = bath_get($id);
    if (!$old || $old['is_block']) {
        throw new BookingError('Запись не найдена');
    }
    $hours = (int) ($d['hours'] ?? $old['hours']);
    [$start, $end] = bath_validate_time((string) ($d['date'] ?? $old['date']), (string) ($d['time'] ?? $old['time_from']), $hours, true);
    $guests = max(1, min(50, (int) ($d['guests'] ?? $old['guests_count'])));
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    if (!valid_email($email)) {
        throw new BookingError('Проверьте адрес почты', 'email');
    }
    if (trim((string) ($d['name'] ?? '')) === '') {
        throw new BookingError('Укажите имя гостя', 'name');
    }
    $hotelGuest = !empty($d['hotel_guest']);
    $extras = $d['extras'] ?? $old['extras_list'];
    $manual = !empty($d['price_manual']);
    $sameSession = $old['starts_at'] === $start && $old['ends_at'] === $end && $old['guests_count'] === $guests
        && (bool) $old['hotel_guest'] === $hotelGuest && $old['extras_list'] == $extras;
    if ($manual) {
        $total = max(0, (int) preg_replace('/\D+/', '', (string) ($d['total_price'] ?? '0')));
    } elseif ($sameSession && !$old['price_manual']) {
        $total = $old['total_price']; // сумму, которую уже назвали гостю, не пересчитываем из-за новых цен
    } else {
        $total = bath_quote(substr($start, 0, 10), $hours, $guests, $hotelGuest, $extras)['total'];
    }

    tx(function () use ($old, $id, $d, $start, $end, $guests, $email, $hotelGuest, $extras, $manual, $total) {
        bath_lock();
        if (in_array($old['status'], BATH_OCCUPYING, true) && ($x = bath_conflict($start, $end, $id))) {
            throw new BookingError('Это время занято: ' . bath_conflict_text($x), 'time');
        }
        if ($old['guest_id']) {
            guest_update($old['guest_id'], ['name' => trim($d['name']), 'phone' => (string) ($d['phone'] ?? ''), 'email' => $email,
                'note' => q_val('SELECT note FROM guests WHERE id = ?', [$old['guest_id']])]);
            $guestId = $old['guest_id'];
        } else {
            $guestId = guest_upsert(clean_line((string) $d['name'], 80), (string) ($d['phone'] ?? ''), $email);
        }
        q_update('bath_bookings', $id, [
            'guest_id' => $guestId, 'starts_at' => $start, 'ends_at' => $end, 'guests_count' => $guests,
            'hotel_guest' => $hotelGuest ? 1 : 0, 'extras' => bath_extras_encode($extras),
            'total_price' => $total, 'price_manual' => $manual ? 1 : 0,
            'contact_pref' => isset(CONTACT_PREFS[$d['contact_pref'] ?? '']) ? $d['contact_pref'] : $old['contact_pref'],
            'guest_comment' => mb_substr(trim((string) ($d['comment'] ?? '')), 0, 1000) ?: null,
            'admin_note' => mb_substr(trim((string) ($d['admin_note'] ?? '')), 0, 2000) ?: null,
            'updated_at' => now(),
        ]);
        $changes = [];
        if ($old['starts_at'] !== $start || $old['ends_at'] !== $end) {
            $changes[] = 'время: ' . ru_date($old['date']) . ' ' . $old['time_from'] . '–' . $old['time_to'] . ' → ' . ru_date(substr($start, 0, 10)) . ' ' . substr($start, 11, 5) . '–' . substr($end, 11, 5);
        }
        if ($old['total_price'] !== $total) {
            $changes[] = 'сумма: ' . ($old['total_price'] === null ? '—' : money($old['total_price'])) . ' → ' . money((int) $total);
        }
        audit('bath', $id, 'updated', $changes ? implode('; ', $changes) : 'изменены данные');
    });
    touch_data_version();
    cleaning_sync($old['date']);
    cleaning_sync(substr($end, 0, 10));
    return bath_get($id);
}

function bath_set_status(int $id, string $status, string $reason = ''): array
{
    if (!isset(BATH_STATUSES[$status])) {
        throw new BookingError('Неизвестный статус');
    }
    tx(function () use ($id, $status, $reason) {
        bath_lock();
        $b = bath_get($id);
        if (!$b) {
            throw new BookingError('Запись не найдена');
        }
        if (!in_array($b['status'], BATH_OCCUPYING, true) && in_array($status, BATH_OCCUPYING, true)
            && ($x = bath_conflict($b['starts_at'], $b['ends_at'], $id))) {
            throw new BookingError('Вернуть запись нельзя — время уже занято: ' . bath_conflict_text($x));
        }
        $row = ['status' => $status, 'updated_at' => now()];
        $details = BATH_STATUSES[$b['status']] . ' → ' . BATH_STATUSES[$status];
        if ($status === 'cancelled') {
            $row['cancel_reason'] = clean_line($reason, 255);
            if ($row['cancel_reason'] !== '') {
                $details .= ' (' . $row['cancel_reason'] . ')';
            }
        }
        q_update('bath_bookings', $id, $row);
        audit('bath', $id, 'status', $details);
    });
    touch_data_version();
    $b = bath_get($id);
    cleaning_sync(substr($b['ends_at'], 0, 10));
    return $b;
}

/** Открыть закрытое время (удалить «закрыто») — только для записей-блокировок. */
function bath_unblock(int $id): void
{
    $b = bath_get($id);
    if (!$b || !$b['is_block']) {
        throw new BookingError('Это не закрытое время');
    }
    q('DELETE FROM bath_bookings WHERE id = ?', [$id]);
    audit('bath', $id, 'unblocked', ru_date($b['date']) . ', ' . $b['time_from'] . '–' . $b['time_to']);
    touch_data_version();
}

/** Будущие записи, которые не попадают во время работы, — после смены расписания. */
function bath_sessions_outside_hours(): array
{
    $out = [];
    foreach (bath_list(today()->format('Y-m-d'), today()->modify('+' . BATH_AHEAD_DAYS . ' days')->format('Y-m-d')) as $b) {
        if ($b['is_block'] || $b['status'] === 'done' || $b['starts_at'] <= now()) {
            continue;
        }
        $hrs = bath_hours_on($b['date']);
        $day = strtotime($b['date'] . ' 00:00:00');
        if (!$hrs || strtotime($b['starts_at']) < $day + $hrs['from'] * 3600 || strtotime($b['ends_at']) > $day + $hrs['to'] * 3600) {
            $out[] = $b;
        }
    }
    return $out;
}

/** Сколько новых заявок в баню ждут ответа — для меню. */
function bath_new_count(): int
{
    return bath_enabled() ? (int) q_val("SELECT COUNT(*) FROM bath_bookings WHERE status = 'new'") : 0;
}
