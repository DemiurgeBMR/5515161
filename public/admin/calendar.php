<?php
/**
 * Шахматка: строки — комнаты, столбцы — ночи. Полоска брони идёт от
 * середины дня заезда до середины дня выезда — так видно, что выезд в
 * 12:00 и заезд в 13:00 в один день не мешают друг другу.
 * Клик по пустой клетке — новая бронь в эту комнату с этой даты.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

// Закрыть или открыть комнату
if (is_post()) {
    csrf_check();
    try {
        if (post('action') === 'close') {
            closure_add((int) post('room_id'), post('date_from'), post('date_to'), post('reason'));
            flash('ok', 'Комната закрыта на ' . ru_date_range(post('date_from'), post('date_to')) . '.');
        } elseif (post('action') === 'open') {
            closure_delete((int) post('closure_id'));
            flash('ok', 'Комната снова продаётся.');
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/calendar.php?' . http_build_query(['start' => post('start'), 'days' => post('days')]));
}

$days = in_array((int) get('days', '21'), [14, 21, 31, 62], true) ? (int) get('days', '21') : 21;
$start = parse_date(get('start')) ?? today()->modify('-2 days');
$end = $start->modify('+' . $days . ' days');
$from = $start->format('Y-m-d');
$to = $end->format('Y-m-d');
$todayStr = today()->format('Y-m-d');
$dates = nights_list($from, $to);

$rooms = rooms_list(true);
$byType = [];
foreach ($rooms as $r) {
    $byType[(int) $r['room_type_id']][] = $r;
}
$types = room_types(false);

// Что стоит в комнатах: брони (кроме отменённых) и закрытия.
$items = [];
foreach (q_all("SELECT b.id, b.room_id, b.check_in AS a, b.check_out AS z, b.status, b.guests_count, g.name AS guest_name
                FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id
                WHERE b.room_id IS NOT NULL AND b.status NOT IN ('cancelled', 'no_show') AND b.check_in < ? AND b.check_out > ?", [$to, $from]) as $b) {
    $items[(int) $b['room_id']][] = ['kind' => 'booking'] + $b;
}
$closures = q_all('SELECT c.*, c.date_from AS a, c.date_to AS z, r.name AS room_name FROM room_closures c JOIN rooms r ON r.id = c.room_id WHERE c.date_from < ? AND c.date_to > ? ORDER BY c.date_from', [$to, $from]);
foreach ($closures as $c) {
    $items[(int) $c['room_id']][] = ['kind' => $c['source'] === 'ical' ? 'ical' : 'closed'] + $c;
}
// Наложения в одной комнате (бронь поверх брони или поверх закрытия) — подсветим красным.
foreach ($items as $roomId => &$list) {
    foreach ($list as $i => &$it) {
        $it['conflict'] = false;
        if ($it['kind'] === 'booking' && !in_array($it['status'], OCCUPYING_STATUSES, true)) {
            continue;
        }
        foreach ($list as $j => $other) {
            if ($i !== $j && $other['a'] < $it['z'] && $other['z'] > $it['a']
                && ($other['kind'] !== 'booking' || in_array($other['status'], OCCUPYING_STATUSES, true))) {
                $it['conflict'] = true;
            }
        }
    }
    unset($it);
}
unset($list);

$nav = fn (DateTimeImmutable $d) => '/admin/calendar.php?' . http_build_query(['start' => $d->format('Y-m-d'), 'days' => $days]);

admin_header('Шахматка', 'calendar', 'adm-autoreload');
?>
<div class="adm-head">
    <div>
        <h1>Шахматка</h1>
        <p class="adm-head__sub"><?= e(ru_date_range($from, $to)) ?>. Нажмите на пустую клетку, чтобы завести бронь в эту комнату.</p>
    </div>
    <form class="filters" method="get">
        <div class="adm-actions">
            <a class="btn btn--outline btn--sm" href="<?= e($nav($start->modify('-7 days'))) ?>" aria-label="Неделя назад">←</a>
            <a class="btn btn--outline btn--sm" href="<?= e($nav(today()->modify('-2 days'))) ?>">Сегодня</a>
            <a class="btn btn--outline btn--sm" href="<?= e($nav($start->modify('+7 days'))) ?>" aria-label="Неделя вперёд">→</a>
        </div>
        <div class="field"><label for="c-start">С даты</label><input id="c-start" type="date" name="start" value="<?= e($from) ?>" onchange="this.form.submit()"></div>
        <div class="field"><label for="c-days">Показать</label>
            <select id="c-days" name="days" onchange="this.form.submit()">
<?php foreach ([14 => '2 недели', 21 => '3 недели', 31 => 'месяц', 62 => '2 месяца'] as $n => $label): ?>
                <option value="<?= $n ?>"<?= $n === $days ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="btn btn--outline btn--sm" type="submit">Показать</button></noscript>
    </form>
</div>

<?php if (!$rooms): ?>
<div class="panel"><p>Комнат пока нет. Заведите их в разделе <a href="/admin/rooms.php">Номера и фото</a>.</p></div>
<?php else: ?>
<div class="chess-wrap">
    <div class="chess" style="--days: <?= count($dates) ?>">
        <div class="chess__row chess__head">
            <div class="chess__corner">Комната</div>
<?php foreach ($dates as $d):
    $dt = parse_date($d);
    $w = (int) $dt->format('w');
    $cls = ($w === 0 || $w === 6 ? ' is-weekend' : '') . ($d === $todayStr ? ' is-today' : '') . ($dt->format('j') === '1' ? ' is-month' : '');
?>
            <div class="chess__day<?= $cls ?>" title="<?= e(ru_date($d, true)) ?>"><?= e(RU_WEEKDAYS_SHORT[$w]) ?><b><?= (int) $dt->format('j') ?></b><?= $dt->format('j') === '1' || $d === $from ? e(mb_substr(RU_MONTHS_GEN[(int) $dt->format('n')], 0, 3)) : '' ?></div>
<?php endforeach; ?>
        </div>
<?php foreach ($byType as $typeId => $typeRooms): $type = $types[$typeId] ?? null; ?>
        <div class="chess__typerow"><span class="chess__type"><?= e($type['name'] ?? '') ?> · <?= e(type_price_label($type ?? ['base_price' => 0, 'price_tbd' => 0])) ?></span></div>
<?php foreach ($typeRooms as $room): $rid = (int) $room['id']; ?>
        <div class="chess__row">
            <a class="chess__room" href="/admin/rooms.php#room-<?= $rid ?>" title="<?= e($room['note']) ?>"><?= e($room['name']) ?></a>
            <div class="chess__cells">
<?php foreach ($dates as $k => $d):
    $w = (int) parse_date($d)->format('w');
    $cls = ($w === 0 || $w === 6 ? ' is-weekend' : '') . ($d === $todayStr ? ' is-today' : '');
?>
                <a class="chess__cell<?= $cls ?>" style="grid-column: <?= $k + 1 ?>" href="/admin/booking.php?new=1&amp;room=<?= $rid ?>&amp;check_in=<?= $d ?>" aria-label="Новая бронь: <?= e($room['name']) ?>, <?= e(ru_date($d)) ?>"></a>
<?php endforeach; ?>
<?php foreach ($items[$rid] ?? [] as $it):
    $startIdx = $it['a'] < $from ? 0 : array_search($it['a'], $dates, true);
    $cutStart = $it['a'] < $from;
    $cutEnd = $it['z'] >= $to;
    $endIdx = $cutEnd ? count($dates) : array_search($it['z'], $dates, true);
    $col = ($startIdx + 1) . ' / ' . ($endIdx + ($cutEnd ? 1 : 2));
    $nights = nights_between($it['a'], $it['z']);
    $cls = 'chess__bar' . ($cutStart ? ' is-cut-start' : '') . ($cutEnd ? ' is-cut-end' : '') . ($it['conflict'] ? ' is-conflict' : '');
    if ($it['kind'] === 'booking'):
        $label = ($it['guest_name'] ?? 'Гость') . ' · ' . $nights . ' н.';
?>
                <a class="<?= $cls ?> chess__bar--<?= e($it['status']) ?>" style="grid-column: <?= $col ?>" href="/admin/booking.php?id=<?= (int) $it['id'] ?>"
                   title="<?= e(($it['guest_name'] ?? 'Гость') . ', ' . ru_date_range($it['a'], $it['z']) . ', ' . (BOOKING_STATUSES[$it['status']] ?? '') . ($it['conflict'] ? ' — НАЛОЖЕНИЕ!' : '')) ?>"><span><?= e($label) ?></span></a>
<?php else: ?>
                <a class="<?= $cls ?> chess__bar--<?= $it['kind'] ?>" style="grid-column: <?= $col ?>" href="#closure-<?= (int) $it['id'] ?>"
                   title="<?= e(($it['kind'] === 'ical' ? 'Занято на площадке' : 'Закрыто') . ': ' . ru_date_range($it['a'], $it['z']) . ($it['reason'] !== '' ? ' — ' . $it['reason'] : '')) ?>"><span><?= e($it['kind'] === 'ical' ? 'Площадка' : ($it['reason'] !== '' ? $it['reason'] : 'Закрыто')) ?></span></a>
<?php endif; endforeach; ?>
            </div>
        </div>
<?php endforeach; endforeach; ?>
    </div>
</div>
<div class="chess-legend">
    <span><i style="background:#fbe9d0;box-shadow:inset 0 0 0 1.5px #d29365"></i>заявка</span>
    <span><i style="background:var(--olive)"></i>подтверждена</span>
    <span><i style="background:var(--accent)"></i>проживает</span>
    <span><i style="background:#ddd4cb"></i>выехал</span>
    <span><i style="background:repeating-linear-gradient(135deg,#e7e0d8 0 4px,#f3eee8 4px 8px)"></i>закрыто</span>
    <span><i style="background:repeating-linear-gradient(135deg,#e3dbea 0 4px,#f1ecf4 4px 8px)"></i>занято на площадке</span>
    <span><i style="box-shadow:inset 0 0 0 2px var(--danger)"></i>наложение</span>
</div>
<?php endif; ?>

<div class="two-col">
    <section class="panel">
        <h2 class="panel__title">Закрыть комнату на даты <small>ремонт, свои нужды</small></h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="start" value="<?= e($from) ?>">
            <input type="hidden" name="days" value="<?= $days ?>">
            <div class="field"><label for="cl-room">Комната</label>
                <select id="cl-room" name="room_id" required>
<?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['type_name']) ?>)</option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="cl-from">С ночи</label><input id="cl-from" type="date" name="date_from" required value="<?= e($todayStr) ?>"></div>
            <div class="field"><label for="cl-to">Открыть с</label><input id="cl-to" type="date" name="date_to" required></div>
            <div class="field"><label for="cl-reason">Причина</label><input id="cl-reason" name="reason" maxlength="255" placeholder="Ремонт"></div>
            <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Закрыть</button></div>
        </form>
    </section>
    <section class="panel">
        <h2 class="panel__title">Закрытия в эти даты</h2>
<?php if (!$closures): ?>
        <p class="empty">Нет.</p>
<?php else: ?>
        <ul class="blist">
<?php foreach ($closures as $c): ?>
            <li class="brow" id="closure-<?= (int) $c['id'] ?>">
                <span class="brow__name"><?= e($c['room_name']) ?>: <?= e(ru_date_range($c['date_from'], $c['date_to'])) ?></span>
<?php if ($c['source'] === 'manual'): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="open">
                    <input type="hidden" name="closure_id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="start" value="<?= e($from) ?>">
                    <input type="hidden" name="days" value="<?= $days ?>">
                    <button class="btn btn--outline btn--sm" type="submit" data-confirm="Открыть комнату для продажи?">Открыть</button>
                </form>
<?php else: ?>
                <span class="muted">с площадки</span>
<?php endif; ?>
                <span class="brow__meta"><?= e($c['reason'] !== '' ? $c['reason'] : 'без причины') ?></span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
</div>
<?php admin_footer(); ?>
