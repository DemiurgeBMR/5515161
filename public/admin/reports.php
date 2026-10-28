<?php
/**
 * Отчёты: загрузка, выручка и оплаты по месяцам, разбивка по номерам и
 * источникам, выгрузка броней и платежей в Excel (CSV).
 *
 * Выручка — начисленная: стоимость брони делится по ночам, поэтому бронь
 * с 28 октября по 3 ноября попадает в оба месяца. Оплаты — по дате оплаты.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

const SOLD_STATUSES = ['confirmed', 'checked_in', 'checked_out'];

/** Сколько ночей периода [from, to) приходится на [a, b). */
function overlap_nights(string $a, string $b, string $from, string $to): int
{
    $s = max($a, $from);
    $e = min($b, $to);
    return $e > $s ? nights_between($s, $e) : 0;
}

/** Цифры за период [from, to). */
function report_period(string $from, string $to): array
{
    $days = nights_between($from, $to);
    $rooms = rooms_list(true);
    $r = [
        'days' => $days, 'available' => 0, 'sold' => 0, 'tentative' => 0, 'platform' => 0, 'repair' => 0,
        'revenue' => 0, 'priced_nights' => 0, 'no_price' => 0, 'arrivals' => 0, 'guests' => 0, 'stay_nights' => 0,
        'cancelled' => 0, 'payments' => 0, 'by_type' => [], 'by_source' => [], 'by_method' => [],
    ];
    foreach ($rooms as $room) {
        $t = (int) $room['room_type_id'];
        $r['by_type'][$t] = $r['by_type'][$t] ?? ['available' => 0, 'sold' => 0, 'revenue' => 0];
        $r['by_type'][$t]['available'] += $days;
        $r['available'] += $days;
    }
    foreach (q_all('SELECT c.*, r.room_type_id FROM room_closures c JOIN rooms r ON r.id = c.room_id WHERE r.is_active = 1 AND c.date_from < ? AND c.date_to > ?', [$to, $from]) as $c) {
        $n = overlap_nights($c['date_from'], $c['date_to'], $from, $to);
        if ($c['source'] === 'ical') {
            $r['platform'] += $n;
        } else {
            // Ремонт и другие закрытия — номер нельзя было продать, из загрузки не считаем.
            $r['repair'] += $n;
            $r['available'] -= $n;
            $r['by_type'][(int) $c['room_type_id']]['available'] -= $n;
        }
    }
    $bookings = q_all('SELECT id, room_type_id, check_in, check_out, status, source, total_price, guests_count FROM bookings WHERE check_in < ? AND check_out > ?', [$to, $from]);
    foreach ($bookings as $b) {
        $n = overlap_nights($b['check_in'], $b['check_out'], $from, $to);
        $arrives = $b['check_in'] >= $from && $b['check_in'] < $to;
        if (in_array($b['status'], ['cancelled', 'no_show'], true)) {
            $r['cancelled'] += $arrives ? 1 : 0;
            continue;
        }
        if ($b['status'] === 'new') {
            $r['tentative'] += $n;
            continue;
        }
        $t = (int) $b['room_type_id'];
        $total = nights_between($b['check_in'], $b['check_out']);
        $money = $b['total_price'] !== null && $total ? (int) round($b['total_price'] * $n / $total) : 0;
        $r['sold'] += $n;
        $r['revenue'] += $money;
        if ($b['total_price'] !== null) {
            $r['priced_nights'] += $n;
        } else {
            $r['no_price']++;
        }
        $r['by_type'][$t] = $r['by_type'][$t] ?? ['available' => 0, 'sold' => 0, 'revenue' => 0];
        $r['by_type'][$t]['sold'] += $n;
        $r['by_type'][$t]['revenue'] += $money;
        $s = $b['source'];
        $r['by_source'][$s] = $r['by_source'][$s] ?? ['bookings' => 0, 'nights' => 0, 'revenue' => 0];
        $r['by_source'][$s]['nights'] += $n;
        $r['by_source'][$s]['revenue'] += $money;
        if ($arrives) {
            $r['arrivals']++;
            $r['guests'] += (int) $b['guests_count'];
            $r['stay_nights'] += $total;
            $r['by_source'][$s]['bookings']++;
        }
    }
    foreach (q_all('SELECT method, SUM(amount) AS s FROM payments WHERE paid_on >= ? AND paid_on < ? GROUP BY method', [$from, $to]) as $p) {
        $r['by_method'][$p['method']] = (int) $p['s'];
        $r['payments'] += (int) $p['s'];
    }
    $r['occupancy'] = $r['available'] > 0 ? (int) round($r['sold'] * 100 / $r['available']) : 0;
    $r['adr'] = $r['priced_nights'] ? (int) round($r['revenue'] / $r['priced_nights']) : 0;
    return $r;
}

