<?php
/**
 * Номера и цены: типы номеров с фото, цена каждой ночи с учётом сезонных
 * правил, скидка за длительное проживание и итоговый расчёт.
 *
 * Цена ночи: базовая цена типа номера, если на эту дату нет правила.
 * Правило с конкретной ценой для типа сильнее правила-процента; из
 * нескольких одинаковых правил действует добавленное последним.
 * Скидка за длительное проживание применяется к сумме за весь срок.
 */

/** Типы номеров (id => тип) с фото и удобствами списком. */
function room_types(bool $activeOnly = true): array
{
    $cache = &app_cache();
    $key = 'room_types_' . ($activeOnly ? 'active' : 'all');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $rows = q_all('SELECT * FROM room_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort, base_price, id');
    $photos = [];
    foreach (q_all('SELECT * FROM room_type_photos ORDER BY sort, id') as $p) {
        $photos[(int) $p['room_type_id']][] = $p;
    }
    $types = [];
    foreach ($rows as $r) {
        $r['id'] = (int) $r['id'];
        $r['capacity'] = (int) $r['capacity'];
        $r['base_price'] = (int) $r['base_price'];
        $r['features_list'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $r['features']))));
        $r['photos'] = $photos[$r['id']] ?? [];
        $types[$r['id']] = $r;
    }
    return $cache[$key] = $types;
}

function room_type(int $id): ?array
{
    return room_types(false)[$id] ?? null;
}

/** «2 500 ₽» или «[5 000] ₽», если цена ещё не утверждена. */
function type_price_label(array $type, ?int $price = null): string
{
    $sum = number_format($price ?? $type['base_price'], 0, ',', "\u{00A0}");
    return !empty($type['price_tbd']) ? '[' . $sum . "]\u{00A0}₽" : $sum . "\u{00A0}₽";
}

/** Скидки за длительное проживание по возрастанию срока. */
function stay_discounts(): array
{
    $cache = &app_cache();
    if (!isset($cache['stay_discounts'])) {
        $cache['stay_discounts'] = array_map(function ($d) {
            $d['min_nights'] = (int) $d['min_nights'];
            $d['percent'] = $d['percent'] === null ? null : (int) $d['percent'];
            // Короткий текст для таблицы цен: «скидка 10%» или «[условия]».
            $d['text'] = $d['percent'] !== null ? 'скидка ' . $d['percent'] . '%' : ($d['note'] !== '' ? $d['note'] : 'по договорённости');
            return $d;
        }, q_all('SELECT * FROM stay_discounts ORDER BY min_nights'));
    }
    return $cache['stay_discounts'];
}

/** Ступень скидки, которая действует при таком числе ночей, или null. */
function stay_tier(int $nights): ?array
{
    $found = null;
    foreach (stay_discounts() as $d) {
        if ($nights >= $d['min_nights']) {
            $found = $d;
        }
    }
    return $found;
}

/** Следующая ступень скидки — для подсказки «ещё N ночей, и будет дешевле». */
function next_stay_tier(int $nights): ?array
{
    foreach (stay_discounts() as $d) {
        if ($d['min_nights'] > $nights) {
            return $d;
        }
    }
    return null;
}

function nights_between(string $checkIn, string $checkOut): int
{
    $a = parse_date($checkIn);
    $b = parse_date($checkOut);
    return ($a && $b && $b > $a) ? (int) $a->diff($b)->days : 0;
}

/** Цены каждой ночи: ['2026-11-05' => 2500, ...]. */
function night_prices(array $type, string $checkIn, string $checkOut): array
{
    $rules = q_all(
        'SELECT * FROM price_rules WHERE date_from < ? AND date_to >= ? AND (room_type_id = ? OR room_type_id IS NULL) ORDER BY id',
        [$checkOut, $checkIn, $type['id']]
    );
    $prices = [];
    $d = parse_date($checkIn);
    $end = parse_date($checkOut);
    while ($d < $end) {
        $date = $d->format('Y-m-d');
        $price = $type['base_price'];
        $fixed = null;
        $percent = null;
        foreach ($rules as $r) {
            if ($r['date_from'] > $date || $r['date_to'] < $date) {
                continue;
            }
            if ($r['price'] !== null && (int) $r['room_type_id'] === $type['id']) {
                $fixed = (int) $r['price'];
            } elseif ($r['percent'] !== null) {
                $percent = (int) $r['percent'];
            }
        }
        if ($fixed !== null) {
            $price = $fixed;
        } elseif ($percent !== null) {
            $price = (int) round($type['base_price'] * (100 + $percent) / 100);
        }
        $prices[$date] = $price;
        $d = $d->modify('+1 day');
    }
    return $prices;
}

/**
 * Расчёт стоимости проживания.
 * total = null, если для такого срока цена обсуждается индивидуально.
 */
function quote(array $type, string $checkIn, string $checkOut): array
{
    $prices = night_prices($type, $checkIn, $checkOut);
    $nights = count($prices);
    $sum = array_sum($prices);
    $tier = stay_tier($nights);
    $discount = 0;
    $total = $sum;
    if ($tier !== null && $tier['percent'] === null) {
        $total = null;
    } elseif ($tier !== null) {
        $discount = (int) round($sum * $tier['percent'] / 100);
        $total = $sum - $discount;
    }
    $seasonal = false;
    foreach ($prices as $p) {
        if ($p !== $type['base_price']) {
            $seasonal = true;
            break;
        }
    }
    return [
        'nights'    => $nights,
        'prices'    => $prices,
        'sum'       => $sum,
        'tier'      => $tier,
        'discount'  => $discount,
        'total'     => $total,
        'avg'       => $nights ? (int) round($sum / $nights) : 0,
        'seasonal'  => $seasonal,
        'next_tier' => next_stay_tier($nights),
    ];
}

/** Пример для главной: N ночей по базовой цене со скидкой. */
function simple_estimate(array $type, int $nights): array
{
    $sum = $type['base_price'] * $nights;
    $tier = stay_tier($nights);
    $total = ($tier && $tier['percent'] !== null) ? $sum - (int) round($sum * $tier['percent'] / 100) : $sum;
    return ['sum' => $sum, 'total' => $total, 'tier' => $tier];
}

/** Предоплата по правилу из настроек (Цены → Предоплата). */
function default_prepayment(array $quote): int
{
    $mode = setting('prepayment_mode', 'first_night');
    $total = $quote['total'] ?? $quote['sum'];
    if ($mode === 'none' || !$quote['nights']) {
        return 0;
    }
    if (strpos($mode, 'percent:') === 0) {
        return (int) round($total * max(0, min(100, (int) substr($mode, 8))) / 100);
    }
    // Первая ночь — с учётом скидки, если она есть.
    $first = (int) reset($quote['prices']);
    if ($quote['tier'] && $quote['tier']['percent'] !== null) {
        $first -= (int) round($first * $quote['tier']['percent'] / 100);
    }
    return min($first, $total);
}

function prepayment_mode_label(?string $mode = null): string
{
    $mode = $mode ?? setting('prepayment_mode', 'first_night');
    if ($mode === 'none') {
        return 'без предоплаты';
    }
    if (strpos($mode, 'percent:') === 0) {
        return (int) substr($mode, 8) . '% от стоимости';
    }
    return 'стоимость первой ночи';
}
