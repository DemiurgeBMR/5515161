<?php
/**
 * Баня и хамам: фото, что внутри, цены, правила и запись на свободное время.
 * Пока баня не открыта — «Сообщить об открытии»; временно закрыта — пояснение.
 */

require __DIR__ . '/../src/bootstrap.php';

if (!db_ready()) {
    require __DIR__ . '/../src/partials/maintenance.php';
    exit;
}
if (!bath_enabled()) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$h = hotel();
$s = bath_settings();
$photos = bath_photos();
$open = $s['state'] === 'open';
$notifyResult = get('notify') ?: null;
$minDate = today()->format('Y-m-d');
$maxDate = today()->modify('+' . BATH_AHEAD_DAYS . ' days')->format('Y-m-d');
$firstDay = $minDate;
// Первая дата, на которую ещё можно записаться (сегодня может быть уже поздно).
for ($i = 0; $i < 14 && $open; $i++) {
    $day = today()->modify('+' . $i . ' days')->format('Y-m-d');
    if (bath_slots($day)['slots']) {
        $firstDay = $day;
        break;
    }
}

$page = [
    'title'       => $s['title'] . ' — ' . $h['name'] . ', Симферополь',
    'description' => $s['title'] . ' в Симферополе: ' . ($open ? bath_price_from() . ', ' . bath_hours_text() . '. Запись на свободное время онлайн.' : mb_strtolower(BATH_STATES[$s['state']]) . '.') . ' ' . mb_substr($s['lead'], 0, 120),
    'path'        => '/bath.php',
    'body_class'  => 'page-bath',
    'og_image'    => isset($photos[0]) ? base_url() . '/' . $photos[0]['path'] : og_image_url(),
];
require __DIR__ . '/../src/partials/head.php';
require __DIR__ . '/../src/partials/header.php';
?>
<main id="main">
    <section class="section section--dark bath-hero" aria-labelledby="bath-title">
        <div class="container bath-hero__grid">
            <div class="bath-hero__text">
                <a class="doc__back doc__back--light" href="/"><?= icon('arrow-right') ?>На главную</a>
                <p class="eyebrow eyebrow--light"><?= e(bath_eyebrow()) ?></p>
                <h1 class="display" id="bath-title"><?= bath_title_html() ?></h1>
                <p class="lead"><?= e($s['lead']) ?></p>
                <ul class="bath-facts">
<?php if ($open): ?>
                    <li><?= icon('clock') ?><span><strong><?= e(bath_hours_text()) ?></strong>запись от <?= $s['min_hours'] ?> до <?= $s['max_hours'] ?> <?= plural($s['max_hours'], 'часа', 'часов', 'часов') ?></span></li>
                    <li><?= icon('banknote') ?><span><strong><?= e(bath_price_from()) ?></strong>за всю баню, не за человека</span></li>
<?php endif; ?>
                    <li><?= icon('users') ?><span><strong>До <?= $s['max_guests'] ?> <?= plural($s['max_guests'], 'гостя', 'гостей', 'гостей') ?></strong>одна компания — баня только ваша</span></li>
<?php if ($s['hotel_discount'] > 0): ?>
                    <li><?= icon('key-round') ?><span><strong>−<?= $s['hotel_discount'] ?>%</strong>гостям гостиницы</span></li>
<?php endif; ?>
                </ul>
<?php if ($open): ?>
                <a class="btn btn--copper" href="#book"><?= icon('calendar-days') ?>Выбрать время</a>
<?php endif; ?>
            </div>
            <div class="bath-gallery" data-bath-gallery>
<?php if ($photos): ?>
                <div class="bath-gallery__track" data-bath-track tabindex="0" aria-label="Фото, листайте">
<?php foreach ($photos as $i => $p): ?>
                    <figure class="bath-gallery__item"><?= bath_photo_img($p, $s['title'] . ', фото ' . ($i + 1), '', '(max-width: 760px) 100vw, 55vw', $i === 0) ?></figure>
<?php endforeach; ?>
                </div>
