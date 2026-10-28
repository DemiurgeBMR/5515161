<?php
/**
 * Цены: базовая цена номеров, сезонные цены и праздники, скидки за
 * длительное проживание, правило предоплаты и калькулятор для проверки.
 *
 * Уже заведённые брони не пересчитываются: у них остаётся сумма,
 * которую назвали гостю.
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();

/** Даты правила (включительно): «31 декабря» или «30 декабря – 2 января». */
function rule_dates(string $from, string $to): string
{
    return $from === $to ? ru_date($from) : ru_date($from) . ' – ' . ru_date($to);
}

/** «+20%» / «−15%» / «3 000 ₽». */
function rule_value(array $r): string
{
    if ($r['price'] !== null) {
        return money((int) $r['price']);
    }
    $p = (int) $r['percent'];
    return ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . '%';
}

if (is_post()) {
    csrf_check();
    $action = post('action');
    $anchor = '';
    try {
        if ($action === 'base') {
            $changes = [];
            foreach (room_types(false) as $t) {
                $raw = $_POST['price'][$t['id']] ?? null;
                if ($raw === null) {
                    continue;
                }
                $price = (int) preg_replace('/\D+/', '', (string) $raw);
                $tbd = !empty($_POST['tbd'][$t['id']]) ? 1 : 0;
                if ($price <= 0) {
                    throw new BookingError('Цена «' . $t['name'] . '» должна быть больше нуля');
                }
                if ($price !== $t['base_price'] || $tbd !== (int) $t['price_tbd']) {
                    q_update('room_types', $t['id'], ['base_price' => $price, 'price_tbd' => $tbd]);
                    $changes[] = $t['name'] . ': ' . money($t['base_price']) . ' → ' . money($price) . ($tbd ? ' (не утверждена)' : '');
                }
            }
            if ($changes) {
                audit('price', null, 'base_price', implode('; ', $changes));
            }
            flash('ok', $changes ? 'Цены сохранены. На сайте уже новые.' : 'Цены не изменились.');
        } elseif ($action === 'rule_add') {
            $from = date_value(post('date_from'));
            $to = date_value(post('date_to'));
            $typeId = (int) post('room_type_id');
            $kind = post('kind') === 'price' ? 'price' : 'percent';
            $value = (int) preg_replace('/[^\d-]+/', '', str_replace(['−', '–'], '-', post('value')));
            if ($from === '' || $to === '' || $to < $from) {
                throw new BookingError('Проверьте даты: «по» не раньше, чем «с»');
            }
            if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 731) {
                throw new BookingError('Правило длиннее двух лет — проверьте год в датах');
            }
            if ($typeId && !room_type($typeId)) {
                throw new BookingError('Такого номера нет');
            }
            if ($kind === 'price' && !$typeId) {
                throw new BookingError('Цену в рублях задают для конкретного номера. Для всех номеров сразу — наценка или скидка в процентах.');
            }
            if ($kind === 'price' && $value <= 0) {
                throw new BookingError('Укажите цену за ночь');
            }
            if ($kind === 'percent' && ($value === 0 || $value < -90 || $value > 300)) {
                throw new BookingError('Процент — от −90 до +300, например 20 (дороже на 20%) или −15 (дешевле на 15%)');
            }
            $rid = q_insert('price_rules', [
                'room_type_id' => $typeId ?: null, 'date_from' => $from, 'date_to' => $to,
                'price' => $kind === 'price' ? $value : null, 'percent' => $kind === 'percent' ? $value : null,
                'note' => mb_substr(post('note'), 0, 255), 'created_at' => now(),
            ]);
            $r = q_one('SELECT * FROM price_rules WHERE id = ?', [$rid]);
            audit('price', $rid, 'rule_added', rule_dates($from, $to) . ', ' . ($typeId ? room_type($typeId)['name'] : 'все номера') . ': ' . rule_value($r) . (post('note') !== '' ? ' (' . post('note') . ')' : ''));
            flash('ok', 'Сезонная цена добавлена: ' . rule_dates($from, $to) . '.');
            $anchor = '#rules';
        } elseif ($action === 'rule_delete') {
            $r = q_one('SELECT * FROM price_rules WHERE id = ?', [(int) post('rule_id')]);
            if ($r) {
                q('DELETE FROM price_rules WHERE id = ?', [$r['id']]);
                audit('price', (int) $r['id'], 'rule_deleted', rule_dates($r['date_from'], $r['date_to']) . ': ' . rule_value($r));
                flash('ok', 'Правило удалено.');
            }
            $anchor = '#rules';
        } elseif ($action === 'discounts') {
            $rows = [];
            foreach ((array) ($_POST['d'] ?? []) as $d) {
                $min = (int) ($d['min_nights'] ?? 0);
                if (!empty($d['delete']) || $min === 0) {
                    continue;
                }
                if ($min < 2 || $min > 365) {
                    throw new BookingError('Срок скидки — от 2 до 365 ночей');
                }
                if (isset($rows[$min])) {
                    throw new BookingError('Две скидки на один срок: ' . nights_label($min));
                }
                $pct = trim((string) ($d['percent'] ?? ''));
                if ($pct !== '' && ((int) $pct < 1 || (int) $pct > 90)) {
                    throw new BookingError('Скидка — от 1 до 90%, или оставьте поле пустым («по договорённости»)');
                }
                $label = trim((string) ($d['label'] ?? ''));
                $rows[$min] = [
                    'min_nights' => $min, 'percent' => $pct === '' ? null : (int) $pct,
                    'label' => mb_substr($label !== '' ? $label : 'От ' . nights_label($min), 0, 50),
                    'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 100),
                ];
            }
            ksort($rows);
            tx(function () use ($rows) {
                q('DELETE FROM stay_discounts');
                foreach ($rows as $row) {
                    q_insert('stay_discounts', $row);
                }
            });
            audit('price', null, 'discounts', $rows ? implode('; ', array_map(fn ($r) => $r['label'] . ': ' . ($r['percent'] !== null ? $r['percent'] . '%' : 'по договорённости'), $rows)) : 'скидок нет');
            flash('ok', 'Скидки сохранены.');
            $anchor = '#discounts';
        } elseif ($action === 'prepayment') {
            $mode = post('mode');
            if ($mode === 'percent') {
                $pct = (int) post('percent');
                if ($pct < 1 || $pct > 100) {
                    throw new BookingError('Процент предоплаты — от 1 до 100');
                }
                $mode = 'percent:' . $pct;
            } elseif (!in_array($mode, ['none', 'first_night'], true)) {
                $mode = 'first_night';
            }
            set_setting('prepayment_mode', $mode);
            audit('price', null, 'prepayment', prepayment_mode_label($mode));
            flash('ok', 'Предоплата для новых броней: ' . prepayment_mode_label($mode) . '.');
            $anchor = '#prepayment';
        }
    } catch (BookingError $e) {
        flash('error', $e->getMessage());
    }
    app_cache_clear();
    redirect('/admin/prices.php' . $anchor);
}

