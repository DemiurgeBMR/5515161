<?php
/**
 * Площадки: обмен занятостью с Авито, Суточно.ру и другими сайтами через
 * календари iCal, чтобы одну комнату не продали дважды.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

if (is_post()) {
    csrf_check();
    $action = post('action');
    $room = q_one('SELECT * FROM rooms WHERE id = ?', [(int) post('room_id')]);
    try {
        if ($action === 'save' && $room) {
            $urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s+/', post('urls'))))));
            foreach ($urls as $u) {
                if (!preg_match('#^https?://[^\s/]+\.[^\s/]+/?#i', $u) || strlen($u) > 1000) {
                    throw new BookingError('Не похоже на ссылку календаря: ' . mb_strimwidth($u, 0, 60, '…'));
                }
            }
            if (count($urls) > 5) {
                throw new BookingError('Не больше пяти площадок на одну комнату');
            }
            q_update('rooms', (int) $room['id'], ['ical_import_url' => $urls ? implode("\n", $urls) : null, 'ical_error' => '']);
            audit('room', (int) $room['id'], 'ical', $urls ? implode(', ', array_map(fn ($u) => parse_url($u, PHP_URL_HOST), $urls)) : 'ссылки удалены');
            if (!$urls) {
                q("DELETE FROM room_closures WHERE room_id = ? AND source = 'ical'", [$room['id']]);
                touch_data_version();
                flash('ok', 'Ссылки удалены, даты с площадок сняты.');
            } else {
                $err = ical_sync_room(q_one('SELECT * FROM rooms WHERE id = ?', [$room['id']]));
                flash($err ? 'error' : 'ok', $err ? 'Сохранено, но календарь не загрузился: ' . $err : 'Сохранено и загружено. Занятые на площадке даты закрыты в шахматке.');
            }
        } elseif ($action === 'sync') {
            $errors = array_filter(ical_sync_all());
            set_setting('cron_ical', (string) time());
            flash($errors ? 'error' : 'ok', $errors ? 'Не всё обновилось: ' . implode('; ', $errors) : 'Календари площадок обновлены.');
        } elseif ($action === 'new_key' && $room) {
            q_update('rooms', (int) $room['id'], ['ical_token' => bin2hex(random_bytes(16))]);
            audit('room', (int) $room['id'], 'ical', 'новая ссылка для площадок');
            flash('ok', 'Ссылка заменена. Старая больше не работает — вставьте новую на всех площадках.');
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/channels.php' . ($room ? '#room-' . (int) $room['id'] : ''));
}

$rooms = rooms_list(true);
$conflicts = ical_conflicts();
$lastSync = (int) setting('cron_ical', '0');
$hasImports = (bool) array_filter($rooms, fn ($r) => trim((string) $r['ical_import_url']) !== '');

admin_header('Площадки', 'channels');
?>
<div class="adm-head">
    <div>
        <h1>Площадки бронирования</h1>
        <p class="adm-head__sub">Если вы сдаёте номера ещё и через Авито, Суточно.ру и другие сайты — свяжите календари, чтобы одну комнату не продали дважды.</p>
    </div>
<?php if ($hasImports): ?>
    <form method="post" class="adm-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync">
        <button class="btn btn--outline btn--sm" type="submit"><?= icon('refresh-cw') ?>Обновить сейчас</button>
    </form>
<?php endif; ?>
</div>

<?php if ($conflicts): ?>
<section class="panel panel--warn">
    <h2 class="panel__title"><?= icon('triangle-alert') ?>Двойная продажа</h2>
    <p>Эти брони пересекаются с датами, занятыми на площадке. Договоритесь с одним из гостей о переносе или переселите его в другую комнату.</p>
    <ul class="blist">
<?php foreach ($conflicts as $c): ?>
        <li><a class="brow" href="/admin/booking.php?id=<?= (int) $c['id'] ?>"><span class="brow__name">Бронь №<?= (int) $c['id'] ?>, <?= e($c['room_name']) ?>: <?= e(ru_date_range($c['check_in'], $c['check_out'])) ?></span><span class="brow__meta">На площадке занято <?= e(ru_date_range($c['date_from'], $c['date_to'])) ?> — <?= e($c['reason']) ?></span></a></li>
<?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="panel">
    <h2 class="panel__title">Как это работает</h2>
    <ol class="steps-list">
        <li><b>Наш календарь → площадке.</b> Скопируйте ссылку комнаты ниже и вставьте её на площадке в разделе «Синхронизация календаря» (может называться «Импорт календаря», «iCal»). Площадка будет сама закрывать даты, когда комната занята у нас.</li>
        <li><b>Календарь площадки → нам.</b> На площадке там же возьмите ссылку «Экспорт календаря» и вставьте в поле комнаты. Занятые там даты закроются в шахматке и на сайте.</li>
        <li>Календари обновляются примерно раз в 15 минут — это не мгновенно. Если гость бронирует в последний момент, бронь из другого места может появиться с задержкой: такие пересечения сайт покажет на экране «Сегодня».</li>
    </ol>
    <p class="hint">Если на площадке нет синхронизации по iCal, заводите её брони вручную: «Новая бронь», источник — «Площадка бронирования». Последнее обновление: <?= $lastSync ? e(ru_datetime(date('Y-m-d H:i:s', $lastSync))) : 'ещё не было' ?>.</p>
</section>

<?php foreach ($rooms as $room):
    $rid = (int) $room['id'];
    $exportUrl = base_url() . '/ical.php?room=' . $rid . '&key=' . $room['ical_token'];
    $imported = (int) q_val("SELECT COUNT(*) FROM room_closures WHERE room_id = ? AND source = 'ical' AND date_to > ?", [$rid, today()->format('Y-m-d')]);
?>
<section class="panel" id="room-<?= $rid ?>">
    <h2 class="panel__title"><?= e($room['name']) ?> <small><?= e($room['type_name']) ?></small></h2>
    <div class="field">
        <label for="exp-<?= $rid ?>">Ссылка для площадок (наша занятость)</label>
        <div class="copy-row">
            <input id="exp-<?= $rid ?>" value="<?= e($exportUrl) ?>" readonly>
            <button class="btn btn--outline btn--sm" type="button" data-copy="#exp-<?= $rid ?>"><?= icon('copy') ?>Копировать</button>
        </div>
    </div>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="room_id" value="<?= $rid ?>">
        <div class="field span-all">
            <label for="imp-<?= $rid ?>">Календари площадок (по одной ссылке на строку)</label>
            <textarea id="imp-<?= $rid ?>" name="urls" rows="2" placeholder="https://www.avito.ru/…/calendar.ics"><?= e((string) $room['ical_import_url']) ?></textarea>
        </div>
<?php if (trim((string) $room['ical_import_url']) !== ''): ?>
        <p class="hint">
<?php if ($room['ical_error'] !== ''): ?>
            <span class="brow__flag">Ошибка: <?= e($room['ical_error']) ?></span> — проверьте ссылку. Пока ошибка, даты с площадки остаются закрытыми по последним данным.<br>
<?php endif; ?>
            Обновлено: <?= $room['ical_synced_at'] ? e(ru_datetime($room['ical_synced_at'])) : 'ещё нет' ?> · закрыто дат с площадок: <?= $imported ?>
        </p>
<?php endif; ?>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить и загрузить</button></div>
    </form>
    <form method="post" class="key-reset">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="new_key">
        <input type="hidden" name="room_id" value="<?= $rid ?>">
        <button class="link-button" type="submit" data-confirm="Старая ссылка перестанет работать на всех площадках. Заменить?">Заменить ссылку для площадок</button>
        <span class="hint">— если её увидел посторонний</span>
    </form>
</section>
<?php endforeach; ?>
<?php admin_footer(); ?>