<?php if (count($photos) > 1): ?>
                <button class="bath-gallery__nav bath-gallery__nav--prev" type="button" data-bath-prev aria-label="Предыдущее фото"><?= icon('arrow-right') ?></button>
                <button class="bath-gallery__nav bath-gallery__nav--next" type="button" data-bath-next aria-label="Следующее фото"><?= icon('arrow-right') ?></button>
                <p class="bath-gallery__count" aria-live="polite"><span data-bath-current>1</span> / <?= count($photos) ?></p>
<?php endif; ?>
<?php else: ?>
                <div class="bath-gallery__empty">
                    <?= site_photo('bath', 'Баня', 'ph--dark bath__ph', '(max-width: 760px) 50vw, 27vw') ?>
                    <?= site_photo('hammam', 'Хамам', 'ph--dark arch bath__ph', '(max-width: 760px) 50vw, 27vw') ?>
                </div>
<?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container bath-page">
            <div class="bath-page__info">
<?php if ($s['features']): ?>
                <section class="bath-block">
                    <h2 class="h3">Что внутри</h2>
                    <ul class="checklist checklist--lg">
<?php foreach ($s['features'] as $f): ?>
                        <li><?= icon('check') ?><span><?= e($f) ?></span></li>
<?php endforeach; ?>
                    </ul>
                </section>
<?php endif; ?>
<?php if ($s['price'] > 0): ?>
                <section class="bath-block" id="prices">
                    <h2 class="h3">Цены</h2>
                    <dl class="bath-prices">
                        <div><dt>Час, будни</dt><dd><?= e(money($s['price'])) ?></dd></div>
<?php if ($s['price_weekend'] > 0 && $s['price_weekend'] !== $s['price']): ?>
                        <div><dt>Час, суббота и воскресенье</dt><dd><?= e(money($s['price_weekend'])) ?></dd></div>
<?php endif; ?>
                        <div><dt>Минимум</dt><dd><?= $s['min_hours'] ?> <?= plural($s['min_hours'], 'час', 'часа', 'часов') ?></dd></div>
                        <div><dt>В цене</dt><dd>до <?= $s['guests_included'] ?> <?= plural($s['guests_included'], 'гостя', 'гостей', 'гостей') ?></dd></div>
<?php if ($s['extra_guest'] > 0 && $s['max_guests'] > $s['guests_included']): ?>
                        <div><dt>Каждый следующий гость</dt><dd>+<?= e(money($s['extra_guest'])) ?> в час</dd></div>
<?php endif; ?>
<?php if ($s['hotel_discount'] > 0): ?>
                        <div><dt>Гостям гостиницы</dt><dd>скидка <?= $s['hotel_discount'] ?>%</dd></div>
<?php endif; ?>
                    </dl>
<?php if ($s['extras']): ?>
                    <h3 class="bath-block__sub">Дополнительно</h3>
                    <dl class="bath-prices bath-prices--extras">
<?php foreach ($s['extras'] as $x): ?>
                        <div><dt><?= e($x['name']) ?></dt><dd><?= $x['price'] > 0 ? e(money($x['price'])) : 'бесплатно' ?></dd></div>
<?php endforeach; ?>
                    </dl>
<?php endif; ?>
                    <p class="muted bath-block__note">Цена — за всю баню на время сеанса. Между сеансами баню убирают и протапливают<?= $s['buffer'] ? ' (' . bath_duration($s['buffer']) . ')' : '' ?>, поэтому она всегда чистая и горячая.</p>
                </section>
<?php endif; ?>
<?php if ($s['rules']): ?>
                <section class="bath-block">
                    <h2 class="h3">Правила</h2>
                    <ul class="bath-rules">
<?php foreach ($s['rules'] as $r): ?>
                        <li><?= e($r) ?></li>
<?php endforeach; ?>
                    </ul>
                </section>
