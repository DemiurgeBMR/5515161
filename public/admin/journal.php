<?php
/**
 * Журнал: кто, когда и что менял — брони, оплаты, цены, номера, входы.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

/** Ссылка на то, о чём запись, если это ещё существует. */
function audit_link(array $a): ?string
{
    $id = (int) $a['entity_id'];
    if (!$id) {
        return null;
    }
    return match ($a['entity']) {
        'booking'   => $a['action'] === 'deleted' ? null : '/admin/booking.php?id=' . $id,
        'guest'     => '/admin/guest.php?id=' . $id,
        'room'      => '/admin/rooms.php#room-' . $id,
        'room_type' => '/admin/room_type.php?id=' . $id,
        'staff'     => is_owner() ? '/admin/users.php' : null,
        'cleaning'  => '/admin/cleaning.php',
        'bath'      => in_array($a['action'], ['deleted', 'unblocked'], true) ? null : ($id ? '/admin/bath_booking.php?id=' . $id : '/admin/bath.php?tab=settings'),
        default     => null,
    };
}

$entity = get('entity');
$staffId = (int) get('staff');
$from = date_value(get('from'));
$to = date_value(get('to'));
$q = get('q');
$where = ['1 = 1'];
$params = [];
if (isset(AUDIT_ENTITIES[$entity])) {
    $where[] = 'a.entity = ?';
    $params[] = $entity;
}
if ($staffId) {
    $where[] = 'a.staff_id = ?';
    $params[] = $staffId;
} elseif (get('staff') === 'guest' || get('staff') === 'system') {
    $where[] = 'a.actor = ?';
    $params[] = get('staff');
}
if ($from !== '') {
    $where[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($q !== '') {
    if (preg_match('/^№?\s*(\d{1,7})$/u', $q, $m)) {
        $where[] = "(a.entity = 'booking' AND a.entity_id = ?)";
        $params[] = (int) $m[1];
    } else {
        $where[] = 'a.details LIKE ?';
        $params[] = '%' . $q . '%';
    }
}
// Входы сотрудников засоряют журнал — показываем их, только если выбран раздел «Сотрудник».
if ($entity !== 'staff') {
    $where[] = "NOT (a.entity = 'staff' AND a.action = 'login')";
}
$whereSql = implode(' AND ', $where);
$perPage = 100;
$page = max(1, (int) get('page', '1'));
$total = (int) q_val("SELECT COUNT(*) FROM audit_log a WHERE $whereSql", $params);
$rows = q_all("SELECT a.*, s.name AS staff_name FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id WHERE $whereSql ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$staff = q_all('SELECT id, name FROM staff ORDER BY name');
$link = fn (array $over) => '/admin/journal.php?' . http_build_query(array_filter(array_merge(['entity' => $entity, 'staff' => get('staff'), 'from' => $from, 'to' => $to, 'q' => $q], $over), fn ($v) => $v !== '' && $v !== null));

admin_header('Журнал', 'journal');
?>
<div class="adm-head">
    <div>
        <h1>Журнал</h1>
        <p class="adm-head__sub">Все изменения в админке и действия гостей на сайте. Записей: <?= $total ?></p>
    </div>
</div>

<form class="filters panel" method="get">
    <div class="field"><label for="j-entity">Раздел</label><select id="j-entity" name="entity">
        <option value="">Все</option>
<?php foreach (AUDIT_ENTITIES as $key => $label): ?>
        <option value="<?= $key ?>"<?= $entity === $key ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
    </select></div>
    <div class="field"><label for="j-staff">Кто</label><select id="j-staff" name="staff">
        <option value="">Все</option>
<?php foreach ($staff as $s): ?>
        <option value="<?= (int) $s['id'] ?>"<?= $staffId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
<?php endforeach; ?>
        <option value="guest"<?= get('staff') === 'guest' ? ' selected' : '' ?>>Гости на сайте</option>
        <option value="system"<?= get('staff') === 'system' ? ' selected' : '' ?>>Система</option>
    </select></div>
    <div class="field"><label for="j-from">С</label><input id="j-from" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="j-to">По</label><input id="j-to" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="field field--wide"><label for="j-q">Поиск</label><input id="j-q" name="q" value="<?= e($q) ?>" placeholder="№ брони или слово из описания"></div>
    <div class="form-actions">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('search') ?>Показать</button>
<?php if ($entity !== '' || get('staff') !== '' || $from !== '' || $to !== '' || $q !== ''): ?>
        <a class="btn btn--outline btn--sm" href="/admin/journal.php">Сбросить</a>
<?php endif; ?>
    </div>
</form>

<?php if (!$rows): ?>
<div class="panel"><p class="empty">Записей не найдено.</p></div>
<?php else: ?>
<div class="table-wrap">
    <table class="table table--cards">
        <thead><tr><th>Когда</th><th>Кто</th><th>Что</th><th>Подробности</th></tr></thead>
        <tbody>
<?php foreach ($rows as $a): $href = audit_link($a); ?>
            <tr>
                <td data-label="Когда" class="nowrap"><?= e(ru_datetime($a['created_at'])) ?></td>
                <td data-label="Кто"><?= e($a['staff_name'] ?? ($a['actor'] === 'guest' ? 'Гость на сайте' : 'Система')) ?></td>
                <td data-label="Что">
                    <?php if ($href): ?><a href="<?= e($href) ?>"><?php endif; ?><?= e(AUDIT_ENTITIES[$a['entity']] ?? $a['entity']) ?><?= $a['entity_id'] && in_array($a['entity'], ['booking', 'guest'], true) ? ' №' . (int) $a['entity_id'] : '' ?><?php if ($href): ?></a><?php endif; ?>
                    <small><?= e(audit_action_label($a['entity'], $a['action'])) ?></small>
                </td>
                <td data-label="Подробности"><?= e((string) $a['details']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($total > $perPage): ?>
<nav class="tabs" aria-label="Страницы">
<?php for ($p = 1; $p <= min(50, (int) ceil($total / $perPage)); $p++): ?>
    <a class="tab<?= $p === $page ? ' is-active' : '' ?>" href="<?= e($link(['page' => $p])) ?>"><?= $p ?></a>
<?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer(); ?>
