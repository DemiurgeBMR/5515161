<?php
/**
 * Профиль сотрудника: имя, письма о заявках (горничной — об уборке),
 * уведомления в Telegram, смена пароля.
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
        } elseif ($action === 'tg_code' && tg_enabled()) {
            tg_link_code_create($me['id']);
            redirect('/admin/profile.php#telegram');
        } elseif ($action === 'tg_notify' && $me['tg_user_id']) {
            q_update('staff', $me['id'], ['tg_notify' => post('tg_notify') === '1' ? 1 : 0]);
            flash('ok', post('tg_notify') === '1' ? 'Уведомления в Telegram включены.' : 'Уведомления в Telegram выключены.');
            redirect('/admin/profile.php#telegram');
        } elseif ($action === 'tg_test' && $me['tg_chat_id']) {
            $ok = tg_send((int) $me['tg_chat_id'], 'Проверка связи: всё работает, ' . e($me['name']) . '!');
            flash($ok ? 'ok' : 'error', $ok ? 'Отправили пробное сообщение — проверьте Telegram.' : 'Не отправилось: ' . setting('tg_last_error'));
            redirect('/admin/profile.php#telegram');
        } elseif ($action === 'tg_unlink') {
            tg_unlink($me['id']);
            flash('ok', 'Telegram отключён — уведомления туда больше не придут.');
            redirect('/admin/profile.php#telegram');
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
<?php if (!$first): ?>
<section class="panel" id="telegram">
    <h2 class="panel__title"><?= icon('send') ?>Уведомления в Telegram</h2>
<?php if (!tg_enabled()): ?>
    <p class="hint">Бот ещё не настроен. <?= is_owner() ? 'Как подключить — в README, раздел «Бот в Telegram»: нужен токен бота в config/secrets.php.' : 'Попросите владельца подключить бота.' ?></p>
<?php elseif ($me['tg_user_id']): ?>
    <p><span class="state state--open">Подключено</span> <?= $me['tg_name'] !== '' ? e($me['tg_name']) . ', ' : '' ?>с <?= e(ru_datetime((string) $me['tg_linked_at'])) ?></p>
    <p class="hint"><?= is_housekeeper($me) ? 'Приходит: «пора убрать» с кнопкой «Убрано». Команда боту /clean — что ждёт уборки сейчас.' : 'Приходят: новые заявки с кнопкой «Подтвердить», заявки в баню, сообщения гостей, утренняя сводка дня. Команда боту /today — сводка в любой момент.' ?><?= !is_housekeeper($me) && !tg_personal() ? ' Имена и телефоны гостей в Telegram не отправляются — они в админке.' : '' ?></p>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="tg_notify">
        <label class="inline-check"><input type="checkbox" name="tg_notify" value="1"<?= $me['tg_notify'] ? ' checked' : '' ?> data-autosubmit> Присылать уведомления в Telegram</label>
    </form>
    <div class="form-actions">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="tg_test"><button class="btn btn--outline btn--sm" type="submit">Отправить пробное</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="tg_unlink"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Отключить Telegram? Уведомления туда перестанут приходить.">Отключить</button></form>
    </div>
<?php else:
    $code = $me['tg_link_code'] && $me['tg_link_expires'] > now() ? $me['tg_link_code'] : null;
    $botUrl = tg_bot_url($code);
?>
    <p class="hint">Заявки, сообщения гостей и уборка — сразу в Telegram, с кнопками «Подтвердить» и «Убрано».</p>
<?php if ($code): ?>
    <ol class="steps-list">
<?php if ($botUrl): ?>
        <li>Откройте на телефоне: <a class="btn btn--primary btn--sm" href="<?= e($botUrl) ?>" target="_blank" rel="noopener"><?= icon('send') ?>Бот в Telegram</a> и нажмите «Запустить» — код отправится сам.</li>
        <li>Если не сработало — отправьте боту код вручную:</li>
<?php else: ?>
        <li>Найдите бота гостиницы в Telegram и нажмите «Запустить».</li>
        <li>Отправьте ему этот код:</li>
<?php endif; ?>
    </ol>
    <p class="max-code" aria-label="Код для привязки"><?= e(implode(' ', str_split($code, 4))) ?></p>
    <p class="hint">Код действует 15 минут, до <?= e(substr((string) $me['tg_link_expires'], 11, 5)) ?>. Когда бот ответит «Готово» — обновите эту страницу.</p>
<?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="tg_code"><button class="btn btn--primary btn--sm" type="submit"><?= icon('link') ?>Подключить Telegram</button></form>
<?php endif; ?>
<?php endif; ?>
<?php if (is_owner() && tg_enabled() && setting('tg_last_error')): ?>
    <p class="hint">Последняя ошибка связи с Telegram: <?= e(setting('tg_last_error')) ?></p>
<?php endif; ?>
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