/** Готовая ячейка CSV, которую не нужно экранировать. */
final class CsvRaw
{
    public function __construct(public string $value)
    {
    }
}

/** Ячейка CSV: текст, который Excel мог бы принять за формулу, экранируем. */
function csv_cell($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_int($v)) {
        return (string) $v;
    }
    $v = (string) $v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/**
 * Телефон для Excel: «+7 978…» он принял бы за формулу и испортил. Номер из
 * одних цифр, пробелов и скобок отдаём как текст ="…", остальное — как обычно.
 */
function csv_phone(string $phone): string
{
    if ($phone === '') {
        return '';
    }
    return preg_match('/^\+?[\d\s()-]+$/', $phone) ? '="' . $phone . '"' : csv_cell($phone);
}

function csv_send(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // BOM и точка с запятой — так русский Excel открывает файл сразу, без мастера импорта.
    echo "\xEF\xBB\xBF" . implode(';', array_map('csv_cell', $header)) . "\r\n";
    foreach ($rows as $row) {
        echo implode(';', array_map(fn ($v) => $v instanceof CsvRaw ? $v->value : csv_cell($v), $row)) . "\r\n";
    }
    exit;
}

$month = preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', get('month'), $m) ? get('month') : today()->format('Y-m');
$start = new DateTimeImmutable($month . '-01', new DateTimeZone(HOTEL_TZ));
$from = $start->format('Y-m-d');
$to = $start->modify('+1 month')->format('Y-m-d');

// Выгрузка: брони, которые пересекаются с периодом, или платежи за период.
$export = get('export');
if ($export === 'bookings' || $export === 'payments') {
    $eFrom = date_value(get('from')) ?: $from;
    $eTo = date_value(get('to')) ?: $start->modify('last day of this month')->format('Y-m-d');
    $eToExcl = (new DateTimeImmutable($eTo))->modify('+1 day')->format('Y-m-d');
    audit('settings', null, 'export', ($export === 'bookings' ? 'брони' : 'платежи') . ' ' . ru_date_range($eFrom, $eToExcl));
    if ($export === 'bookings') {
        $rows = [];
        foreach (array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE b.check_in < ? AND b.check_out > ? ORDER BY b.check_in, b.id', [$eToExcl, $eFrom])) as $b) {
            $rows[] = [
                (int) $b['id'], $b['status_label'], $b['check_in'], $b['check_out'], $b['nights'],
                $b['room_name'] ?? '', $b['type_name'], $b['guest_name'] ?? '', new CsvRaw(csv_phone((string) $b['guest_phone'])), $b['guest_email'] ?? '',
                $b['guests_count'], BOOKING_SOURCES[$b['source']] ?? $b['source'],
                $b['total_price'], $b['prepayment'], $b['paid'], $b['balance'],
                substr($b['created_at'], 0, 16), (string) $b['guest_comment'], (string) $b['admin_note'],
            ];
        }
        csv_send('bronirovaniya_' . $eFrom . '_' . $eTo . '.csv',
            ['№', 'Статус', 'Заезд', 'Выезд', 'Ночей', 'Комната', 'Тип номера', 'Гость', 'Телефон', 'Почта', 'Гостей', 'Источник', 'Сумма', 'Предоплата', 'Оплачено', 'Остаток', 'Создана', 'Комментарий гостя', 'Заметка'],
            $rows);
    }
    $rows = [];
    foreach (q_all('SELECT p.*, g.name AS guest_name, s.name AS staff_name FROM payments p JOIN bookings b ON b.id = p.booking_id
                    LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN staff s ON s.id = p.created_by
                    WHERE p.paid_on >= ? AND p.paid_on < ? ORDER BY p.paid_on, p.id', [$eFrom, $eToExcl]) as $p) {
        $rows[] = [$p['paid_on'], (int) $p['amount'], PAYMENT_METHODS[$p['method']] ?? $p['method'], (int) $p['booking_id'], $p['guest_name'] ?? '', $p['note'], $p['staff_name'] ?? ''];
    }
    csv_send('oplaty_' . $eFrom . '_' . $eTo . '.csv', ['Дата', 'Сумма', 'Способ', 'Бронь №', 'Гость', 'Заметка', 'Записал'], $rows);
}

$r = report_period($from, $to);
$year = (int) $start->format('Y');
$yearRows = [];
for ($i = 1; $i <= 12; $i++) {
    $ms = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $i), new DateTimeZone(HOTEL_TZ));
    $yearRows[$i] = report_period($ms->format('Y-m-d'), $ms->modify('+1 month')->format('Y-m-d'));
}
$types = room_types(false);
$monthName = RU_MONTHS[(int) $start->format('n')] . ' ' . $year;
$prev = $start->modify('-1 month')->format('Y-m');
$next = $start->modify('+1 month')->format('Y-m');

