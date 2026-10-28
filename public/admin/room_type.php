<?php
/**
 * Тип номера: описание для сайта, цена, вместимость и фотографии.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

$id = (int) (post('id') ?: get('id'));
$t = room_type($id);
if (!$t) {
    http_response_code(404);
    admin_header('Тип номера не найден', 'rooms');
    echo '<div class="panel"><p>Такого типа номера нет. <a href="/admin/rooms.php">К номерам</a></p></div>';
    admin_footer();
    exit;
}

function type_files(): array
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

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'save') {
            if (post('name') === '') {
                throw new BookingError('Укажите название');
            }
            $price = (int) preg_replace('/\D+/', '', post('base_price'));
            q_update('room_types', $id, [
                'name' => mb_substr(post('name'), 0, 100), 'summary' => mb_substr(post('summary'), 0, 1000),
                'features' => mb_substr(post('features'), 0, 3000), 'capacity' => max(1, min(20, (int) post('capacity'))),
                'area' => mb_substr(post('area'), 0, 30), 'bed' => mb_substr(post('bed'), 0, 100),
                'base_price' => $price, 'price_tbd' => post('price_tbd') === '1' ? 1 : 0,
                'badge' => mb_substr(post('badge'), 0, 50), 'featured' => post('featured') === '1' ? 1 : 0,
                'is_active' => post('is_active') === '1' ? 1 : 0, 'sort' => (int) post('sort'),
            ]);
            $changes = $price !== $t['base_price'] ? 'цена ' . money($t['base_price']) . ' → ' . money($price) : 'описание';
            audit('room_type', $id, 'updated', $t['name'] . ': ' . $changes);
            touch_data_version();
            flash('ok', 'Сохранено. На сайте уже обновилось.');
        } elseif ($action === 'upload') {
            $files = type_files();
            if (!$files) {
                throw new BookingError('Выберите фото');
            }
            $sort = (int) q_val('SELECT COALESCE(MAX(sort), 0) FROM room_type_photos WHERE room_type_id = ?', [$id]);
            $done = 0;
            foreach ($files as $file) {
                $up = upload_photo($file, 'rooms');
                q_insert('room_type_photos', ['room_type_id' => $id, 'path' => $up['path'], 'width' => $up['width'], 'height' => $up['height'], 'sort' => ++$sort, 'created_at' => now()]);
                $done++;
            }
            audit('room_type', $id, 'photos_added', $t['name'] . ': ' . $done);
            flash('ok', 'Загружено фото: ' . $done . '.');
        } elseif ($action === 'photo_delete') {
            $p = q_one('SELECT * FROM room_type_photos WHERE id = ? AND room_type_id = ?', [(int) post('photo_id'), $id]);
            if ($p) {
                q('DELETE FROM room_type_photos WHERE id = ?', [$p['id']]);
                delete_upload($p['path']);
                flash('ok', 'Фото удалено.');
            }
        } elseif ($action === 'photo_cover') {
            $min = (int) q_val('SELECT COALESCE(MIN(sort), 0) FROM room_type_photos WHERE room_type_id = ?', [$id]);
            q('UPDATE room_type_photos SET sort = ? WHERE id = ? AND room_type_id = ?', [$min - 1, (int) post('photo_id'), $id]);
            flash('ok', 'Это фото теперь первое — его видно в карточке номера.');
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/room_type.php?id=' . $id . ($action === 'save' ? '' : '#photos'));
}

admin_header($t['name'], 'rooms');
?>
<div class="adm-head">
    <div>
        <h1><?= e($t['name']) ?></h1>
        <p class="adm-head__sub"><a href="/admin/rooms.php">← Все номера</a> · <a href="/#rooms" target="_blank" rel="noopener">Как выглядит на сайте</a></p>
    </div>
</div>
<div class="main-side">
    <section class="panel">
        <h2 class="panel__title">Описание и цена</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="save">
            <div class="field span-all"><label for="t-name">Название</label><input id="t-name" name="name" required maxlength="100" value="<?= e($t['name']) ?>"></div>
            <div class="field"><label for="t-price">Цена за ночь, ₽</label><input id="t-price" name="base_price" inputmode="numeric" required value="<?= $t['base_price'] ?>"></div>
            <div class="field"><label for="t-cap">Сколько гостей помещается</label><input id="t-cap" name="capacity" type="number" min="1" max="20" value="<?= $t['capacity'] ?>"></div>
            <div class="field"><label for="t-area">Площадь, м²</label><input id="t-area" name="area" maxlength="30" value="<?= e($t['area']) ?>"></div>
            <div class="field"><label for="t-bed">Кровать</label><input id="t-bed" name="bed" maxlength="100" value="<?= e($t['bed']) ?>" placeholder="двуспальная"></div>
            <div class="field span-all"><label for="t-summary">Коротко о номере</label><textarea id="t-summary" name="summary" rows="2" maxlength="1000"><?= e($t['summary'] ?? '') ?></textarea></div>
            <div class="field span-all"><label for="t-features">Что в номере — по одному пункту на строку</label><textarea id="t-features" name="features" rows="7"><?= e($t['features'] ?? '') ?></textarea></div>
            <div class="field"><label for="t-badge">Плашка на фото</label><input id="t-badge" name="badge" maxlength="50" value="<?= e($t['badge']) ?>" placeholder="Самый просторный"></div>
            <div class="field"><label for="t-sort">Порядок на сайте</label><input id="t-sort" name="sort" type="number" value="<?= (int) $t['sort'] ?>"></div>
            <div class="field span-all">
                <label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= $t['is_active'] ? ' checked' : '' ?>> Показывать на сайте и принимать брони</label>
                <label class="inline-check"><input type="checkbox" name="featured" value="1"<?= $t['featured'] ? ' checked' : '' ?>> Выделить тёмной карточкой</label>
                <label class="inline-check"><input type="checkbox" name="price_tbd" value="1"<?= $t['price_tbd'] ? ' checked' : '' ?>> Цена ещё не утверждена (на сайте — в скобках)</label>
            </div>
            <div class="form-actions span-all"><button class="btn btn--primary" type="submit">Сохранить</button></div>
        </form>
    </section>
    <section class="panel" id="photos">
        <h2 class="panel__title">Фото <small><?= count($t['photos']) ?></small></h2>
<?php if ($t['photos']): ?>
        <div class="photo-grid">
<?php foreach ($t['photos'] as $i => $p): ?>
            <figure>
                <img src="/<?= e(upload_small($p['path']) ?? $p['path']) ?>" alt="Фото <?= $i + 1 ?>" loading="lazy">
<?php if ($i === 0): ?><span class="is-cover">Главное</span><?php endif; ?>
                <figcaption>
<?php if ($i > 0): ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="photo_cover"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                        <button class="icon-button" type="submit" aria-label="Сделать главным" title="Сделать главным"><?= icon('star') ?></button></form>
<?php endif; ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
                        <button class="icon-button" type="submit" aria-label="Удалить фото" data-confirm="Удалить фото?"><?= icon('trash-2') ?></button></form>
                </figcaption>
            </figure>
<?php endforeach; ?>
        </div>
<?php else: ?>
        <p class="empty">Фото пока нет — на сайте показывается заглушка.</p>
<?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="upload">
            <div class="field span-all"><label for="t-photos">Добавить фото (можно несколько)</label><input id="t-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></div>
            <p class="hint span-all">Гостям важнее всего увидеть кровать, санузел, холодильник и вид из окна. Снимайте днём, при свете из окна.</p>
            <div class="form-actions span-all"><button class="btn btn--outline btn--sm" type="submit"><?= icon('upload') ?>Загрузить</button></div>
        </form>
    </section>
</div>
<?php admin_footer(); ?>
