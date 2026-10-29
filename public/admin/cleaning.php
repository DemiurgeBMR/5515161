<?php
/**
 * Уборка — кабинет горничной и раздел администратора.
 *
 * Горничная видит, какие номера пора убрать, и отмечает «Убрано».
 * Администратор видит то же самое, тоже может отметить уборку, добавить
 * разовую (гость попросил) и настроить, как часто убирать у проживающих.
 *
 *   /admin/cleaning.php            — сегодня
 *   /admin/cleaning.php?day=next   — завтра (чтобы спланировать день)
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();
$hk = is_housekeeper($me);

$tomorrowView = get('day') === 'next';
$day = $tomorrowView ? today()->modify('+1 day')->format('Y-m-d') : today()->format('Y-m-d');
$self = '/admin/cleaning.php' . ($tomorrowView ? '?day=next' : '');

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        switch ($action) {
            case 'done':
            case 'skip':
                cleaning_mark((int) post('cleaning_id'), $action === 'done' ? 'done' : 'skipped');
                flash('ok', $action === 'done' ? 'Отмечено: номер убран. Спасибо!' : 'Уборка отмечена как ненужная.');
                break;
            case 'reopen':
                cleaning_reopen((int) post('cleaning_id'));
                flash('ok', 'Отметка снята — номер снова в списке «нужно убрать».');
                break;
            case 'add':
                cleaning_add((int) post('room_id'), post('date'), post('time'), post('note'));
                flash('ok', 'Уборка добавлена. Когда подойдёт время, горничной придёт письмо.');
                break;
            case 'settings':
                if ($hk) {
                    throw new BookingError('Настройки меняет администратор');
                }
                $every = max(0, min(30, (int) post('every')));
                $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', post('stay_time')) ? post('stay_time') : '11:00';
                set_setting('cleaning_every_days', (string) $every);
                set_setting('cleaning_stay_time', $time);
                audit('cleaning', null, 'settings', $every ? 'у проживающих — раз в ' . $every . ' ' . plural($every, 'день', 'дня', 'дней') . ', с ' . $time : 'у проживающих — только по просьбе');
                cleaning_sync(today()->format('Y-m-d'));
                flash('ok', 'Сохранено.');
                break;
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect($self);
}

cleaning_sync($day);
$list = cleaning_list($day);
$due = array_filter($list, fn ($c) => $c['status'] === 'pending' && ($c['is_due'] || $tomorrowView));
$later = $tomorrowView ? [] : array_filter($list, fn ($c) => $c['status'] === 'pending' && !$c['is_due']);
$finished = array_filter($list, fn ($c) => $c['status'] !== 'pending');
$rooms = rooms_list(true);

/** Карточка уборки. */
function cleaning_card(array $c, array $me, string $self): string
{
    $hk = is_housekeeper($me);
    $pending = $c['status'] === 'pending';
    $out = '<article class="clean-card' . ($c['urgent'] ? ' is-urgent' : '') . ($pending ? '' : ' is-finished') . '" id="cl-' . $c['id'] . '">';
    $out .= '<div class="clean-card__head"><h3 class="clean-card__room">' . e($c['place']) . '</h3>' . ($c['is_bath'] ? '' : '<span class="clean-card__type">' . e($c['type_name']) . '</span>') . '</div>';
    $out .= '<p class="clean-card__what">' . e(cleaning_when($c)) . '</p>';
    if ($c['arrival'] && $pending) {
        $a = $c['arrival'];
        $out .= '<p class="clean-card__arrival">' . icon($a['today'] ? 'triangle-alert' : 'calendar-days')
            . '<span>' . ($c['is_bath'] ? 'Следующий сеанс в ' . e($a['time']) . ' — успеть до него' : ($a['today'] ? 'Сегодня заезд с ' . e(hotel()['check_in']) . ' — успеть до заезда' : 'Заезд ' . e(ru_date($a['date'])))) . ' · '
            . $a['guests'] . ' ' . plural($a['guests'], 'гость', 'гостя', 'гостей') . '</span></p>';
    }
    if ($c['note'] !== '') {
        $out .= '<p class="clean-card__note">' . e($c['note']) . '</p>';
    }
    $form = function (string $action, string $label, string $class, string $confirm = '') use ($c, $self): string {
        return '<form method="post" action="' . e($self) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="cleaning_id" value="' . $c['id'] . '">'
            . '<button class="' . $class . '" type="submit"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $label . '</button></form>';
    };
    if ($pending) {
        $out .= '<div class="clean-card__actions">' . $form('done', icon('check') . 'Убрано', 'btn btn--primary clean-card__done');
        if (!$hk) {
            $out .= $form('skip', 'Не нужна', 'btn btn--outline btn--sm', 'Уборка «' . $c['place'] . '» не нужна?');
        }
        $out .= '</div>';
    } else {
        $who = $c['done_by_name'] ? ' — ' . $c['done_by_name'] : '';
        $out .= '<p class="clean-card__status">' . icon($c['status'] === 'done' ? 'circle-check' : 'ban')
            . '<span>' . e(CLEANING_STATUSES[$c['status']]) . ' в ' . e(substr((string) $c['done_at'], 11, 5)) . e($who) . '</span></p>';
        $canUndo = !$hk || ((int) $c['done_by'] === $me['id'] && strtotime((string) $c['done_at']) >= time() - CLEANING_UNDO_MINUTES * 60);
        if ($canUndo) {
            $out .= '<div class="clean-card__actions">' . $form('reopen', icon('undo-2') . 'Снять отметку', 'link-button') . '</div>';
        }
    }
    return $out . '</article>';
}

