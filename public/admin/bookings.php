<?php
/**
 * Список броней с поиском и фильтрами.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$tabs = [
    'active'    => ['Текущие', "b.status IN ('new', 'confirmed', 'checked_in')"],
    'new'       => ['Заявки', "b.status = 'new'"],
    'done'      => ['Выехали', "b.status = 'checked_out'"],
    'cancelled' => ['Отменённые', "b.status IN ('cancelled', 'no_show')"],
    'all'       => ['Все', '1 = 1'],
];
$tab = get('status', 'active');
if (!isset($tabs[$tab])) {
    $tab = 'active';
}

$where = [$tabs[$tab][1]];
$params = [];
$q = get('q');
if ($q !== '') {
    if (preg_match('/^№?\s*(\d{1,7})$/u', $q, $m)) {
        $where[] = 'b.id = ?';
        $params[] = (int) $m[1];
    } else {
        $digits = preg_replace('/\D+/', '', $q);
        $where[] = '(g.name LIKE ? OR g.email LIKE ?' . (strlen($digits) >= 4 ? ' OR g.phone_digits LIKE ?' : '') . ')';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        if (strlen($digits) >= 4) {
            $params[] = '%' . $digits . '%';
        }
    }
}
$from = date_value(get('from'));
$to = date_value(get('to'));
if ($from !== '') {
    $where[] = 'b.check_out > ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'b.check_in <= ?';
    $params[] = $to;
}
$type = (int) get('type');
if ($type) {
    $where[] = 'b.room_type_id = ?';
    $params[] = $type;
}

$perPage = 50;
$page = max(1, (int) get('page', '1'));
$whereSql = implode(' AND ', $where);
$total = (int) q_val('SELECT COUNT(*) FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id WHERE ' . $whereSql, $params);
$order = $tab === 'new' ? 'b.created_at DESC' : ($tab === 'active' ? 'b.check_in, b.id' : 'b.check_in DESC, b.id DESC');
$rows = array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params));
$counts = [];
foreach ($tabs as $key => [, $sql]) {
    $counts[$key] = (int) q_val('SELECT COUNT(*) FROM bookings b WHERE ' . $sql);
}
$link = fn (array $over) => '/admin/bookings.php?' . http_build_query(array_filter(array_merge(['status' => $tab, 'q' => $q, 'from' => $from, 'to' => $to, 'type' => $type ?: ''], $over), fn ($v) => $v !== '' && $v !== null));

admin_header('Брони', 'bookings');
?>
<div class="adm-head">
    <div>
        <h1>Брони</h1>
        <p class="adm-head__sub">Найдено: <?= $total ?></p>
    </div>
    <div class="adm-actions">
        <a class="btn btn--primary btn--sm" href="/admin/booking.php?new=1"><?= icon('plus') ?>Новая бронь</a>
        <a class="btn btn--outline btn--sm" href="/admin/reports.php"><?= icon('download') ?>Выгрузка в Excel</a>
    </div>
</div>

<nav class="tabs" aria-label="Статус">
<?php foreach ($tabs as $key => [$label]): ?>
    <a class="tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($link(['status' => $key, 'page' => null])) ?>"><?= e($label) ?><?php if ($key === 'new' && $counts['new']): ?> <b><?= $counts['new'] ?></b><?php elseif ($key !== 'new'): ?> <span class="muted"><?= $counts[$key] ?></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>

<form class="filters panel" method="get">
    <input type="hidden" name="status" value="<?= e($tab) ?>">
    <div class="field field--wide"><label for="f-q">Поиск</label><input id="f-q" name="q" value="<?= e($q) ?>" placeholder="Имя, телефон, почта или № брони"></div>
    <div class="field"><label for="f-from">Проживание с</label><input id="f-from" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="f-to">по</label><input id="f-to" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label for="f-type">Номер</label>
        <select id="f-type" name="type">
            <option value="">Любой</option>
<?php foreach (room_types(false) as $t): ?>
            <option value="<?= $t['id'] ?>"<?= $type === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('search') ?>Найти</button>
<?php if ($q !== '' || $from !== '' || $to !== '' || $type): ?>
        <a class="btn btn--outline btn--sm" href="/admin/bookings.php?status=<?= e($tab) ?>">Сбросить</a>
<?php endif; ?>
    </div>
</form>

<?php if (!$rows): ?>
<div class="panel"><p class="empty">Броней не найдено.</p></div>
<?php else: ?>
<div class="table-wrap">
    <table class="table table--cards">
        <thead>
            <tr><th>№</th><th>Гость</th><th>Даты</th><th>Номер</th><th>Статус</th><th class="num">Сумма</th><th class="num">Оплачено</th><th>Создана</th></tr>
        </thead>
        <tbody>
<?php foreach ($rows as $b): ?>
            <tr class="<?= in_array($b['status'], ['cancelled', 'no_show'], true) ? 'is-muted' : '' ?>">
                <td data-label="№"><a href="/admin/booking.php?id=<?= $b['id'] ?>"><?= e(booking_number($b)) ?></a></td>
                <td data-label="Гость"><a href="/admin/booking.php?id=<?= $b['id'] ?>"><?= e($b['guest_name'] ?? 'Гость') ?></a><small><?= e($b['guest_phone'] ?? '') ?></small></td>
                <td data-label="Даты" class="nowrap"><?= e(ru_date_range($b['check_in'], $b['check_out'])) ?><small><?= e(nights_label($b['nights'])) ?></small></td>
                <td data-label="Номер"><?= e($b['room_name'] ?? '—') ?><small><?= e($b['type_name']) ?></small></td>
                <td data-label="Статус"><?= status_badge($b['status']) ?><?= $b['open_messages'] ? '<small class="brow__flag">сообщение гостя</small>' : '' ?></td>
                <td data-label="Сумма" class="num"><?= $b['total_price'] !== null ? e(money($b['total_price'])) : '—' ?></td>
                <td data-label="Оплачено" class="num"><?= e(money($b['paid'])) ?><?php if ($b['prepayment'] > 0 && $b['paid'] < $b['prepayment'] && in_array($b['status'], ['new', 'confirmed'], true)): ?><small class="brow__flag">нет предоплаты</small><?php endif; ?></td>
                <td data-label="Создана" class="nowrap"><?= e(ru_datetime($b['created_at'])) ?><small><?= e(BOOKING_SOURCES[$b['source']] ?? $b['source']) ?></small></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($total > $perPage): ?>
<nav class="tabs" aria-label="Страницы">
<?php for ($p = 1; $p <= (int) ceil($total / $perPage); $p++): ?>
    <a class="tab<?= $p === $page ? ' is-active' : '' ?>" href="<?= e($link(['page' => $p])) ?>"><?= $p ?></a>
<?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer(); ?>