admin_header('Отчёты', 'reports');
?>
<div class="adm-head">
    <div>
        <h1>Отчёты</h1>
        <p class="adm-head__sub">Выручка делится по ночам: бронь на стыке месяцев попадает в оба. Учитываются подтверждённые брони, проживающие и выехавшие.</p>
    </div>
    <nav class="tabs" aria-label="Месяц">
        <a class="tab" href="/admin/reports.php?month=<?= $prev ?>" aria-label="Предыдущий месяц">←</a>
        <span class="tab is-active"><?= e($monthName) ?></span>
        <a class="tab" href="/admin/reports.php?month=<?= $next ?>" aria-label="Следующий месяц">→</a>
    </nav>
</div>

<div class="kpis">
    <div class="kpi kpi--accent"><span>Загрузка</span><b><?= $r['occupancy'] ?>%</b><small><?= $r['sold'] ?> из <?= $r['available'] ?> ночей</small></div>
    <div class="kpi"><span>Выручка</span><b><?= e(money($r['revenue'])) ?></b><small><?= $r['no_price'] ? 'ещё ' . $r['no_price'] . ' ' . plural($r['no_price'], 'бронь', 'брони', 'броней') . ' без суммы' : 'начислено за месяц' ?></small></div>
    <div class="kpi"><span>Средняя цена ночи</span><b><?= $r['adr'] ? e(money($r['adr'])) : '—' ?></b><small>с учётом скидок</small></div>
    <div class="kpi"><span>Поступило оплат</span><b><?= e(money($r['payments'])) ?></b><small>по дате оплаты</small></div>
    <div class="kpi"><span>Заездов</span><b><?= $r['arrivals'] ?></b><small><?= $r['arrivals'] ? 'в среднем ' . e(nights_label((int) round($r['stay_nights'] / $r['arrivals']))) : 'гостей: 0' ?></small></div>
    <div class="kpi"><span>Отмены</span><b><?= $r['cancelled'] ?></b><small>отменённые и неприезды</small></div>
</div>
<?php if ($r['tentative'] || $r['platform'] || $r['repair']): ?>
<p class="hint">
    <?= $r['tentative'] ? 'Неподтверждённые заявки: ' . e(nights_label($r['tentative'])) . ' — в загрузку не вошли. ' : '' ?>
    <?= $r['platform'] ? 'Занято по календарям площадок: ' . e(nights_label($r['platform'])) . ' — суммы этих броней сайт не знает. ' : '' ?>
    <?= $r['repair'] ? 'Закрыто на ремонт и по другим причинам: ' . e(nights_label($r['repair'])) . ' — не считаются доступными.' : '' ?>
</p>
<?php endif; ?>

<div class="two-col">
<section class="panel">
    <h2 class="panel__title">По номерам</h2>
    <table class="table">
        <thead><tr><th>Номер</th><th class="num">Ночей</th><th class="num">Загрузка</th><th class="num">Выручка</th></tr></thead>
        <tbody>