$types = room_types(false);
$showPast = get('past') === '1';
$rules = q_all('SELECT r.*, t.name AS type_name FROM price_rules r LEFT JOIN room_types t ON t.id = r.room_type_id'
    . ($showPast ? '' : ' WHERE r.date_to >= ?') . ' ORDER BY r.date_from, r.id', $showPast ? [] : [today()->format('Y-m-d')]);
$pastCount = (int) q_val('SELECT COUNT(*) FROM price_rules WHERE date_to < ?', [today()->format('Y-m-d')]);
$discounts = stay_discounts();
$mode = setting('prepayment_mode', 'first_night');

// Калькулятор: проверить, сколько будет стоить проживание.
$calc = null;
$cType = (int) get('calc_type') ?: (int) array_key_first($types);
$cIn = date_value(get('calc_in')) ?: today()->modify('+1 day')->format('Y-m-d');
$cOut = date_value(get('calc_out')) ?: today()->modify('+8 days')->format('Y-m-d');
if (get('calc_type') !== '' && isset($types[$cType]) && $cOut > $cIn && nights_between($cIn, $cOut) <= 366) {
    $calc = quote($types[$cType], $cIn, $cOut);
}

admin_header('Цены', 'prices');
?>
<div class="adm-head">
    <div>
        <h1>Цены</h1>
        <p class="adm-head__sub">Изменения сразу видны на сайте и в расчёте стоимости. Уже заведённые брони не пересчитываются.</p>
    </div>
</div>

<div class="two-col">
<section class="panel">
    <h2 class="panel__title">Цена за ночь</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="base">
        <table class="table">
            <thead><tr><th>Номер</th><th class="num">₽ за ночь</th><th>Не утверждена</th></tr></thead>
            <tbody>
<?php foreach ($types as $t): ?>
                <tr class="<?= $t['is_active'] ? '' : 'is-muted' ?>">
                    <td><label for="bp-<?= $t['id'] ?>"><?= e($t['name']) ?></label><?= $t['is_active'] ? '' : '<small>скрыт с сайта</small>' ?></td>
                    <td class="num"><input id="bp-<?= $t['id'] ?>" class="cell-input cell-input--num cell-input--price" name="price[<?= $t['id'] ?>]" inputmode="numeric" required value="<?= $t['base_price'] ?>"></td>
                    <td><label class="inline-check"><input type="checkbox" name="tbd[<?= $t['id'] ?>]" value="1"<?= $t['price_tbd'] ? ' checked' : '' ?>> в скобках</label></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">«Не утверждена» — на сайте цена показывается в квадратных скобках, как черновик.</p>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить цены</button></div>
    </form>
