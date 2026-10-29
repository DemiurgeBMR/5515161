<?php
/**
 * Баня: расписание на день, новые заявки, запись по телефону, закрытие
 * времени; настройки (статус — только владелец), описание, фото, цены и
 * часы работы; лист ожидания открытия.
 *
 *   /admin/bath.php[?date=ГГГГ-ММ-ДД]   — расписание
 *   /admin/bath.php?tab=settings        — настройки и сайт
 *   /admin/bath.php?tab=waitlist        — кто ждёт открытия
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

if (!bath_enabled()) {
    http_response_code(404);
    admin_header('Баня', 'bath');
    echo '<div class="panel"><p>Баня выключена в config/hotel.php (bath → enabled).</p></div>';
    admin_footer();
    exit;
}

$tab = in_array(get('tab'), ['settings', 'waitlist'], true) ? get('tab') : 'schedule';
$date = date_value(get('date')) ?: today()->format('Y-m-d');

/** Фото из поля формы с несколькими файлами. */
function bath_uploaded_files(): array
{
    $f = $_FILES['photos'] ?? null;
    $out = [];
    if ($f && is_array($f['name'])) {
        foreach ($f['name'] as $i => $name) {
            if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $out[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }
    }
    return $out;
}

/** Поля записи из формы — общие для новой записи и карточки. */
function bath_form_input(): array
{
    return [
        'date' => post('date'), 'time' => post('time'), 'hours' => (int) post('hours'), 'guests' => (int) post('guests', '1'),
        'name' => mb_substr(post('name'), 0, 80), 'phone' => mb_substr(post('phone'), 0, 25), 'email' => mb_substr(post('email'), 0, 190),
        'contact_pref' => post('contact_pref', 'call'), 'hotel_guest' => post('hotel_guest') === '1',
        'extras' => bath_extras_from_input(is_array($_POST['extras'] ?? null) ? $_POST['extras'] : []),
        'price_manual' => post('price_manual') === '1', 'total_price' => post('total_price'),
        'comment' => post('comment'), 'admin_note' => post('admin_note'),
        'status' => post('status', 'confirmed'), 'source' => post('source', 'phone'),
    ];
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    $back = '/admin/bath.php' . ($tab !== 'schedule' ? '?tab=' . $tab : '?date=' . $date);
    try {
        switch ($action) {
            case 'create':
                $b = bath_create(bath_form_input(), true);
                $sent = false;
                if (post('notify') === '1' && $b['guest_email']) {
                    [$subject, $text] = $b['status'] === 'new' ? mail_bath_received($b) : mail_bath_confirmed($b);
                    $sent = send_mail($b['guest_email'], $subject, $text);
                    audit('bath', $b['id'], 'mail', ($sent ? 'отправлено письмо: ' : 'НЕ отправлено письмо: ') . $subject);
                }
                flash('ok', 'Записали: ' . bath_when($b) . '.' . ($sent ? ' Гостю отправлено письмо.' : ''));
                redirect('/admin/bath_booking.php?id=' . $b['id']);
                break;
            case 'block':
                bath_block(post('date'), post('from'), post('to'), post('reason'));
                flash('ok', 'Время закрыто — на сайте на него не записаться.');
                break;
            case 'unblock':
                bath_unblock((int) post('bath_id'));
                flash('ok', 'Время снова открыто для записи.');
                break;
            case 'confirm':
                $b = bath_set_status((int) post('bath_id'), 'confirmed');
                $sent = false;
                if (post('notify') === '1' && $b['guest_email']) {
                    [$subject, $text] = mail_bath_confirmed($b);
                    $sent = send_mail($b['guest_email'], $subject, $text);
                    audit('bath', $b['id'], 'mail', ($sent ? 'отправлено письмо: ' : 'НЕ отправлено письмо: ') . $subject);
                }
                flash('ok', 'Запись подтверждена.' . ($sent ? ' Гостю отправлено письмо.' : ''));
                break;
            case 'state':
                bath_set_state(post('state'));
                flash('ok', 'Баня: ' . mb_strtolower(BATH_STATES[post('state')]) . '. На сайте уже обновилось.'
                    . (post('state') === 'open' && ($n = (int) q_val("SELECT COUNT(*) FROM waitlist WHERE topic = 'bath'")) ? ' Открытия ждут ' . $n . ' ' . plural($n, 'человек', 'человека', 'человек') . ' — их телефоны во вкладке «Ждут открытия».' : ''));
                break;
            case 'texts':
                if (trim(post('title')) === '') {
                    throw new BookingError('Укажите название');
                }
                set_setting('bath_title', clean_line(post('title'), 60));
                set_setting('bath_lead', mb_substr(trim(post('lead')), 0, 600));
                set_setting('bath_features', implode("\n", array_map(fn ($l) => mb_substr($l, 0, 120), array_slice(bath_lines(post('features')), 0, 15))));
                set_setting('bath_rules', implode("\n", array_map(fn ($l) => mb_substr($l, 0, 200), array_slice(bath_lines(post('rules')), 0, 15))));
                set_setting('bath_opening', clean_line(post('opening'), 60));
                set_setting('bath_closed_note', mb_substr(trim(post('closed_note')), 0, 300));
                audit('bath', null, 'settings', 'описание на сайте');
                touch_data_version();
                flash('ok', 'Сохранено. На сайте уже обновилось.');
                break;
            case 'prices':
                $num = fn (string $k) => (int) preg_replace('/\D+/', '', post($k));
                $days = implode('', array_filter(array_map('intval', is_array($_POST['days'] ?? null) ? $_POST['days'] : []), fn ($d) => $d >= 1 && $d <= 7));
                $from = max(0, min(23, (int) post('open_from')));
                $to = max(1, min(24, (int) post('open_to')));
                $min = max(1, min(12, (int) post('min_hours')));
                if ($to - $from < $min) {
                    throw new BookingError('Время работы короче минимального сеанса — проверьте часы');
                }
                if ($days === '' && bath_state() === 'open') {
                    throw new BookingError('Отметьте хотя бы один рабочий день — баня сейчас открыта');
                }
                $old = bath_settings();
                foreach ([
                    'price' => $num('price'), 'price_weekend' => $num('price_weekend'), 'guests_included' => max(1, $num('guests_included')),
                    'extra_guest' => $num('extra_guest'), 'max_guests' => max(1, $num('max_guests')), 'min_hours' => $min,
                    'max_hours' => max($min, min(24, (int) post('max_hours'))), 'open_from' => $from, 'open_to' => $to,
                    'buffer' => max(0, min(240, (int) post('buffer'))), 'lead_hours' => max(0, min(72, (int) post('lead_hours'))),
                    'hotel_discount' => max(0, min(100, (int) post('hotel_discount'))),
                ] as $k => $v) {
                    set_setting('bath_' . $k, (string) $v);
                }
                set_setting('bath_days', $days);
                set_setting('bath_extras', implode("\n", array_map(fn ($x) => $x['name'] . ' — ' . $x['price'], bath_parse_extras(post('extras')))));
                $new = bath_settings();
                audit('bath', null, 'settings', 'цены и время' . ($old['price'] !== $new['price'] ? ': час ' . money($old['price']) . ' → ' . money($new['price']) : ''));
                touch_data_version();
                flash('ok', 'Сохранено. Уже записанные сеансы не пересчитываются.');
                break;
            case 'upload':
                $files = bath_uploaded_files();
                if (!$files) {
                    throw new BookingError('Выберите фото');
                }
                $sort = (int) q_val('SELECT COALESCE(MAX(sort), 0) FROM bath_photos');
                foreach ($files as $file) {
                    $up = upload_photo($file, 'bath');
                    q_insert('bath_photos', ['path' => $up['path'], 'width' => $up['width'], 'height' => $up['height'], 'sort' => ++$sort, 'created_at' => now()]);
                }
                audit('bath', null, 'photos_added', (string) count($files));
                touch_data_version();
                flash('ok', 'Загружено фото: ' . count($files) . '.');
                break;
            case 'photo_cover':
                $min = (int) q_val('SELECT COALESCE(MIN(sort), 0) FROM bath_photos');
                q('UPDATE bath_photos SET sort = ? WHERE id = ?', [$min - 1, (int) post('photo_id')]);
                touch_data_version();
                flash('ok', 'Это фото теперь первое — его видно на главной и первым в галерее.');
                break;
            case 'photo_delete':
                $p = q_one('SELECT * FROM bath_photos WHERE id = ?', [(int) post('photo_id')]);
                if ($p) {
                    q('DELETE FROM bath_photos WHERE id = ?', [$p['id']]);
                    delete_upload($p['path']);
                    audit('bath', null, 'photo_deleted');
                    touch_data_version();
                    flash('ok', 'Фото удалено.');
                }
                break;
            case 'waitlist_done':
                q("DELETE FROM waitlist WHERE id = ? AND topic = 'bath'", [(int) post('wid')]);
                flash('ok', 'Убрали из списка.');
                break;
            case 'waitlist_clear':
                $n = q("DELETE FROM waitlist WHERE topic = 'bath'")->rowCount();
                audit('bath', null, 'waitlist', 'список очищен, телефонов: ' . $n);
                flash('ok', 'Список очищен.');
                break;
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    $anchor = ['texts' => '#texts', 'prices' => '#prices', 'upload' => '#photos', 'photo_cover' => '#photos', 'photo_delete' => '#photos', 'state' => '#state', 'block' => '#block'][$action] ?? '';
    redirect($back . $anchor);
}

$s = bath_settings();
$waitCount = (int) q_val("SELECT COUNT(*) FROM waitlist WHERE topic = 'bath'");

/** Плашка статуса записи. */
function bath_badge(array $b): string
{
    if ($b['is_block']) {
        return '<span class="status status--cancelled">Закрыто</span>';
    }
    return '<span class="status status--' . e($b['status']) . '">' . e($b['status_label']) . '</span>';
}

/** Строка записи в списке. */
function bath_row_html(array $b, bool $withDate = false): string
{
    if ($b['is_block']) {
        return '<a class="brow" href="/admin/bath_booking.php?id=' . $b['id'] . '"><span class="brow__name">' . e(($withDate ? ru_date($b['date']) . ', ' : '') . $b['time_from'] . '–' . $b['time_to']) . '</span>'
            . bath_badge($b) . '<span class="brow__meta">' . e($b['admin_note'] ?: 'время закрыто') . '</span></a>';
    }
    $money = $b['total_price'] !== null ? money($b['total_price']) . ($b['paid'] > 0 ? ', оплачено ' . money($b['paid']) : '') : '';
    return '<a class="brow" href="/admin/bath_booking.php?id=' . $b['id'] . '">'
        . '<span class="brow__name">' . e(($withDate ? ru_date($b['date']) . ', ' : '') . $b['time_from'] . '–' . $b['time_to']) . ' · ' . e($b['guest_name'] ?? 'Гость') . '</span>'
        . bath_badge($b)
        . '<span class="brow__meta">' . e($b['guest_phone'] ?? '') . ' · ' . $b['guests_count'] . ' ' . plural($b['guests_count'], 'гость', 'гостя', 'гостей')
        . ($money !== '' ? ' · ' . e($money) : '') . ($b['hotel_guest'] ? ' · <span class="brow__flag">живёт в гостинице</span>' : '') . '</span></a>';
}

admin_header('Баня', 'bath', $tab === 'schedule' ? 'adm-autoreload' : '');
?>
<div class="adm-head">
    <div>
        <h1><?= e($s['title']) ?> <span class="state state--<?= e($s['state']) ?>"><?= e(BATH_STATES[$s['state']]) ?></span></h1>
        <p class="adm-head__sub"><?= $s['state'] === 'open' ? 'Запись с сайта открыта · ' . e(bath_hours_text()) . ' · ' . e(bath_price_from()) : 'Запись с сайта не принимается. Записать гостя по телефону можно и сейчас.' ?> · <a href="/bath.php" target="_blank" rel="noopener">Как выглядит на сайте</a></p>
    </div>
    <nav class="tabs" aria-label="Разделы бани">
        <a class="tab<?= $tab === 'schedule' ? ' is-active' : '' ?>" href="/admin/bath.php">Расписание</a>
        <a class="tab<?= $tab === 'settings' ? ' is-active' : '' ?>" href="/admin/bath.php?tab=settings">Настройки и сайт</a>
        <a class="tab<?= $tab === 'waitlist' ? ' is-active' : '' ?>" href="/admin/bath.php?tab=waitlist">Ждут открытия<?= $waitCount ? ' <b>' . $waitCount . '</b>' : '' ?></a>
    </nav>
</div>

<?php if ($tab === 'schedule'):
    $d = parse_date($date);
    $prevDay = $d->modify('-1 day')->format('Y-m-d');
    $nextDay = $d->modify('+1 day')->format('Y-m-d');
    $day = bath_list($date, $nextDay, true);
    $free = bath_slots($date, false);
    $new = array_map('bath_hydrate', q_all(bath_select_sql() . " WHERE x.status = 'new' ORDER BY x.starts_at LIMIT 50"));
    $upcoming = bath_list(today()->modify('+1 day')->format('Y-m-d'), today()->modify('+15 days')->format('Y-m-d'));
    // Сколько сеансов в каждый из ближайших 14 дней — для полоски дней.
    $counts = [];
    foreach (q_all("SELECT DATE(starts_at) AS d, COUNT(*) AS n FROM bath_bookings WHERE source <> 'block' AND status IN ('new', 'confirmed', 'done')
                    AND starts_at >= ? AND starts_at < ? GROUP BY DATE(starts_at)", [today()->format('Y-m-d') . ' 00:00:00', today()->modify('+14 days')->format('Y-m-d') . ' 00:00:00']) as $r) {
        $counts[$r['d']] = (int) $r['n'];
    }
?>
<?php if ($new): ?>
<section class="panel panel--warn">
    <h2 class="panel__title"><?= icon('bell') ?> Новые заявки — подтвердите или позвоните гостю</h2>
    <div class="blist">
<?php foreach ($new as $b): ?>
        <div class="brow-wrap">
            <?= bath_row_html($b, true) ?>
            <form method="post" class="inline brow-wrap__action"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="bath_id" value="<?= $b['id'] ?>">
<?php if ($b['guest_email']): ?><input type="hidden" name="notify" value="1"><?php endif; ?>
                <button class="btn btn--primary btn--sm" type="submit"><?= icon('check') ?>Подтвердить<?= $b['guest_email'] ? ' и написать гостю' : '' ?></button></form>
        </div>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="days-strip" role="navigation" aria-label="Ближайшие дни">
<?php for ($i = 0; $i < 14; $i++): $dd = today()->modify('+' . $i . ' days'); $ds = $dd->format('Y-m-d'); ?>
    <a class="days-strip__day<?= $ds === $date ? ' is-active' : '' ?><?= str_contains($s['days'], $dd->format('N')) ? '' : ' is-off' ?>" href="/admin/bath.php?date=<?= $ds ?>">
        <small><?= e(RU_WEEKDAYS_SHORT[(int) $dd->format('w')]) ?></small><b><?= $dd->format('j') ?></b><span><?= !empty($counts[$ds]) ? $counts[$ds] . ' ' . plural($counts[$ds], 'сеанс', 'сеанса', 'сеансов') : '—' ?></span>
    </a>
<?php endfor; ?>
</div>

<div class="main-side">
    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title"><?= e(ru_date($date, true)) ?>, <?= e(RU_WEEKDAYS[(int) $d->format('w')]) ?></h2>
            <form method="get" class="day-nav">
                <a class="btn btn--outline btn--sm" href="/admin/bath.php?date=<?= $prevDay ?>" aria-label="Предыдущий день">←</a>
                <input type="date" name="date" value="<?= e($date) ?>" aria-label="Дата" data-autosubmit>
                <a class="btn btn--outline btn--sm" href="/admin/bath.php?date=<?= $nextDay ?>" aria-label="Следующий день">→</a>
            </form>
        </div>
<?php if (!str_contains($s['days'], $d->format('N'))): ?>
        <p class="hint">По расписанию в этот день баня не работает — на сайте записаться нельзя, по телефону можно.</p>
<?php endif; ?>
<?php if (!$day): ?>
        <p class="empty">Записей нет.</p>
<?php else: ?>
        <div class="blist">
<?php foreach ($day as $b): ?>
            <?= bath_row_html($b) ?>
<?php endforeach; ?>
        </div>
<?php endif; ?>
<?php if ($free['slots']): ?>
        <h3 class="panel__subtitle">Можно начать <small class="muted">— с учётом перерыва на уборку <?= e(bath_duration($s['buffer'])) ?></small></h3>
        <div class="free-times">
<?php foreach ($free['slots'] as $sl): ?>
            <a class="tab" href="?date=<?= e($date) ?>&amp;time=<?= e($sl['time']) ?>#new" title="до <?= $sl['max_hours'] ?> ч"><?= e($sl['time']) ?> <small>до <?= $sl['max_hours'] ?> ч</small></a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
<?php if ($upcoming): ?>
        <h3 class="panel__subtitle">Ближайшие две недели</h3>
        <div class="blist">
<?php foreach ($upcoming as $b): ?>
            <?= bath_row_html($b, true) ?>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>

    <div class="blist">
        <section class="panel" id="new">
            <h2 class="panel__title">Записать гостя</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="field"><label for="n-date">Дата</label><input id="n-date" type="date" name="date" required value="<?= e($date) ?>"></div>
                <div class="field"><label for="n-time">Начало</label><select id="n-time" name="time">
<?php $want = preg_match('/^\d{2}:\d{2}$/', get('time')) ? get('time') : bath_hour($s['open_from']); for ($m = 0; $m < 24 * 60; $m += 30): $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
                    <option<?= $t === $want ? ' selected' : '' ?>><?= $t ?></option>
<?php endfor; ?>
                </select></div>
                <div class="field"><label for="n-hours">Часов</label><input id="n-hours" type="number" name="hours" min="1" max="12" value="<?= $s['min_hours'] ?>"></div>
                <div class="field"><label for="n-guests">Гостей</label><input id="n-guests" type="number" name="guests" min="1" max="50" value="2"></div>
                <div class="field span-all"><label for="n-name">Имя</label><input id="n-name" name="name" required maxlength="80"></div>
                <div class="field"><label for="n-phone">Телефон</label><input id="n-phone" type="tel" name="phone" maxlength="25"></div>
                <div class="field"><label for="n-email">Почта</label><input id="n-email" type="email" name="email" maxlength="190"></div>
<?php foreach ($s['extras'] as $i => $x): ?>
                <div class="field"><label for="n-x<?= $i ?>"><?= e($x['name']) ?>, шт.</label><input id="n-x<?= $i ?>" type="number" name="extras[<?= $i ?>]" min="0" max="20" value="0"></div>
<?php endforeach; ?>
                <div class="field"><label for="n-status">Статус</label><select id="n-status" name="status"><option value="confirmed">Подтверждена</option><option value="new">Заявка</option></select></div>
                <div class="field"><label for="n-source">Откуда</label><select id="n-source" name="source">
<?php foreach (BOOKING_SOURCES as $k => $label): if ($k === 'site' || $k === 'ota') continue; ?>
                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                </select></div>
                <div class="field span-all"><label for="n-note">Заметка</label><input id="n-note" name="admin_note" maxlength="500" placeholder="Видна только сотрудникам"></div>
                <div class="field span-all">
<?php if ($s['hotel_discount'] > 0): ?>
                    <label class="inline-check"><input type="checkbox" name="hotel_guest" value="1"> Живёт в гостинице — скидка <?= $s['hotel_discount'] ?>%</label>
<?php endif; ?>
                    <label class="inline-check"><input type="checkbox" name="notify" value="1" checked> Отправить гостю письмо (если указана почта)</label>
                </div>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?>Записать</button></div>
            </form>
            <p class="hint">Сумма посчитается по ценам из настроек. Свою сумму можно поставить в карточке записи.</p>
        </section>
        <section class="panel" id="block">
            <h2 class="panel__title">Закрыть время</h2>
            <p class="hint">Ремонт, санитарный день, своё мероприятие — на это время на сайте не записаться.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="block">
                <div class="field"><label for="k-date">Дата</label><input id="k-date" type="date" name="date" required value="<?= e($date) ?>"></div>
                <div class="field"><label for="k-from">С</label><input id="k-from" type="time" name="from" required value="<?= e(bath_hour($s['open_from'])) ?>"></div>
                <div class="field"><label for="k-to">До</label><input id="k-to" type="time" name="to" required value="<?= e($s['open_to'] === 24 ? '23:59' : bath_hour($s['open_to'])) ?>"></div>
                <div class="field span-all"><label for="k-reason">Причина</label><input id="k-reason" name="reason" maxlength="200" placeholder="Например: санитарный день"></div>
                <div class="form-actions span-all"><button class="btn btn--outline btn--sm" type="submit"><?= icon('lock') ?>Закрыть</button></div>
            </form>
        </section>
    </div>
</div>

<?php elseif ($tab === 'settings'):
    $photos = bath_photos();
?>
<div class="main-side">
    <div class="blist">
        <section class="panel" id="state">
            <h2 class="panel__title">Статус бани</h2>
<?php if (is_owner()): ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="state">
                <div class="state-pick">
<?php foreach (BATH_STATES as $k => $label): ?>
                    <label class="state-pick__item"><input type="radio" name="state" value="<?= e($k) ?>"<?= $s['state'] === $k ? ' checked' : '' ?>>
                        <span><strong><?= e($label) ?></strong><small><?= e(['soon' => 'на сайте фото, описание и «Сообщить об открытии»', 'open' => 'на сайте цены и запись на свободное время', 'closed' => 'запись не принимается, на сайте — пояснение'][$k]) ?></small></span></label>
<?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить статус</button></div>
            </form>
<?php else: ?>
            <p><span class="state state--<?= e($s['state']) ?>"><?= e(BATH_STATES[$s['state']]) ?></span></p>
            <p class="hint">Открыть или закрыть баню может только владелец. Остальное — описание, фото, цены — можно менять и вам.</p>
<?php endif; ?>
            <h3 class="panel__subtitle">Перед открытием</h3>
            <ul class="ready-list">
<?php foreach (bath_checklist() as [$ok, $text]): ?>
                <li class="<?= $ok ? 'is-ok' : 'is-todo' ?>"><?= icon($ok ? 'circle-check' : 'triangle-alert') ?><span><?= e($text) ?></span></li>
<?php endforeach; ?>
            </ul>
        </section>

        <section class="panel" id="texts">
            <h2 class="panel__title">Описание на сайте</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="texts">
                <div class="field span-all"><label for="s-title">Название</label><input id="s-title" name="title" required maxlength="60" value="<?= e($s['title']) ?>"></div>
                <div class="field span-all"><label for="s-lead">Коротко</label><textarea id="s-lead" name="lead" rows="3" maxlength="600"><?= e($s['lead']) ?></textarea></div>
                <div class="field span-all"><label for="s-features">Что внутри — по одному пункту на строку</label><textarea id="s-features" name="features" rows="5"><?= e(implode("\n", $s['features'])) ?></textarea></div>
                <div class="field span-all"><label for="s-rules">Правила — по одному на строку</label><textarea id="s-rules" name="rules" rows="5"><?= e(implode("\n", $s['rules'])) ?></textarea></div>
                <div class="field"><label for="s-opening">Когда откроемся</label><input id="s-opening" name="opening" maxlength="60" value="<?= e($s['opening']) ?>" placeholder="весной 2027"><p class="field-hint">Видно, пока статус «Скоро открытие».</p></div>
                <div class="field"><label for="s-closed">Почему закрыта</label><input id="s-closed" name="closed_note" maxlength="300" value="<?= e($s['closed_note']) ?>" placeholder="Профилактика до 15 мая"><p class="field-hint">Видно при статусе «Временно закрыта».</p></div>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
            </form>
        </section>

        <section class="panel" id="prices">
            <h2 class="panel__title">Цены и время работы</h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="prices">
                <div class="field"><label for="p-price">Час, будни, ₽</label><input id="p-price" name="price" inputmode="numeric" value="<?= $s['price'] ?: '' ?>" placeholder="2000"></div>
                <div class="field"><label for="p-weekend">Час, сб и вс, ₽</label><input id="p-weekend" name="price_weekend" inputmode="numeric" value="<?= $s['price_weekend'] ?: '' ?>" placeholder="как в будни"></div>
                <div class="field"><label for="p-incl">Гостей в цене</label><input id="p-incl" name="guests_included" type="number" min="1" max="50" value="<?= $s['guests_included'] ?>"></div>
                <div class="field"><label for="p-extra">Доплата за гостя сверх, ₽ в час</label><input id="p-extra" name="extra_guest" inputmode="numeric" value="<?= $s['extra_guest'] ?>"></div>
                <div class="field"><label for="p-max">Максимум гостей</label><input id="p-max" name="max_guests" type="number" min="1" max="50" value="<?= $s['max_guests'] ?>"></div>
                <div class="field"><label for="p-disc">Скидка гостям гостиницы, %</label><input id="p-disc" name="hotel_discount" type="number" min="0" max="100" value="<?= $s['hotel_discount'] ?>"></div>
                <div class="field"><label for="p-minh">Сеанс от, часов</label><input id="p-minh" name="min_hours" type="number" min="1" max="12" value="<?= $s['min_hours'] ?>"></div>
                <div class="field"><label for="p-maxh">Сеанс до, часов</label><input id="p-maxh" name="max_hours" type="number" min="1" max="24" value="<?= $s['max_hours'] ?>"></div>
                <div class="field"><label for="p-from">Открыта с, час</label><input id="p-from" name="open_from" type="number" min="0" max="23" value="<?= $s['open_from'] ?>"></div>
                <div class="field"><label for="p-to">До, час</label><input id="p-to" name="open_to" type="number" min="1" max="24" value="<?= $s['open_to'] ?>"></div>
                <div class="field"><label for="p-buf">Перерыв между сеансами, минут</label><input id="p-buf" name="buffer" type="number" min="0" max="240" step="15" value="<?= $s['buffer'] ?>"><p class="field-hint">Уборка и протопка.</p></div>
                <div class="field"><label for="p-lead">Записаться с сайта не позже чем за, часов</label><input id="p-lead" name="lead_hours" type="number" min="0" max="72" value="<?= $s['lead_hours'] ?>"></div>
                <fieldset class="span-all weekdays"><legend>Дни работы</legend>
                    <div class="weekdays__list">
<?php foreach (BATH_WEEKDAYS as $n => $label): ?>
                        <label class="inline-check"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= str_contains($s['days'], (string) $n) ? ' checked' : '' ?>> <?= e($label) ?></label>
<?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="field span-all"><label for="p-extras">Дополнительные услуги — «название — цена», по одной на строку</label><textarea id="p-extras" name="extras" rows="4"><?= e(implode("\n", array_map(fn ($x) => $x['name'] . ' — ' . $x['price'], $s['extras']))) ?></textarea></div>
                <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
            </form>
        </section>
    </div>

    <section class="panel" id="photos">
        <h2 class="panel__title">Фото <small><?= count($photos) ?></small></h2>
<?php if ($photos): ?>
        <div class="photo-grid">
<?php foreach ($photos as $i => $p): ?>
            <figure>
                <img src="/<?= e(upload_small($p['path']) ?? $p['path']) ?>" alt="Фото <?= $i + 1 ?>" loading="lazy">
<?php if ($i === 0): ?><span class="is-cover">Главное</span><?php endif; ?>
                <figcaption>
<?php if ($i > 0): ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="photo_cover"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                        <button class="icon-button" type="submit" aria-label="Сделать главным" title="Сделать главным"><?= icon('star') ?></button></form>
<?php endif; ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                        <button class="icon-button" type="submit" aria-label="Удалить фото" data-confirm="Удалить фото?"><?= icon('trash-2') ?></button></form>
                </figcaption>
            </figure>
<?php endforeach; ?>
        </div>
<?php else: ?>
        <p class="empty">Фото пока нет — на сайте показываются заглушки.</p>
<?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="field span-all"><label for="b-photos">Добавить фото (можно несколько)</label><input id="b-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></div>
            <p class="hint span-all">Первые два фото видны на главной, все — в галерее на странице бани. Снимайте парную, хамам, купель и комнату отдыха при тёплом свете.</p>
            <div class="form-actions span-all"><button class="btn btn--outline btn--sm" type="submit"><?= icon('upload') ?>Загрузить</button></div>
        </form>
    </section>
</div>

<?php else:
    $rows = q_all("SELECT * FROM waitlist WHERE topic = 'bath' ORDER BY id DESC");
?>
<section class="panel">
    <h2 class="panel__title">Просили сообщить об открытии <small><?= count($rows) ?></small></h2>
<?php if ($s['state'] === 'open' && $rows): ?>
    <div class="notice notice--ok"><?= icon('bell') ?><div>Баня открыта — позвоните или напишите этим людям, а потом отметьте «Сообщили».</div></div>
<?php endif; ?>
<?php if (!$rows): ?>
    <p class="empty">Список пуст.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Телефон</th><th>Когда оставил</th><th></th></tr></thead>
            <tbody>
<?php foreach ($rows as $w): ?>
                <tr>
                    <td><?= guest_contacts_html($w['phone'], null) ?></td>
                    <td><?= e(ru_datetime($w['created_at'])) ?></td>
                    <td class="num"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="waitlist_done"><input type="hidden" name="wid" value="<?= (int) $w['id'] ?>">
                        <button class="btn btn--outline btn--sm" type="submit"><?= icon('check') ?>Сообщили</button></form></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="waitlist_clear">
        <button class="btn btn--danger btn--sm" type="submit" data-confirm="Удалить все телефоны из списка?"><?= icon('trash-2') ?>Очистить список</button></form>
<?php endif; ?>
    <p class="hint">Телефоны хранятся только для того, чтобы сообщить об открытии. Сообщили — уберите номер из списка.</p>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
