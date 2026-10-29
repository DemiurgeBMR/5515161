<?php
/**
 * Сотрудники (только владелец): кто может входить в админку, кому
 * приходят письма о новых заявках, сброс пароля.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff(true);

/** Роль из формы; неизвестная — администратор. */
function staff_role_input(): string
{
    return isset(STAFF_ROLES[post('role')]) ? post('role') : 'admin';
}

/** Сколько активных владельцев останется, если этого сотрудника изменить. */
function owners_left_without(int $id): int
{
    return (int) q_val("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND is_active = 1 AND id <> ?", [$id]);
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    $s = q_one('SELECT * FROM staff WHERE id = ?', [(int) post('staff_id')]);
    try {
        if ($action === 'add') {
            $new = staff_create(post('email'), post('name'), staff_role_input());
            flash('ok', 'Сотрудник добавлен. Почта для входа: ' . $new['email'] . ', временный пароль: ' . $new['password']
                . ' — передайте его лично или в мессенджере. При первом входе админка попросит придумать свой пароль.');
        } elseif ($action === 'save' && $s) {
            $id = (int) $s['id'];
            $role = staff_role_input();
            $active = post('is_active') === '1' ? 1 : 0;
            if ($id === $me['id'] && !$active) {
                throw new BookingError('Нельзя отключить самого себя');
            }
            if ($s['role'] === 'owner' && ($role !== 'owner' || !$active) && owners_left_without($id) === 0) {
                throw new BookingError('Это единственный владелец — сначала назначьте владельцем кого-то ещё');
            }
            $name = trim(post('name'));
            if ($name === '') {
                throw new BookingError('Укажите имя');
            }
            q_update('staff', $id, ['name' => mb_substr($name, 0, 100), 'role' => $role, 'is_active' => $active, 'notify' => post('notify') === '1' ? 1 : 0]);
            $what = [];
            if ($role !== $s['role']) {
                $what[] = 'роль: ' . STAFF_ROLES[$role];
            }
            if ($active !== (int) $s['is_active']) {
                $what[] = $active ? 'включён' : 'отключён';
            }
            audit('staff', $id, 'updated', $what ? implode(', ', $what) : null);
            flash('ok', 'Сохранено' . (!$active && $s['is_active'] ? ' — сотрудник больше не может войти' : '') . '.');
        } elseif ($action === 'reset' && $s) {
            if ((int) $s['id'] === $me['id']) {
                throw new BookingError('Свой пароль меняйте в профиле');
            }
            $password = temp_password();
            staff_set_password((int) $s['id'], $password, true);
            rate_clear('staff_email', $s['email']);
            audit('staff', (int) $s['id'], 'password_reset');
            flash('ok', 'Новый временный пароль для ' . $s['email'] . ': ' . $password . ' — все его входы на других устройствах сброшены.');
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/users.php');
}

$staff = q_all('SELECT * FROM staff ORDER BY is_active DESC, role DESC, name');

admin_header('Сотрудники', 'users');
?>
<div class="adm-head">
    <div>
        <h1>Сотрудники</h1>
        <p class="adm-head__sub">Владелец видит всё и управляет сотрудниками. Администратор работает с бронями, гостями, номерами и ценами. Горничная видит только список уборки — без данных гостей и денег.</p>
    </div>
</div>

<section class="panel">
    <div class="table-wrap">
        <table class="table table--cards">
            <thead><tr><th>Сотрудник</th><th>Роль</th><th>Настройки</th><th>Последний вход</th><th></th></tr></thead>
            <tbody>
<?php foreach ($staff as $s): $sid = (int) $s['id']; $fid = 'staff-' . $sid; ?>
                <tr class="<?= $s['is_active'] ? '' : 'is-muted' ?>">
                    <td data-label="Сотрудник"><input form="<?= $fid ?>" class="cell-input" name="name" value="<?= e($s['name']) ?>" maxlength="100" required aria-label="Имя">
                        <small><?= e($s['email']) ?><?= $sid === $me['id'] ? ' · это вы' : '' ?><?= $s['must_change_password'] ? ' · ещё не сменил временный пароль' : '' ?></small></td>
                    <td data-label="Роль"><select form="<?= $fid ?>" class="cell-input cell-input--role" name="role" aria-label="Роль">
<?php foreach (STAFF_ROLES as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $s['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                    </select></td>
                    <td data-label="Настройки"><div class="checks">
                        <label class="inline-check"><input form="<?= $fid ?>" type="checkbox" name="notify" value="1"<?= $s['notify'] ? ' checked' : '' ?>> <?= $s['role'] === 'housekeeper' ? 'письма об уборке' : 'письма о заявках' ?></label>
<?php if (max_enabled()): ?>
                        <small><?= $s['max_user_id'] ? 'MAX подключён' . ($s['max_notify'] ? '' : ', уведомления выключены') : 'MAX не подключён' ?></small>
<?php endif; ?>
                        <label class="inline-check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1"<?= $s['is_active'] ? ' checked' : '' ?><?= $sid === $me['id'] ? ' disabled' : '' ?>> может входить</label><?php if ($sid === $me['id']): ?><input form="<?= $fid ?>" type="hidden" name="is_active" value="1"><?php endif; ?>
                    </div></td>
                    <td data-label="Последний вход"><?= $s['last_login_at'] ? e(ru_datetime($s['last_login_at'])) : '—' ?></td>
                    <td data-label="" class="num"><div class="row-actions">
                        <form method="post" id="<?= $fid ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="staff_id" value="<?= $sid ?>">
                            <button class="btn btn--outline btn--sm" type="submit">Сохранить</button></form>
<?php if ($sid !== $me['id']): ?>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="staff_id" value="<?= $sid ?>">
                            <button class="btn btn--outline btn--sm" type="submit" data-confirm="Выдать <?= e($s['name']) ?> новый временный пароль? Старый перестанет работать."><?= icon('key-round') ?>Новый пароль</button></form>
<?php endif; ?>
                    </div></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2 class="panel__title">Добавить сотрудника</h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="field"><label for="u-name">Имя</label><input id="u-name" name="name" required maxlength="100" placeholder="Ольга"></div>
        <div class="field"><label for="u-email">Почта</label><input id="u-email" type="email" name="email" required maxlength="190" placeholder="olga@mail.ru"></div>
        <div class="field"><label for="u-role">Роль</label><select id="u-role" name="role">
            <option value="admin">Администратор</option>
            <option value="housekeeper">Горничная</option>
            <option value="owner">Владелец</option>
        </select></div>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?>Добавить</button></div>
    </form>
    <p class="hint">Сайт придумает временный пароль и покажет его здесь один раз. Письма о новых заявках и сообщениях гостей приходят администраторам и владельцам, у кого они включены, а горничным — письма, когда пора убирать номер.</p>
</section>
<?php admin_footer(); ?>