</section>

<section class="panel" id="prepayment">
    <h2 class="panel__title">Предоплата</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="prepayment">
        <label class="inline-check"><input type="radio" name="mode" value="first_night"<?= $mode === 'first_night' ? ' checked' : '' ?>> Стоимость первой ночи</label>
        <label class="inline-check"><input type="radio" name="mode" value="percent"<?= str_starts_with($mode, 'percent:') ? ' checked' : '' ?>> Процент от стоимости:
            <input class="cell-input cell-input--num" name="percent" type="number" min="1" max="100" value="<?= str_starts_with($mode, 'percent:') ? (int) substr($mode, 8) : 30 ?>" aria-label="Процент предоплаты"> %</label>
        <label class="inline-check"><input type="radio" name="mode" value="none"<?= $mode === 'none' ? ' checked' : '' ?>> Без предоплаты</label>
        <p class="hint">Сумма предоплаты считается для каждой новой брони, её можно поменять в карточке брони. Оплату принимаете сами (перевод, наличные) и отмечаете в брони.</p>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить</button></div>
    </form>
</section>
</div>

<section class="panel" id="rules">
    <h2 class="panel__title">Сезонные цены и праздники
<?php if ($pastCount): ?>
        <small><a href="/admin/prices.php<?= $showPast ? '' : '?past=1' ?>#rules"><?= $showPast ? 'скрыть прошедшие' : 'показать прошедшие (' . $pastCount . ')' ?></a></small>
<?php endif; ?>
    </h2>
<?php if (!$rules): ?>
    <p class="empty">Правил нет — все ночи по обычной цене.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--cards">
            <thead><tr><th>Даты (ночи)</th><th>Номер</th><th class="num">Цена ночи</th><th>Пример</th><th>Заметка</th><th></th></tr></thead>
            <tbody>
<?php foreach ($rules as $r):
    $example = '';
    foreach ($r['room_type_id'] ? [$types[(int) $r['room_type_id']] ?? null] : array_slice($types, 0, 1) as $et) {
        if ($et) {
            $new = $r['price'] !== null ? (int) $r['price'] : (int) round($et['base_price'] * (100 + (int) $r['percent']) / 100);
            $example = $et['name'] . ': ' . money($et['base_price']) . ' → ' . money($new);
        }
    }
?>
                <tr class="<?= $r['date_to'] < today()->format('Y-m-d') ? 'is-muted' : '' ?>">
                    <td data-label="Даты" class="nowrap"><?= e(rule_dates($r['date_from'], $r['date_to'])) ?></td>
                    <td data-label="Номер"><?= e($r['type_name'] ?? 'Все номера') ?></td>
                    <td data-label="Цена ночи" class="num"><b><?= e(rule_value($r)) ?></b></td>
                    <td data-label="Пример"><?= e($example) ?></td>
                    <td data-label="Заметка"><?= e($r['note']) ?></td>
                    <td data-label="" class="num">
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rule_delete"><input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-button" type="submit" aria-label="Удалить правило" data-confirm="Удалить это правило?"><?= icon('trash-2') ?></button></form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rule_add">
        <div class="field"><label for="r-from">Ночи с</label><input id="r-from" type="date" name="date_from" required></div>
        <div class="field"><label for="r-to">по (включительно)</label><input id="r-to" type="date" name="date_to" required></div>
        <div class="field"><label for="r-type">Номер</label><select id="r-type" name="room_type_id">
            <option value="0">Все номера</option>
<?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
<?php endforeach; ?>
        </select></div>
        <div class="field"><label for="r-kind">Как меняется цена</label><select id="r-kind" name="kind">
            <option value="percent">Процент к обычной цене</option>
            <option value="price">Своя цена за ночь, ₽</option>
        </select></div>
        <div class="field"><label for="r-value">Значение</label><input id="r-value" name="value" required inputmode="numeric" placeholder="20, −15 или 3000"></div>
        <div class="field"><label for="r-note">Заметка</label><input id="r-note" name="note" maxlength="255" placeholder="Новогодние праздники"></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit"><?= icon('plus') ?>Добавить</button></div>
    </form>
    <p class="hint">Дата — это ночь: «с 30 декабря по 2 января» меняет цену ночей 30, 31, 1 и 2 (выезд 3 января уже по обычной). Процент: 20 — дороже на 20%, −15 — дешевле на 15%. Если правила пересекаются, своя цена номера сильнее процента, а из одинаковых действует добавленное позже.</p>
