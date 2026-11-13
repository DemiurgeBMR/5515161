<?php
/**
 * Карточка гостя: контакты, заметка, все его брони. Владелец может
 * удалить личные данные гостя по его просьбе (152-ФЗ) — брони остаются
 * для учёта, но уже без имени и контактов.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$id = (int) (post('id') ?: get('id'));
$g = guest_get($id);
if (!$g) {
    http_response_code(404);
    admin_header('Гость не найден', 'guests');
    echo '<div class="panel"><p>Гостя нет. <a href="/admin/guests.php">К списку</a></p></div>';
    admin_footer();
    exit;
}
$error = null;
if (is_post()) {
    csrf_check();
    if (post('action') === 'save') {
        $email = mb_strtolower(post('email'));
        if (post('name') === '') {
            $error = 'Укажите имя';
        } elseif (!valid_email($email)) {
            $error = 'Проверьте адрес почты';
        } else {
            guest_update($id, ['name' => mb_substr(post('name'), 0, 100), 'phone' => mb_substr(post('phone'), 0, 25), 'email' => $email, 'note' => mb_substr(post('note'), 0, 2000) ?: null]);
            audit('guest', $id, 'updated');
            flash('ok', 'Данные гостя сохранены.');
            redirect('/admin/guest.php?id=' . $id);
        }
    } elseif (post('action') === 'anonymize' && is_owner()) {
        guest_anonymize($id, 'по просьбе гостя');
        flash('ok', 'Личные данные гостя удалены. Брони остались без имени и контактов.');
        redirect('/admin/guest.php?id=' . $id);
    }
}

$bookings = bookings_where('b.guest_id = ?', [$id], 'b.check_in DESC');
$spent = array_sum(array_map(fn ($b) => in_array($b['status'], ['checked_in', 'checked_out'], true) ? (int) $b['total_price'] : 0, $bookings));

admin_header($g['name'], 'guests');
?>
<div class="adm-head">
    <div>
        <h1><?= e($g['name']) ?></h1>
        <p class="adm-head__sub"><?= $g['anonymized_at'] ? 'Личные данные удалены ' . e(ru_datetime($g['anonymized_at'])) : guest_contacts_html($g['phone'], $g['email']) ?></p>
    </div>
<?php if (!$g['anonymized_at']): ?>
    <div class="adm-actions"><a class="btn btn--primary btn--sm" href="/admin/booking.php?new=1&amp;guest=<?= $id ?>"><?= icon('plus') ?>Новая бронь для гостя</a></div>
<?php endif; ?>
</div>
<div class="main-side">
    <section class="panel">
        <h2 class="panel__title">Брони <small><?= count($bookings) ?>, прожито на <?= e(money($spent)) ?></small></h2>
<?php if (!$bookings): ?>
        <p class="empty">Броней нет.</p>
<?php else: ?>
        <div class="blist">
<?php foreach ($bookings as $b): ?>
            <?= booking_row_html($b, $b['total_price'] !== null ? money($b['total_price']) : '') ?>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>
<?php if (!$g['anonymized_at']): ?>
    <div class="blist">
        <section class="panel">
            <h2 class="panel__title">Контакты и заметка</h2>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="save">
                <div class="field span-all"><label for="g-name">Имя</label><input id="g-name" name="name" required value="<?= e($g['name']) ?>"></div>
                <div class="field"><label for="g-phone">Телефон</label><input id="g-phone" name="phone" type="tel" value="<?= e($g['phone']) ?>"></div>
                <div class="field"><label for="g-email">Почта</label><input id="g-email" name="email" type="email" value="<?= e($g['email']) ?>"></div>
                <div class="field span-all"><label for="g-note">Заметка <span class="muted">видят только сотрудники</span></label><textarea id="g-note" name="note" rows="3"><?= e($g['note'] ?? '') ?></textarea></div>
                <p class="hint span-all">Не записывайте сюда диагнозы и паспортные данные — они не нужны для работы и требуют особой защиты.</p>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
            </form>
        </section>
<?php if (is_owner()): ?>
        <section class="panel">
            <h2 class="panel__title">Удалить личные данные</h2>
            <p class="hint">По просьбе гостя: имя, телефон, почта и комментарии сотрутся, брони и платежи останутся для учёта. Отменить нельзя.</p>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="anonymize">
                <button class="btn btn--danger btn--sm" type="submit" data-confirm="Удалить личные данные гостя навсегда?">Удалить данные гостя</button></form>
        </section>
<?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
