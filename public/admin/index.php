<?php
/**
 * «Сегодня» — главный экран администратора: что требует внимания прямо
 * сейчас. Обновляется сам, когда приходит новая заявка.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$today = today()->format('Y-m-d');
$week = today()->modify('+7 days')->format('Y-m-d');

$new = bookings_where("b.status = 'new'", [], 'b.created_at DESC');
$arrivals = bookings_where("b.status IN ('new', 'confirmed') AND b.check_in = ?", [$today]);
$departures = bookings_where("b.status = 'checked_in' AND b.check_out = ?", [$today]);
$inHouse = bookings_where("b.status = 'checked_in'", [], 'b.check_out');
$upcoming = bookings_where("b.status IN ('new', 'confirmed') AND b.check_in > ? AND b.check_in <= ?", [$today, $week]);
$overdueOut = bookings_where("b.status = 'checked_in' AND b.check_out < ?", [$today]);
$overdueIn = bookings_where("b.status IN ('new', 'confirmed') AND b.check_in < ?", [$today]);
$messages = q_all('SELECT m.*, b.id AS booking_id, g.name AS guest_name FROM booking_messages m JOIN bookings b ON b.id = m.booking_id LEFT JOIN guests g ON g.id = b.guest_id WHERE m.handled_at IS NULL ORDER BY m.id DESC');
$conflicts = ical_conflicts();

$rooms = rooms_list(true);
$grid = availability_grid($today, today()->modify('+1 day')->format('Y-m-d'));
$freeTonight = array_sum($grid['free'][$today] ?? []);
$waitlist = (int) q_val('SELECT COUNT(*) FROM waitlist');
cleaning_sync($today);
$toClean = cleaning_due_count();

admin_header('Сегодня', 'today', 'adm-autoreload');
?>
<div class="adm-head">
    <div>
        <h1><?= e(ru_date($today)) ?>, <?= e(RU_WEEKDAYS[(int) date('w')]) ?></h1>
        <p class="adm-head__sub">Здравствуйте, <?= e($me['name']) ?>. Новые заявки и сообщения появляются здесь сами.</p>
    </div>
    <div class="adm-actions">
        <a class="btn btn--outline btn--sm" href="/admin/calendar.php"><?= icon('calendar-days') ?>Шахматка</a>
    </div>
</div>

<div class="kpis">
    <a class="kpi<?= $new ? ' kpi--accent' : '' ?>" href="/admin/bookings.php?status=new"><b><?= count($new) ?></b><span><?= plural(count($new), 'новая заявка', 'новые заявки', 'новых заявок') ?></span></a>
    <a class="kpi" href="#arrivals"><b><?= count($arrivals) ?></b><span><?= plural(count($arrivals), 'заезд', 'заезда', 'заездов') ?> сегодня</span></a>
    <a class="kpi" href="#departures"><b><?= count($departures) ?></b><span><?= plural(count($departures), 'выезд', 'выезда', 'выездов') ?> сегодня</span></a>
    <a class="kpi" href="#inhouse"><b><?= count($inHouse) ?></b><span><?= plural(count($inHouse), 'номер занят', 'номера заняты', 'номеров занято') ?> гостями</span></a>
    <a class="kpi<?= $toClean ? ' kpi--accent' : '' ?>" href="/admin/cleaning.php"><b><?= $toClean ?></b><span><?= $toClean ? plural($toClean, 'номер ждёт', 'номера ждут', 'номеров ждут') . ' уборки' : 'все номера убраны' ?></span></a>
    <a class="kpi" href="/admin/calendar.php"><b><?= $freeTonight ?> <small>из <?= count($rooms) ?></small></b><span><?= plural($freeTonight, 'комната свободна', 'комнаты свободны', 'комнат свободно') ?> сегодня</span></a>
</div>

<?php if ($conflicts): ?>
<section class="panel panel--warn">
    <h2 class="panel__title"><?= icon('triangle-alert') ?> Двойная продажа: номер занят и у нас, и на площадке</h2>
    <div class="blist">
<?php foreach ($conflicts as $c): ?>
        <a class="brow" href="/admin/booking.php?id=<?= (int) $c['id'] ?>"><span class="brow__name">Бронь №<?= (int) $c['id'] ?>, комната <?= e($c['room_name']) ?></span><span></span>
            <span class="brow__meta">У нас: <?= e(ru_date_range($c['check_in'], $c['check_out'])) ?> · на площадке: <?= e(ru_date_range($c['date_from'], $c['date_to'])) ?> (<?= e($c['reason']) ?>)</span></a>
<?php endforeach; ?>
    </div>
    <p class="hint">Перенесите бронь в другую комнату или снимите номер с продажи на площадке.</p>
</section>
<?php endif; ?>

<?php if ($overdueOut || $overdueIn): ?>
<section class="panel panel--warn">
    <h2 class="panel__title"><?= icon('clock') ?> Нужно обновить статус</h2>
    <div class="blist">
<?php foreach ($overdueOut as $b): ?>
        <?= booking_row_html($b, 'должен был выехать') ?>
<?php endforeach; ?>
<?php foreach ($overdueIn as $b): ?>
        <?= booking_row_html($b, 'дата заезда прошла — заселился или не приехал?') ?>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="two-col">
    <div class="blist">
        <section class="panel">
            <h2 class="panel__title">Новые заявки <small>подтвердите или отмените</small></h2>
<?php if (!$new): ?>
            <p class="empty">Новых заявок нет.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($new as $b): ?>
                <?= booking_row_html($b, 'пришла ' . ru_datetime($b['created_at'])) ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Сообщения гостей</h2>
<?php if (!$messages): ?>
            <p class="empty">Новых сообщений нет.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($messages as $m): ?>
                <a class="brow" href="/admin/booking.php?id=<?= (int) $m['booking_id'] ?>#messages">
                    <span class="brow__name"><?= e($m['guest_name'] ?? 'Гость') ?>: <?= e(mb_strtolower(MESSAGE_KINDS[$m['kind']] ?? $m['kind'])) ?><?= $m['new_check_out'] ? ' до ' . e(ru_date($m['new_check_out'])) : '' ?></span><span></span>
                    <span class="brow__meta">Бронь №<?= (int) $m['booking_id'] ?> · <?= e(ru_datetime($m['created_at'])) ?><?= $m['message'] ? ' · «' . e(mb_strimwidth($m['message'], 0, 80, '…')) . '»' : '' ?></span>
                </a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>

        <section class="panel" id="inhouse">
            <h2 class="panel__title">Проживают сейчас</h2>
<?php if (!$inHouse): ?>
            <p class="empty">Никто не заселён.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($inHouse as $b): ?>
                <?= booking_row_html($b, 'выезд ' . ru_date($b['check_out'])) ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>
    </div>

    <div class="blist">
        <section class="panel" id="arrivals">
            <h2 class="panel__title">Заезды сегодня <small>с <?= e(hotel()['check_in']) ?></small></h2>
<?php if (!$arrivals): ?>
            <p class="empty">Сегодня заездов нет.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($arrivals as $b): ?>
                <?= booking_row_html($b) ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>

        <section class="panel" id="departures">
            <h2 class="panel__title">Выезды сегодня <small>до <?= e(hotel()['check_out']) ?></small></h2>
<?php if (!$departures): ?>
            <p class="empty">Сегодня выездов нет.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($departures as $b): ?>
                <?= booking_row_html($b, $b['balance'] > 0 ? 'к оплате ' . money($b['balance']) : '') ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="panel__title">Заезды на неделе</h2>
<?php if (!$upcoming): ?>
            <p class="empty">В ближайшие 7 дней заездов нет.</p>
<?php else: ?>
            <div class="blist">
<?php foreach ($upcoming as $b): ?>
                <?= booking_row_html($b) ?>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>
<?php if ($waitlist): ?>
        <a class="kpi" href="/admin/guests.php?tab=waitlist"><b><?= $waitlist ?></b><span><?= plural($waitlist, 'человек просит', 'человека просят', 'человек просят') ?> сообщить об открытии бани</span></a>
<?php endif; ?>
    </div>
</div>
<?php admin_footer(); ?>