<?php endif; ?>
                <section class="bath-block">
                    <h2 class="h3">Где</h2>
                    <p><?= e(full_address()) ?><?= filled($h['address']['entrance']) ? '. ' . e($h['address']['entrance']) : '' ?></p>
                    <p class="bp__buttons">
                        <a class="btn btn--outline btn--sm" href="<?= e(route_href()) ?>" target="_blank" rel="noopener"><?= icon('navigation') ?>Маршрут</a>
                        <a class="btn btn--outline btn--sm" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
                    </p>
                </section>
            </div>

            <aside class="bath-page__side" id="book">
<?php if ($open): ?>
                <form class="card bath-book" action="/api/bath.php" method="post" data-bath-form novalidate>
                    <h2 class="h3">Записаться</h2>
                    <input type="hidden" name="ts" value="<?= time() ?>">
                    <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                    <div class="bath-book__row">
                        <div class="field">
                            <label for="bb-date">Дата</label>
                            <input id="bb-date" type="date" name="date" required min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" value="<?= e($firstDay) ?>" data-bath-date>
                        </div>
                        <div class="field">
                            <label for="bb-guests">Гостей</label>
                            <select id="bb-guests" name="guests" data-bath-guests>
<?php for ($g = 1; $g <= $s['max_guests']; $g++): ?>
                                <option value="<?= $g ?>"<?= $g === min(2, $s['max_guests']) ? ' selected' : '' ?>><?= $g ?></option>
<?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <fieldset class="field bath-times">
                        <legend>Время начала</legend>
                        <div class="bath-times__list" data-bath-times>
<?php // Без JS — все часы работы; с JS список заменяется свободным временем на выбранную дату. ?>
<?php for ($t = $s['open_from']; $t + $s['min_hours'] <= $s['open_to']; $t++): ?>
                            <label class="chip"><input type="radio" name="time" value="<?= e(bath_hour($t)) ?>" required><span><?= e(bath_hour($t)) ?></span></label>
<?php endfor; ?>
                        </div>
                        <p class="field-hint" data-bath-times-hint>Показано время, на которое можно записаться.</p>
                    </fieldset>
                    <div class="field">
                        <label for="bb-hours">Сколько часов</label>
                        <select id="bb-hours" name="hours" data-bath-hours>
<?php for ($n = $s['min_hours']; $n <= $s['max_hours']; $n++): ?>
                            <option value="<?= $n ?>"><?= $n ?> <?= plural($n, 'час', 'часа', 'часов') ?></option>
<?php endfor; ?>
                        </select>
                    </div>
<?php if ($s['extras']): ?>
                    <fieldset class="field bath-extras">
                        <legend>Дополнительно</legend>
<?php foreach ($s['extras'] as $i => $x): ?>
                        <div class="bath-extras__row">
                            <label for="bb-x<?= $i ?>"><?= e($x['name']) ?> <span class="muted"><?= $x['price'] > 0 ? e(money($x['price'])) : 'бесплатно' ?></span></label>
                            <input id="bb-x<?= $i ?>" type="number" name="extras[<?= $i ?>]" min="0" max="20" value="0" inputmode="numeric" data-bath-extra="<?= $x['price'] ?>">
                        </div>
<?php endforeach; ?>
                    </fieldset>
<?php endif; ?>
<?php if ($s['hotel_discount'] > 0): ?>
                    <label class="check">
                        <input type="checkbox" name="hotel_guest" value="1" data-bath-hotel>
                        <span>Я живу в гостинице — скидка <?= $s['hotel_discount'] ?>% (администратор проверит бронь)</span>
                    </label>
<?php endif; ?>
                    <div class="field">
                        <label for="bb-name">Как к вам обращаться</label>
                        <input id="bb-name" name="name" required maxlength="80" autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="bb-phone">Телефон</label>
                        <input id="bb-phone" type="tel" name="phone" required maxlength="25" autocomplete="tel" inputmode="tel" placeholder="+7 978 000-00-00" data-phone>
                    </div>
                    <div class="field">
                        <label for="bb-email">Почта <span class="muted">— пришлём подтверждение и напоминание</span></label>
                        <input id="bb-email" type="email" name="email" maxlength="190" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="bb-contact">Как с вами связаться</label>
                        <select id="bb-contact" name="contact_pref">