$dueIds = implode(',', array_map(fn ($c) => $c['id'], array_filter($due, fn ($c) => !$tomorrowView)));
$dueNames = implode(', ', array_map(fn ($c) => $c['place'], $due));

admin_header('Уборка', 'cleaning', 'adm-autoreload');
?>
<div class="adm-head">
    <div>
        <h1>Уборка · <?= e(ru_date($day)) ?></h1>
        <p class="adm-head__sub"><?= $hk ? 'Здравствуйте, ' . e($me['name']) . '! ' : '' ?>Убрали номер — нажмите «Убрано». Новые уборки появляются здесь сами<?= $hk ? ', а когда подходит время — приходит письмо' : '' ?>.</p>
    </div>
    <nav class="tabs" aria-label="День">
        <a class="tab<?= $tomorrowView ? '' : ' is-active' ?>" href="/admin/cleaning.php"<?= $tomorrowView ? '' : ' aria-current="page"' ?>>Сегодня</a>
        <a class="tab<?= $tomorrowView ? ' is-active' : '' ?>" href="/admin/cleaning.php?day=next"<?= $tomorrowView ? ' aria-current="page"' : '' ?>>Завтра</a>
    </nav>
</div>

<div class="clean-alert" data-clean-due="<?= e($dueIds) ?>" data-clean-names="<?= e($dueNames) ?>" hidden></div>

<section class="panel">
    <h2 class="panel__title"><?= $tomorrowView ? 'Уборки на завтра' : 'Пора убрать' ?> <small><?= count($due) ?></small></h2>
<?php if (!$due): ?>
    <p class="muted"><?= $tomorrowView ? 'На завтра уборок пока нет.' : 'Сейчас убирать нечего.' . ($later ? ' Следующие уборки — ниже.' : '') ?></p>
<?php else: ?>
    <div class="clean-grid">
<?php foreach ($due as $c): ?>
        <?= cleaning_card($c, $me, $self) ?>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</section>

<?php if ($later): ?>
<section class="panel">
    <h2 class="panel__title">Позже сегодня <small><?= count($later) ?></small></h2>
    <div class="clean-grid">
<?php foreach ($later as $c): ?>
        <?= cleaning_card($c, $me, $self) ?>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($finished): ?>
<section class="panel">
    <h2 class="panel__title">Готово <small><?= count($finished) ?></small></h2>
    <div class="clean-grid">
<?php foreach ($finished as $c): ?>
        <?= cleaning_card($c, $me, $self) ?>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($hk): ?>
<section class="panel">
    <h2 class="panel__title"><?= icon('bell') ?>Оповещения</h2>
    <p class="hint">Письмо о том, что пора убрать номер, приходит на <?= e($me['email']) ?><?= $me['notify'] ? '' : ' — <strong>сейчас письма выключены</strong>, включите их в <a href="/admin/profile.php">профиле</a>' ?>.</p>
    <p class="hint" data-clean-push-hint>Пока эта страница открыта на телефоне, она может ещё и показывать уведомление со звуком.</p>
    <button class="btn btn--outline btn--sm" type="button" data-clean-push hidden><?= icon('bell') ?>Включить уведомления на этом телефоне</button>
</section>
<?php else: ?>
<div class="two-col">
    <section class="panel" id="add">
        <h2 class="panel__title">Добавить уборку</h2>
        <p class="hint">Гость попросил убрать, генеральная уборка, замена белья. Горничной придёт письмо к указанному времени.</p>
        <form method="post" action="<?= e($self) ?>" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="field"><label for="c-room">Комната</label><select id="c-room" name="room_id" required>
<?php foreach ($rooms as $r): ?>
                <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?> · <?= e($r['type_name'] ?? '') ?></option>
<?php endforeach; ?>
<?php if (bath_enabled()): ?>
                <option value="0">Баня</option>
<?php endif; ?>
            </select></div>
            <div class="field"><label for="c-date">Дата</label><input id="c-date" type="date" name="date" required min="<?= e(today()->format('Y-m-d')) ?>" value="<?= e($day) ?>"></div>
            <div class="field"><label for="c-time">С какого времени</label><input id="c-time" type="time" name="time" required value="<?= e($tomorrowView ? cleaning_stay_time() : date('H:i')) ?>"></div>
            <div class="field span-all"><label for="c-note">Что сделать</label><input id="c-note" name="note" maxlength="255" placeholder="Например: поменять полотенца"></div>
            <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?>Добавить</button></div>
        </form>
    </section>
    <section class="panel" id="settings">
        <h2 class="panel__title">Как часто убирать</h2>
        <form method="post" action="<?= e($self) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">
            <p class="hint">После выезда гостя уборка появляется сама — в день выезда к <?= e(hotel()['check_out']) ?>, а если вы отметили «Выехал» раньше, то сразу.</p>
            <div class="field"><label for="c-every">У проживающих гостей — раз в сколько дней</label>
                <input id="c-every" type="number" name="every" min="0" max="30" value="<?= cleaning_every_days() ?>">
                <p class="field-hint">0 — только по просьбе гостя (добавляете вручную).</p></div>
            <div class="field"><label for="c-stay">Во сколько</label><input id="c-stay" type="time" name="stay_time" value="<?= e(cleaning_stay_time()) ?>"></div>
            <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
            <p class="hint">Письма горничным уходят, только если в разделе «Сотрудники» есть сотрудник с ролью «Горничная» и включёнными письмами.</p>
        </form>
    </section>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