<?php foreach ($r['by_type'] as $tid => $row): ?>
            <tr>
                <td><?= e($types[$tid]['name'] ?? '—') ?></td>
                <td class="num"><?= $row['sold'] ?></td>
                <td class="num"><?= $row['available'] > 0 ? (int) round($row['sold'] * 100 / $row['available']) . '%' : '—' ?></td>
                <td class="num"><?= e(money($row['revenue'])) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2 class="panel__title">Откуда брони</h2>
<?php if (!$r['by_source']): ?>
    <p class="empty">В этом месяце броней нет.</p>
<?php else: arsort($r['by_source']); ?>
    <table class="table">
        <thead><tr><th>Источник</th><th class="num">Заездов</th><th class="num">Ночей</th><th class="num">Выручка</th></tr></thead>
        <tbody>
<?php foreach ($r['by_source'] as $src => $row): ?>
            <tr><td><?= e(BOOKING_SOURCES[$src] ?? $src) ?></td><td class="num"><?= $row['bookings'] ?></td><td class="num"><?= $row['nights'] ?></td><td class="num"><?= e(money($row['revenue'])) ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php if ($r['by_method']): ?>
    <h3 class="panel__subtitle">Оплаты по способам</h3>
    <table class="table">
        <tbody>
<?php foreach ($r['by_method'] as $method => $sum): ?>
            <tr><td><?= e(PAYMENT_METHODS[$method] ?? $method) ?></td><td class="num"><?= e(money($sum)) ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
</div>

<section class="panel">
    <h2 class="panel__title"><?= $year ?> год по месяцам</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Месяц</th><th class="num">Загрузка</th><th class="num">Ночей</th><th class="num">Заездов</th><th class="num">Выручка</th><th class="num">Оплаты</th></tr></thead>
            <tbody>
<?php $sum = ['sold' => 0, 'available' => 0, 'arrivals' => 0, 'revenue' => 0, 'payments' => 0];
foreach ($yearRows as $i => $row):
    foreach ($sum as $k => $_) {
        $sum[$k] += $row[$k];
    }
    $key = sprintf('%d-%02d', $year, $i); ?>
                <tr class="<?= $key === $month ? 'is-current' : '' ?>">
                    <td><a href="/admin/reports.php?month=<?= $key ?>"><?= e(RU_MONTHS[$i]) ?></a></td>
                    <td class="num"><span class="bar" style="--p: <?= min(100, $row['occupancy']) ?>%"></span><?= $row['occupancy'] ?>%</td>
                    <td class="num"><?= $row['sold'] ?></td>
                    <td class="num"><?= $row['arrivals'] ?></td>
                    <td class="num"><?= e(money($row['revenue'])) ?></td>
                    <td class="num"><?= e(money($row['payments'])) ?></td>
                </tr>
<?php endforeach; ?>
                <tr class="is-total">
                    <td>Итого</td>
                    <td class="num"><?= $sum['available'] ? (int) round($sum['sold'] * 100 / $sum['available']) : 0 ?>%</td>
                    <td class="num"><?= $sum['sold'] ?></td>
                    <td class="num"><?= $sum['arrivals'] ?></td>
                    <td class="num"><?= e(money($sum['revenue'])) ?></td>
                    <td class="num"><?= e(money($sum['payments'])) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="export">
    <h2 class="panel__title">Выгрузка в Excel</h2>
    <form method="get" class="form-grid">
        <div class="field"><label for="e-what">Что выгрузить</label><select id="e-what" name="export">
            <option value="bookings">Брони (все, кто жил или будет жить в эти даты)</option>
            <option value="payments">Платежи за эти даты</option>
        </select></div>
        <div class="field"><label for="e-from">С</label><input id="e-from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label for="e-to">По</label><input id="e-to" type="date" name="to" value="<?= e($start->modify('last day of this month')->format('Y-m-d')) ?>"></div>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('download') ?>Скачать</button></div>
    </form>
    <p class="hint">Файл CSV открывается в Excel и Google Таблицах. В нём есть телефоны и почта гостей — не пересылайте его посторонним.</p>
</section>
<?php admin_footer(); ?>