</section>

<section class="panel" id="discounts">
    <h2 class="panel__title">Скидки за длительное проживание</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discounts">
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th>От скольких ночей</th><th>Скидка, %</th><th>Название в таблице цен</th><th>Если без процента — что написать</th><th>Удалить</th></tr></thead>
                <tbody>
<?php foreach (array_merge($discounts, [['min_nights' => '', 'percent' => null, 'label' => '', 'note' => '']]) as $i => $d): $new = $d['min_nights'] === ''; ?>
                    <tr>
                        <td data-label="От ночей"><input class="cell-input cell-input--num" name="d[<?= $i ?>][min_nights]" type="number" min="2" max="365" value="<?= e((string) $d['min_nights']) ?>" placeholder="<?= $new ? '14' : '' ?>" aria-label="От скольких ночей"></td>
                        <td data-label="Скидка, %"><input class="cell-input cell-input--num" name="d[<?= $i ?>][percent]" type="number" min="1" max="90" value="<?= $d['percent'] === null ? '' : (int) $d['percent'] ?>" placeholder="—" aria-label="Скидка в процентах"></td>
                        <td data-label="Название"><input class="cell-input" name="d[<?= $i ?>][label]" maxlength="50" value="<?= e($d['label']) ?>" placeholder="От 14 ночей" aria-label="Название"></td>
                        <td data-label="Без процента"><input class="cell-input" name="d[<?= $i ?>][note]" maxlength="100" value="<?= e($d['note']) ?>" placeholder="по договорённости" aria-label="Текст без процента"></td>
                        <td data-label="Удалить"><?php if (!$new): ?><label class="inline-check"><input type="checkbox" name="d[<?= $i ?>][delete]" value="1"> удалить</label><?php else: ?><span class="muted">новая</span><?php endif; ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">Скидка считается от суммы за весь срок. Пустой процент — цена обсуждается лично («по договорённости»): такую заявку сайт примет без суммы, а вы назовёте цену сами. Чтобы добавить ещё одну скидку, заполните пустую строку.</p>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">Сохранить скидки</button></div>
    </form>
</section>

<section class="panel" id="calc">
    <h2 class="panel__title">Проверить расчёт</h2>
    <form method="get" action="/admin/prices.php#calc" class="form-grid">
        <div class="field"><label for="c-type">Номер</label><select id="c-type" name="calc_type">
<?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>"<?= $t['id'] === $cType ? ' selected' : '' ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
        </select></div>
        <div class="field"><label for="c-in">Заезд</label><input id="c-in" type="date" name="calc_in" value="<?= e($cIn) ?>"></div>
        <div class="field"><label for="c-out">Выезд</label><input id="c-out" type="date" name="calc_out" value="<?= e($cOut) ?>"></div>
        <div class="form-actions"><button class="btn btn--outline btn--sm" type="submit">Посчитать</button></div>
    </form>
<?php if ($calc): ?>
    <div class="calc">
        <div class="money-line"><span><?= e(nights_label($calc['nights'])) ?><?= $calc['seasonal'] ? ' (есть сезонные цены)' : '' ?></span><b><?= e(money($calc['sum'])) ?></b></div>
<?php if ($calc['tier']): ?>
        <div class="money-line"><span><?= e($calc['tier']['label']) ?></span><b><?= $calc['tier']['percent'] !== null ? '−' . e(money($calc['discount'])) : e($calc['tier']['text']) ?></b></div>
<?php endif; ?>
        <div class="money-line money-line--ok"><span>Итого</span><b><?= $calc['total'] !== null ? e(money($calc['total'])) : 'по договорённости' ?></b></div>
        <div class="money-line"><span>Предоплата (<?= e(prepayment_mode_label()) ?>)</span><b><?= e(money(default_prepayment($calc))) ?></b></div>
<?php if ($calc['seasonal']): ?>
        <details><summary>Цена каждой ночи</summary>
            <ul class="calc__nights">
<?php foreach ($calc['prices'] as $date => $p): ?>
                <li><span><?= e(ru_date($date, false)) ?>, <?= e(RU_WEEKDAYS_SHORT[(int) (new DateTimeImmutable($date))->format('w')]) ?></span><b><?= e(money($p)) ?></b></li>
<?php endforeach; ?>
            </ul>
        </details>
<?php endif; ?>
    </div>
<?php endif; ?>
</section>
<?php admin_footer(); ?>
