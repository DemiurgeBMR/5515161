<?php
/**
 * Гости: поиск по имени, телефону и почте; список тех, кто просил
 * сообщить об открытии бани.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

if (is_post()) {
    csrf_check();
    if (post('action') === 'waitlist_delete') {
        q('DELETE FROM waitlist WHERE id = ?', [(int) post('wid')]);
        flash('ok', 'Удалено из списка.');
    } elseif (post('action') === 'waitlist_clear') {
        $n = q("DELETE FROM waitlist WHERE topic = 'bath'")->rowCount();
        audit('guest', null, 'waitlist_cleared', 'удалено телефонов: ' . $n);
        flash('ok', 'Список очищен.');
    } elseif (post('action') === 'retention' && is_owner()) {
        $days = (int) post('days');
        if ($days < 7 || $days > 365) {
            flash('error', 'Срок — от 7 до 365 дней.');
        } else {
            set_setting('retention_days', (string) $days);
            audit('settings', null, 'updated', 'срок хранения несостоявшихся заявок: ' . $days . ' дн.');
            flash('ok', 'Сохранено. Этот срок указан и в политике обработки персональных данных на сайте.');
        }
        redirect('/admin/guests.php#retention');
    }
    redirect('/admin/guests.php?tab=waitlist');
}

$tab = get('tab') === 'waitlist' ? 'waitlist' : 'guests';
$q = get('q');

admin_header('Гости', 'guests');
?>
<div class="adm-head">
    <div><h1>Гости</h1></div>
    <nav class="tabs">
        <a class="tab<?= $tab === 'guests' ? ' is-active' : '' ?>" href="/admin/guests.php">Все гости</a>
        <a class="tab<?= $tab === 'waitlist' ? ' is-active' : '' ?>" href="/admin/guests.php?tab=waitlist">Ждут открытия бани <span class="muted"><?= (int) q_val('SELECT COUNT(*) FROM waitlist') ?></span></a>
    </nav>
</div>
<?php if ($tab === 'waitlist'):
    $rows = q_all("SELECT * FROM waitlist WHERE topic = 'bath' ORDER BY id DESC");
?>
<section class="panel">
    <h2 class="panel__title">Просили сообщить об открытии бани и хамама</h2>
<?php if (!$rows): ?>
    <p class="empty">Пока никто не оставил телефон.</p>
<?php else: ?>
    <table class="table table--cards">
        <thead><tr><th>Телефон</th><th>Когда</th><th></th></tr></thead>
        <tbody>
<?php foreach ($rows as $w): ?>
            <tr>
                <td data-label="Телефон"><?= guest_contacts_html($w['phone'], '') ?></td>
                <td data-label="Когда"><?= e(ru_datetime($w['created_at'])) ?></td>
                <td data-label="" class="num">
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="waitlist_delete"><input type="hidden" name="wid" value="<?= (int) $w['id'] ?>">
                        <button class="icon-button" type="submit" aria-label="Удалить"><?= icon('trash-2') ?></button></form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="waitlist_clear">
        <button class="btn btn--outline btn--sm" type="submit" data-confirm="Удалить все телефоны из списка? Сделайте это, когда всех обзвоните.">Всех обзвонили — очистить список</button></form>
<?php endif; ?>
    <p class="hint">Телефоны нужны только для одного звонка. Когда баня откроется и вы всем сообщите — очистите список.</p>
</section>
<?php else:
    $where = 'g.anonymized_at IS NULL';
    $params = [];
    if ($q !== '') {
        $digits = preg_replace('/\D+/', '', $q);
        $where .= ' AND (g.name LIKE ? OR g.email LIKE ?' . (strlen($digits) >= 4 ? ' OR g.phone_digits LIKE ?' : '') . ')';
        $params = ['%' . $q . '%', '%' . $q . '%'];
        if (strlen($digits) >= 4) {
            $params[] = '%' . $digits . '%';
        }
    }
    $rows = q_all("SELECT g.*, COUNT(b.id) AS bookings_count, MAX(b.check_in) AS last_check_in,
                          SUM(CASE WHEN b.status IN ('checked_in', 'checked_out') THEN 1 ELSE 0 END) AS stays
                   FROM guests g LEFT JOIN bookings b ON b.guest_id = g.id
                   WHERE $where GROUP BY g.id ORDER BY last_check_in DESC, g.id DESC LIMIT 200", $params);
?>
<form class="filters panel" method="get">
    <div class="field field--wide"><label for="g-q">Поиск</label><input id="g-q" name="q" value="<?= e($q) ?>" placeholder="Имя, телефон или почта"></div>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('search') ?>Найти</button></div>
</form>
<?php if (!$rows): ?>
<div class="panel"><p class="empty">Гостей не найдено.</p></div>
<?php else: ?>
<div class="table-wrap">
    <table class="table table--cards">
        <thead><tr><th>Гость</th><th>Контакты</th><th class="num">Броней</th><th class="num">Проживаний</th><th>Последний заезд</th></tr></thead>
        <tbody>
<?php foreach ($rows as $g): ?>
            <tr>
                <td data-label="Гость"><a href="/admin/guest.php?id=<?= (int) $g['id'] ?>"><?= e($g['name']) ?></a><?php if ($g['note']): ?><small><?= e(mb_strimwidth($g['note'], 0, 60, '…')) ?></small><?php endif; ?></td>
                <td data-label="Контакты"><?= guest_contacts_html($g['phone'], $g['email']) ?></td>
                <td data-label="Броней" class="num"><?= (int) $g['bookings_count'] ?></td>
                <td data-label="Проживаний" class="num"><?= (int) $g['stays'] ?></td>
                <td data-label="Последний заезд"><?= $g['last_check_in'] ? e(ru_date($g['last_check_in'])) : '—' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php if (is_owner()): ?>
<section class="panel" id="retention">
    <h2 class="panel__title"><?= icon('shield-check') ?>Хранение данных гостей</h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="retention">
        <div class="field"><label for="g-days">Удалять данные несостоявшихся заявок через, дней</label><input id="g-days" name="days" type="number" min="7" max="365" value="<?= max(7, (int) setting('retention_days', '30')) ?>"></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit">Сохранить</button></div>
    </form>
    <p class="hint">Если заявку отменили или гость не приехал, через столько дней после дат брони сайт сам сотрёт имя, телефон, почту и комментарии — так требует закон о персональных данных. Брони остаются для отчётов, но без личных данных. Тех, кто у вас жил, сайт не трогает: их можно обезличить вручную в карточке гостя, если человек попросит.</p>
</section>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer(); ?>
