<?php
/**
 * Профиль сотрудника: имя, письма о заявках (горничной — об уборке), смена пароля.
 * Сюда же попадают после входа с временным паролем (?first=1).
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();
$first = (bool) $me['must_change_password'];

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'password') {
            if (!password_verify(post('current'), $me['password_hash'])) {
                throw new BookingError('Текущий пароль введён неверно');
            }
            // Пробелы по краям обрезаются так же, как при входе.
            $new = post('password');
            if ($new !== post('password2')) {
                throw new BookingError('Пароли не совпадают');
            }
            if (hash_equals(post('current'), $new)) {
                throw new BookingError('Новый пароль должен отличаться от старого');
            }
            staff_set_password($me['id'], $new);
            audit('staff', $me['id'], 'password_changed');
            flash('ok', 'Пароль изменён. На других устройствах нужно будет войти заново.');
            redirect($first ? '/admin/' : '/admin/profile.php');
        } elseif ($action === 'save' && !$first) {
            $name = trim(post('name'));
            if ($name === '') {
                throw new BookingError('Укажите имя');
            }
            q_update('staff', $me['id'], ['name' => mb_substr($name, 0, 100), 'notify' => post('notify') === '1' ? 1 : 0]);
            flash('ok', 'Сохранено.');
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/profile.php' . ($first ? '?first=1' : ''));
}

admin_header('Профиль', '');
?>
<div class="adm-head">
    <div>
        <h1><?= $first ? 'Придумайте свой пароль' : 'Профиль' ?></h1>
        <p class="adm-head__sub"><?= e($me['email']) ?> · <?= e(STAFF_ROLES[$me['role']] ?? $me['role']) ?></p>
    </div>
</div>
<div class="two-col">
<?php if (!$first): ?>
<section class="panel">
    <h2 class="panel__title">Данные</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="field"><label for="p-name">Имя</label><input id="p-name" name="name" required maxlength="100" value="<?= e($me['name']) ?>"></div>
        <label class="inline-check"><input type="checkbox" name="notify" value="1"<?= $me['notify'] ? ' checked' : '' ?>> <?= is_housekeeper($me) ? 'Присылать мне письма, когда пора убирать номер' : 'Присылать мне письма о новых заявках и сообщениях гостей' ?></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
    </form>
</section>
<?php endif; ?>
<section class="panel">
    <h2 class="panel__title"><?= icon('lock') ?>Пароль</h2>
<?php if ($first): ?>
    <p>Вы вошли с временным паролем. Придумайте свой — не короче 8 символов. Его будете знать только вы.</p>
<?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <?php // Почта — для менеджера паролей браузера: без неё он не поймёт, у какого входа сменился пароль, и будет подставлять старый. ?>
        <input type="email" name="username" value="<?= e($me['email']) ?>" autocomplete="username" readonly hidden>
        <?= password_field('p-cur', 'current', $first ? 'Временный пароль' : 'Текущий пароль', 'current-password') ?>
        <?= password_field('p-new', 'password', 'Новый пароль', 'new-password', true, 8) ?>
        <?= password_field('p-new2', 'password2', 'Ещё раз', 'new-password', true, 8) ?>
        <p class="hint">Если браузер предложит сохранить или обновить пароль — согласитесь: иначе при следующем входе он может подставить старый, временный.</p>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сменить пароль</button></div>
    </form>
</section>
</div>
<?php admin_footer(); ?>
