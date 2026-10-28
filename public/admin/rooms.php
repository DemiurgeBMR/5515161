<?php
/**
 * Номера и фото: типы номеров (то, что видит гость на сайте), настоящие
 * комнаты (то, что занимают брони) и фото на главной.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

const SITE_PHOTO_SLOTS = ['hero' => 'Первый экран (арка)', 'bath' => 'Баня', 'hammam' => 'Хамам'];

/** Фото из поля формы с несколькими файлами — списком отдельных файлов. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    }
    return $out;
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'type_add') {
            $name = trim(post('name'));
            if ($name === '') {
                throw new BookingError('Укажите название');
            }
            $id = q_insert('room_types', [
                'slug' => 'type-' . bin2hex(random_bytes(4)), 'name' => mb_substr($name, 0, 100),
                'base_price' => max(0, (int) post('base_price')), 'capacity' => max(1, min(20, (int) post('capacity', '2'))),
                'sort' => (int) q_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM room_types'),
            ]);
            audit('room_type', $id, 'created', $name);
            flash('ok', 'Тип «' . $name . '» добавлен. Заполните описание и загрузите фото.');
            redirect('/admin/room_type.php?id=' . $id);
        } elseif ($action === 'room_add') {
            $typeId = (int) post('room_type_id');
            if (!room_type($typeId) || trim(post('name')) === '') {
                throw new BookingError('Укажите название комнаты и её тип');
            }
            $rid = q_insert('rooms', ['room_type_id' => $typeId, 'name' => mb_substr(trim(post('name')), 0, 50), 'note' => mb_substr(post('note'), 0, 255),
                'sort' => (int) q_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM rooms'), 'ical_token' => bin2hex(random_bytes(16))]);
            audit('room', $rid, 'created', post('name'));
            touch_data_version();
            flash('ok', 'Комната «' . post('name') . '» добавлена и сразу продаётся.');
        } elseif ($action === 'room_save') {
            $rid = (int) post('room_id');
            $room = q_one('SELECT * FROM rooms WHERE id = ?', [$rid]);
            $typeId = (int) post('room_type_id');
            if (!$room || !room_type($typeId) || trim(post('name')) === '') {
                throw new BookingError('Проверьте название и тип комнаты');
            }
            $active = post('is_active') === '1' ? 1 : 0;
            if ($typeId !== (int) $room['room_type_id'] && q_val("SELECT id FROM bookings WHERE room_id = ? AND status IN (" . occupying_in() . ") AND check_out > ? LIMIT 1", [$rid, today()->format('Y-m-d')])) {
                throw new BookingError('У комнаты есть будущие брони — сначала перенесите их, потом меняйте тип');
            }
            q_update('rooms', $rid, ['name' => mb_substr(trim(post('name')), 0, 50), 'room_type_id' => $typeId, 'note' => mb_substr(post('note'), 0, 255), 'is_active' => $active, 'sort' => (int) post('sort')]);
            audit('room', $rid, 'updated', post('name') . ($active ? '' : ' (не продаётся)'));
            touch_data_version();
            flash('ok', 'Комната сохранена.');
        } elseif ($action === 'room_delete') {
            $rid = (int) post('room_id');
            $room = q_one('SELECT * FROM rooms WHERE id = ?', [$rid]);
            if (q_val('SELECT id FROM bookings WHERE room_id = ? LIMIT 1', [$rid])) {
                throw new BookingError('У комнаты есть брони — удалить нельзя. Снимите галочку «продаётся», чтобы скрыть её.');
            }
            q('DELETE FROM rooms WHERE id = ?', [$rid]);
            audit('room', $rid, 'deleted', $room['name'] ?? null);
            touch_data_version();
            flash('ok', 'Комната удалена.');
        } elseif ($action === 'site_photo') {
            $slot = post('slot');
            if (!isset(SITE_PHOTO_SLOTS[$slot])) {
                throw new BookingError('Неизвестное место для фото');
            }
            $files = uploaded_files('photo');
            if (!$files) {
                throw new BookingError('Выберите файл');
            }
            $up = upload_photo($files[0], 'site');
            delete_upload(setting('photo_' . $slot));
            set_setting('photo_' . $slot, $up['path']);
            audit('settings', null, 'site_photo', SITE_PHOTO_SLOTS[$slot]);
            flash('ok', 'Фото «' . SITE_PHOTO_SLOTS[$slot] . '» обновлено.');
        } elseif ($action === 'site_photo_delete') {
            $slot = post('slot');
            if (isset(SITE_PHOTO_SLOTS[$slot])) {
                delete_upload(setting('photo_' . $slot));
                set_setting('photo_' . $slot, null);
                flash('ok', 'Фото удалено — на сайте снова заглушка.');
            }
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/rooms.php' . (str_starts_with($action, 'room') ? '#rooms' : (str_starts_with($action, 'site') ? '#site-photos' : '')));
}

$types = room_types(false);
$rooms = rooms_list(false);
$roomCount = [];
foreach ($rooms as $r) {
    if ($r['is_active']) {
        $roomCount[(int) $r['room_type_id']] = ($roomCount[(int) $r['room_type_id']] ?? 0) + 1;
    }
}

admin_header('Номера и фото', 'rooms');
?>
<div class="adm-head">
    <div>
        <h1>Номера и фото</h1>
        <p class="adm-head__sub">Тип номера — то, что видит гость на сайте (описание, фото, цена). Комнаты — настоящие номера, их занимают брони.</p>
    </div>
</div>

<section class="panel">
    <h2 class="panel__title">Типы номеров</h2>
    <div class="table-wrap">
        <table class="table table--cards">
            <thead><tr><th>Тип</th><th class="num">Цена за ночь</th><th class="num">Гостей</th><th class="num">Комнат</th><th class="num">Фото</th><th>На сайте</th><th></th></tr></thead>
            <tbody>
<?php foreach ($types as $t): ?>
                <tr class="<?= $t['is_active'] ? '' : 'is-muted' ?>">
                    <td data-label="Тип"><a href="/admin/room_type.php?id=<?= $t['id'] ?>"><?= e($t['name']) ?></a></td>
                    <td data-label="Цена за ночь" class="num"><?= e(type_price_label($t)) ?></td>
                    <td data-label="Гостей" class="num">до <?= $t['capacity'] ?></td>
                    <td data-label="Комнат" class="num"><?= $roomCount[$t['id']] ?? 0 ?></td>
                    <td data-label="Фото" class="num"><?= count($t['photos']) ?></td>
                    <td data-label="На сайте"><?= $t['is_active'] ? 'показывается' : 'скрыт' ?></td>
                    <td data-label="" class="num"><a class="btn btn--outline btn--sm" href="/admin/room_type.php?id=<?= $t['id'] ?>"><?= icon('pencil') ?>Изменить</a></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="type_add">
        <div class="field"><label for="t-name">Новый тип номера</label><input id="t-name" name="name" required placeholder="Например: Семейный"></div>
        <div class="field"><label for="t-price">Цена за ночь, ₽</label><input id="t-price" name="base_price" inputmode="numeric" required></div>
        <div class="field"><label for="t-cap">Гостей</label><input id="t-cap" name="capacity" type="number" min="1" max="20" value="2"></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit"><?= icon('plus') ?>Добавить тип</button></div>
    </form>
</section>

<section class="panel" id="rooms">
    <h2 class="panel__title">Комнаты <small>сколько их — столько броней можно принять на одни даты</small></h2>
    <div class="table-wrap">
        <table class="table table--cards">
            <thead><tr><th>Название</th><th>Тип</th><th>Заметка</th><th>Продаётся</th><th class="num">Порядок</th><th></th></tr></thead>
            <tbody>
<?php foreach ($rooms as $r): $rid = (int) $r['id']; $fid = 'room-form-' . $rid; ?>
                <tr id="room-<?= $rid ?>" class="<?= $r['is_active'] ? '' : 'is-muted' ?>">
                    <td data-label="Название"><input form="<?= $fid ?>" name="name" value="<?= e($r['name']) ?>" required maxlength="50" aria-label="Название комнаты" class="cell-input"></td>
                    <td data-label="Тип"><select form="<?= $fid ?>" name="room_type_id" aria-label="Тип" class="cell-input">
<?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"<?= (int) $r['room_type_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
                    </select></td>
                    <td data-label="Заметка"><input form="<?= $fid ?>" name="note" value="<?= e($r['note']) ?>" maxlength="255" placeholder="этаж, вид из окна…" aria-label="Заметка" class="cell-input"></td>
                    <td data-label="Продаётся"><label class="inline-check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1"<?= $r['is_active'] ? ' checked' : '' ?>> да</label></td>
                    <td data-label="Порядок" class="num"><input form="<?= $fid ?>" name="sort" type="number" value="<?= (int) $r['sort'] ?>" aria-label="Порядок" class="cell-input cell-input--num"></td>
                    <td data-label="" class="num nowrap">
                        <form method="post" id="<?= $fid ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="room_save"><input type="hidden" name="room_id" value="<?= $rid ?>">
                            <button class="btn btn--outline btn--sm" type="submit">Сохранить</button></form>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="room_delete"><input type="hidden" name="room_id" value="<?= $rid ?>">
                            <button class="icon-button" type="submit" aria-label="Удалить комнату" data-confirm="Удалить комнату «<?= e($r['name']) ?>»?"><?= icon('trash-2') ?></button></form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="room_add">
        <div class="field"><label for="r-name">Новая комната</label><input id="r-name" name="name" required maxlength="50" placeholder="Например: 101"></div>
        <div class="field"><label for="r-type">Тип</label><select id="r-type" name="room_type_id">
<?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
<?php endforeach; ?>
        </select></div>
        <div class="field"><label for="r-note">Заметка</label><input id="r-note" name="note" maxlength="255" placeholder="2 этаж"></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit"><?= icon('plus') ?>Добавить комнату</button></div>
    </form>
</section>

<section class="panel" id="site-photos">
    <h2 class="panel__title">Фото на главной</h2>
    <p class="hint">Фото номеров загружаются в карточке каждого типа номера. Подойдут JPEG, PNG или WebP до 15 МБ — сайт сам уменьшит фото и уберёт из него геометки.</p>
    <div class="photo-grid">
<?php foreach (SITE_PHOTO_SLOTS as $slot => $label): $path = setting('photo_' . $slot); ?>
        <figure>
<?php if ($path && is_file(HOTEL_ROOT . '/public/' . $path)): ?>
            <img src="/<?= e($path) ?>" alt="<?= e($label) ?>">
            <figcaption>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="site_photo_delete"><input type="hidden" name="slot" value="<?= e($slot) ?>">
                    <button class="icon-button" type="submit" aria-label="Удалить фото" data-confirm="Удалить фото?"><?= icon('trash-2') ?></button></form>
            </figcaption>
            <span class="is-cover"><?= e($label) ?></span>
<?php else: ?>
            <?= photo_placeholder($label) ?>
<?php endif; ?>
        </figure>
<?php endforeach; ?>
    </div>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="site_photo">
        <div class="field"><label for="sp-slot">Куда</label><select id="sp-slot" name="slot">
<?php foreach (SITE_PHOTO_SLOTS as $slot => $label): ?>
            <option value="<?= e($slot) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
        </select></div>
        <div class="field"><label for="sp-file">Фото</label><input id="sp-file" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit"><?= icon('upload') ?>Загрузить</button></div>
    </form>
</section>
<?php admin_footer(); ?>