<?php foreach (CONTACT_PREFS as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="bb-comment">Комментарий <span class="muted">— необязательно</span></label>
                        <textarea id="bb-comment" name="comment" rows="2" maxlength="1000" placeholder="Например: будем с детьми"></textarea>
                    </div>
                    <div class="bath-sum" data-bath-sum hidden>
                        <div class="money-line"><span data-bath-sum-base></span><b data-bath-sum-base-v></b></div>
                        <div class="money-line" data-bath-sum-extra-row hidden><span data-bath-sum-extra></span><b data-bath-sum-extra-v></b></div>
                        <div class="money-line" data-bath-sum-disc-row hidden><span>Скидка гостю гостиницы</span><b data-bath-sum-disc-v></b></div>
                        <div class="money-line" data-bath-sum-x-row hidden><span>Дополнительно</span><b data-bath-sum-x-v></b></div>
                        <div class="money-line money-line--total"><span>Итого</span><b data-bath-sum-total></b></div>
                    </div>
                    <label class="check">
                        <input type="checkbox" name="consent" value="1" required>
                        <span>Даю <a href="/consent.php" target="_blank">согласие на обработку персональных данных</a></span>
                    </label>
<?php $err = get('error'); ?>
                    <div class="form-error" role="alert" data-form-error<?= $err === '' ? ' hidden' : '' ?>><?= $err === 'taken' ? 'Это время только что заняли — выберите другое.' : ($err !== '' ? 'Не получилось записаться — проверьте дату, время и телефон или позвоните нам: ' . e($h['phone']) : '') ?></div>
                    <button class="btn btn--primary btn--block" type="submit"><span data-submit-label>Записаться</span></button>
                    <p class="muted bath-book__foot">Время сразу держим за вами, администратор подтвердит запись. Оплата — на месте.</p>
                </form>
<?php elseif ($s['state'] === 'closed'): ?>
                <div class="card bath-book">
                    <h2 class="h3">Временно закрыта</h2>
                    <p><?= e($s['closed_note'] !== '' ? $s['closed_note'] : 'Баня временно не работает. Когда откроемся — подскажем по телефону.') ?></p>
                    <a class="btn btn--outline" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
                </div>
<?php else: ?>
                <div class="card bath-book" id="notify">
                    <h2 class="h3">Скоро открытие<?= filled($s['opening']) ? ' — ' . e($s['opening']) : '' ?></h2>
<?php if ($notifyResult === 'ok'): ?>
                    <p class="notice notice--ok" role="status"><?= icon('check') ?>Спасибо! Сообщим, как только откроемся.</p>
<?php else: ?>
                    <p>Оставьте телефон — позвоним или напишем, как только откроем запись.</p>
                    <form class="bath__form" action="/api/booking.php" method="post" data-notify>
                        <input type="hidden" name="type" value="bath">
                        <input type="hidden" name="back" value="bath">
                        <input type="hidden" name="ts" value="<?= time() ?>">
                        <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                        <div class="field">
                            <label for="n-phone">Телефон</label>
                            <input id="n-phone" type="tel" name="phone" required maxlength="25" autocomplete="tel" inputmode="tel" placeholder="+7 978 000-00-00" data-phone>
                        </div>
                        <label class="check">
                            <input type="checkbox" name="consent" value="1" required>
                            <span>Даю <a href="/consent.php" target="_blank">согласие на обработку персональных данных</a></span>
                        </label>
                        <button class="btn btn--primary" type="submit">Сообщить мне</button>
                        <p class="form-msg" role="status" data-notify-msg hidden></p>
                    </form>
<?php endif; ?>
                </div>
<?php endif; ?>
            </aside>
        </div>
    </section>
</main>
<script type="application/json" id="site-data"><?= json_encode([
    'phone'    => $h['phone'],
    'whatsapp' => whatsapp_href(),
    'bath'     => [
        'price' => $s['price'], 'price_weekend' => $s['price_weekend'], 'included' => $s['guests_included'],
        'extra_guest' => $s['extra_guest'], 'discount' => $s['hotel_discount'],
        'min_hours' => $s['min_hours'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
