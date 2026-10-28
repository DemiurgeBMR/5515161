<?php
require __DIR__ . '/../src/bootstrap.php';

$h = hotel();
$rooms = $h['rooms'];
$minPrice = min(array_column($rooms, 'price'));
$maxPrice = max(array_column($rooms, 'price'));
$weekTier = null;
foreach ($h['long_stay'] as $tier) {
    if ($tier['percent'] !== null && ($weekTier === null || $tier['nights'] < $weekTier['nights'])) {
        $weekTier = $tier;
    }
}
$walk = $h['walk_minutes'];
$today = today();

// Значения формы: из быстрой формы на первом экране без JS (GET) или пустые.
$form = [
    'check_in'  => (string) ($_GET['check_in'] ?? ''),
    'check_out' => (string) ($_GET['check_out'] ?? ''),
    'room'      => (string) ($_GET['room'] ?? ''),
    'guests'    => (string) ($_GET['guests'] ?? '1'),
];
if (!isset($rooms[$form['room']])) {
    $form['room'] = '';
}
// Результат отправки заявки без JS (api/booking.php возвращает сюда).
$bookingResult = $_GET['booking'] ?? null;
$notifyResult = $_GET['notify'] ?? null;

$faq = [
    ['Что нужно для заселения?',
     'Паспорт каждого взрослого гостя, для детей — свидетельство о рождении. Мы обязаны зарегистрировать всех, кто живёт в номере, — это требование закона. Иностранных гостей ставим на миграционный учёт: нужны паспорт и миграционная карта.'],
    ['Мы приезжаем ночным поездом. Можно заселиться рано утром?',
     'Заселение с ' . $h['check_in'] . ', выезд до ' . $h['check_out'] . '. Если номер свободен — заселим раньше. Если нет — оставьте багаж у нас и спокойно идите на приём. Напишите время приезда в заявке, и мы постараемся подготовить номер заранее.'],
    ['Сроки лечения могут сдвинуться. Что будет с бронью?',
     'Так бывает часто: госпитализацию переносят, результаты анализов готовы позже. Предупредите нас — перенесём даты или продлим проживание. Условия отмены: ' . $h['cancellation'] . '.'],
    ['Можно продлить проживание?',
     'Да, в любой день — просто скажите администратору. Если ваш номер на новые даты уже занят, предложим другой. Чем дольше живёте, тем ниже цена за сутки.'],
    ['Можно жить вместе с родственником?',
     'Да. Цена — за номер, а не за человека. ' . implode(', ', array_map(
         fn ($r) => '«' . $r['name'] . '» — до ' . $r['guests'] . ' ' . plural($r['guests'], 'гостя', 'гостей', 'гостей'),
         array_values($rooms)
     )) . '.'],
    ['Можно готовить? Где хранить лекарства?',
     'В каждом номере есть холодильник, чайник и посуда — в холодильнике можно хранить и продукты, и лекарства, которым нужен холод. [Общая кухня с плитой и микроволновкой.] Рядом продуктовый магазин и аптека.'],
    ['Нужен номер без ступенек или на первом этаже',
     'Напишите об этом в комментарии к заявке или скажите по телефону — подберём подходящий номер. [Есть ли номера на первом этаже, лифт, пандус.]'],
    ['Как оплатить? Будут ли документы?',
     'Оплата: ' . $h['payment'] . '. Предоплата: ' . $h['prepayment'] . '. Всегда выдаём кассовый чек, а если нужно — договор и справку о проживании для работодателя или бухгалтерии.'],
    ['Как добраться от вокзала?',
     'От ж/д вокзала и Центрального автовокзала — около [N] минут на такси. [Общественный транспорт: номер маршрута и остановка.] Если нужно, подскажем дорогу по телефону или вызовем такси.'],
    ['Есть парковка? Можно с животными?',
     'Парковка: ' . $h['parking'] . '. Животные: ' . $h['pets'] . '.'],
];

$jsonld = [[
    '@context'     => 'https://schema.org',
    '@type'        => 'Hotel',
    'name'         => $h['name'],
    'url'          => $h['site_url'] . '/',
    'telephone'    => $h['phone'],
    'address'      => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => $h['address']['street'],
        'addressLocality' => $h['address']['locality'],
        'addressRegion'   => $h['address']['region'],
        'postalCode'      => $h['address']['postal_code'],
    ],
    'checkinTime'  => $h['check_in'],
    'checkoutTime' => $h['check_out'],
    'priceRange'   => $minPrice . '–' . $maxPrice . ' RUB',
    'petsAllowed'  => $h['pets'],
] + (has_coords() ? ['geo' => [
    '@type' => 'GeoCoordinates', 'latitude' => $h['address']['lat'], 'longitude' => $h['address']['lon'],
]] : []), [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn ($q) => [
        '@type' => 'Question', 'name' => $q[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
    ], $faq),
]];

$page = [
    'title'       => $h['name'] . ' — гостиница рядом с онкодиспансером в Симферополе',
    'description' => 'Номера от ' . number_format($minPrice, 0, ',', ' ') . ' ₽ в сутки в ' . $walk
        . ' минутах пешком от онкодиспансера. Для пациентов и их близких: кухня, тишина, скидки при долгом проживании, документы для отчёта.',
    'path'        => '/',
    'jsonld'      => $jsonld,
    'body_class'  => 'page-home',
];

require __DIR__ . '/../src/partials/head.php';
require __DIR__ . '/../src/partials/header.php';
?>
<main id="main">

    <!-- ============================================================
         Первый экран
         ============================================================ -->
    <section class="hero" aria-labelledby="hero-title">
        <div class="container hero__grid">
            <div class="hero__text">
                <p class="eyebrow"><?= e($h['address']['street']) ?> · <?= e($h['city']) ?></p>
                <h1 class="display" id="hero-title">Спокойное место <em>рядом с&nbsp;теми, кто вам дорог</em></h1>
                <p class="lead">Уютные номера в <?= e($walk) ?> минутах пешком от онкодиспансера. Для пациентов и их близких — на одну ночь или на всё время лечения.</p>
                <div class="hero__actions">
                    <a class="btn btn--primary btn--lg" href="#booking">Узнать цену на мои даты</a>
                    <a class="btn btn--ghost btn--lg" href="<?= e(tel_href()) ?>"><?= icon('phone') ?>Позвонить</a>
                </div>
                <ul class="hero__facts">
                    <li><strong>от <?= e(money($minPrice)) ?></strong><span>за номер в сутки</span></li>
<?php if ($weekTier): ?>
                    <li><strong>−<?= (int) $weekTier['percent'] ?>%</strong><span><?= e(mb_strtolower($weekTier['label'])) ?></span></li>
<?php endif; ?>
                    <li><strong><?= e($h['response_time']) ?></strong><span>ответ на заявку</span></li>
                </ul>
            </div>
            <div class="hero__media">
                <?= photo_placeholder('уютный номер в тёплом свете', 'arch hero__photo') ?>
                <p class="walk-badge"><?= icon('footprints') ?><span><strong><?= e($walk) ?> минут</strong> пешком до диспансера</span></p>
            </div>
        </div>

        <div class="container">
            <form class="quick" action="#booking" method="get" data-quick aria-label="Быстрый расчёт стоимости">
                <div class="field">
                    <label for="q-in">Заезд</label>
                    <input id="q-in" type="date" name="check_in" min="<?= $today->format('Y-m-d') ?>" value="<?= e($form['check_in']) ?>" data-date-in>
                </div>
                <div class="field">
                    <label for="q-out">Выезд</label>
                    <input id="q-out" type="date" name="check_out" min="<?= $today->modify('+1 day')->format('Y-m-d') ?>" value="<?= e($form['check_out']) ?>" data-date-out>
                </div>
                <div class="field">
                    <label for="q-room">Номер</label>
                    <select id="q-room" name="room">
                        <option value="">Любой подходящий</option>
<?php foreach ($rooms as $key => $r): ?>
                        <option value="<?= e($key) ?>"<?= $form['room'] === $key ? ' selected' : '' ?>><?= e($r['name']) ?> — <?= e(room_price($r)) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="field field--narrow">
                    <label for="q-guests">Гостей</label>
                    <select id="q-guests" name="guests">
<?php for ($g = 1; $g <= max(array_column($rooms, 'guests')); $g++): ?>
                        <option value="<?= $g ?>"<?= $form['guests'] === (string) $g ? ' selected' : '' ?>><?= $g ?></option>
<?php endfor; ?>
                    </select>
                </div>
                <button class="btn btn--primary quick__submit" type="submit">Рассчитать стоимость<?= icon('arrow-right') ?></button>
            </form>
        </div>
    </section>

    <!-- ============================================================
         Почему здесь
         ============================================================ -->
    <section class="section section--tight" aria-label="Почему гости выбирают нас">
        <div class="container">
            <ul class="features">
                <li class="feature">
                    <span class="feature__icon"><?= icon('map-pin') ?></span>
                    <h2 class="feature__title">Рядом с диспансером</h2>
                    <p><?= e($walk) ?> минут пешком. Не нужно тратить силы на дорогу и ждать такси после процедур.</p>
                </li>
                <li class="feature">
                    <span class="feature__icon"><?= icon('moon') ?></span>
                    <h2 class="feature__title">Тишина и отдых</h2>
                    <p>Тихие часы с <?= e(str_replace('–', ' до ', $h['quiet_hours'])) ?>, в номерах не курят. Удобные кровати и чистое бельё — можно выспаться и днём.</p>
                </li>
                <li class="feature">
                    <span class="feature__icon"><?= icon('house') ?></span>
                    <h2 class="feature__title">Как дома</h2>
                    <p>Холодильник, чайник и посуда в каждом номере — можно есть привычную еду и соблюдать диету.</p>
                </li>
                <li class="feature">
                    <span class="feature__icon"><?= icon('shield-check') ?></span>
                    <h2 class="feature__title">Честные условия</h2>
                    <p>Цена за номер, без доплат. Чек, договор и справка о проживании — если нужны для работы или отчёта.</p>
                </li>
            </ul>
        </div>
    </section>

    <!-- ============================================================
         Номера
         ============================================================ -->
    <section class="section section--cream" id="rooms" aria-labelledby="rooms-title">
        <div class="container">
            <div class="section-head">
                <h2 class="h2" id="rooms-title">Выберите <em>номер</em></h2>
                <p class="section-head__note">В каждом — свой санузел, холодильник и удобная кровать. Цена за номер в сутки, а не за человека.</p>
            </div>

            <div class="rooms">
<?php foreach ($rooms as $key => $r): ?>
                <article class="room<?= !empty($r['featured']) ? ' room--featured' : '' ?>" aria-labelledby="room-<?= e($key) ?>">
                    <div class="room__media">
<?php if ($r['photos']): ?>
                        <div class="room__gallery" tabindex="0" aria-label="Фотографии номера «<?= e($r['name']) ?>», листайте вбок">
<?php foreach ($r['photos'] as $i => $src): ?>
                            <img src="/<?= e(ltrim($src, '/')) ?>" alt="Номер «<?= e($r['name']) ?>», фото <?= $i + 1 ?>" loading="lazy" decoding="async" width="800" height="600">
<?php endforeach; ?>
                        </div>
<?php else: ?>
                        <?= photo_placeholder('«' . $r['name'] . '»', 'room__ph') ?>
<?php endif; ?>
<?php if (!empty($r['badge'])): ?>
                        <span class="room__badge"><?= e($r['badge']) ?></span>
<?php endif; ?>
                    </div>
                    <div class="room__body">
                        <header class="room__head">
                            <h3 class="room__name" id="room-<?= e($key) ?>"><?= e($r['name']) ?></h3>
                            <p class="room__price"><strong><?= e(room_price($r)) ?></strong> / сутки</p>
                        </header>
                        <ul class="room__facts">
                            <li><?= icon('users') ?>до <?= (int) $r['guests'] ?> <?= plural((int) $r['guests'], 'гостя', 'гостей', 'гостей') ?></li>
                            <li><?= icon('ruler') ?><?= e($r['area']) ?> м²</li>
                            <li><?= icon('bed-double') ?><?= e($r['bed']) ?></li>
                        </ul>
                        <p class="room__summary"><?= e($r['summary']) ?></p>
                        <details class="room__more">
                            <summary>Что в номере<?= icon('chevron-down') ?></summary>
                            <ul class="checklist">
<?php foreach ($r['features'] as $f): ?>
                                <li><?= icon('check') ?><?= e($f) ?></li>
<?php endforeach; ?>
                            </ul>
                        </details>
                        <a class="btn <?= !empty($r['featured']) ? 'btn--copper' : 'btn--outline' ?> room__cta" href="?room=<?= e($key) ?>#booking" data-book-room="<?= e($key) ?>">Забронировать</a>
                    </div>
                </article>
<?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Во время лечения
         ============================================================ -->
    <section class="section" id="care" aria-labelledby="care-title">
        <div class="container">
            <div class="section-head section-head--stack">
                <p class="eyebrow">Во время лечения</p>
                <h2 class="h2" id="care-title">Продумали то, что важно, <em>когда силы нужны на&nbsp;лечение</em></h2>
                <p class="section-head__lead">У нас останавливаются те, кто приехал на обследование, курс терапии или навестить близкого в стационаре. Поэтому быт устроен так, чтобы ни о чём лишнем не думать.</p>
            </div>
            <ul class="care">
                <li class="care__item">
                    <span class="care__icon"><?= icon('luggage') ?></span>
                    <h3>Ранний заезд и хранение багажа</h3>
                    <p>Приезжаете ночным поездом или первым автобусом? Если номер свободен — заселим раньше. Если нет — оставите вещи и пойдёте на приём налегке.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('calendar-clock') ?></span>
                    <h3>Даты сдвинулись — перенесём</h3>
                    <p>Госпитализацию переносят, анализы готовы позже — это обычное дело. Предупредите нас, и мы перенесём или продлим бронь.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('refrigerator') ?></span>
                    <h3>Холодильник в каждом номере</h3>
                    <p>Для привычной еды, диетических продуктов и лекарств, которые нужно хранить в холоде.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('heart-handshake') ?></span>
                    <h3>Можно вместе с близким</h3>
                    <p>Сопровождающий живёт в том же номере — цена за номер, а не за человека. А если близкий в стационаре, вы будете рядом каждый день.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('sparkles') ?></span>
                    <h3>Чисто и спокойно</h3>
                    <p>Регулярная уборка и свежее бельё, в номерах не курят, после <?= e(explode('–', $h['quiet_hours'])[0]) ?> — тишина. Нужно убрать чаще — просто скажите.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('file-text') ?></span>
                    <h3>Документы для отчёта</h3>
                    <p>Кассовый чек, договор и справка о проживании — для работодателя, бухгалтерии или командировочного отчёта.</p>
                </li>
            </ul>
        </div>
    </section>

    <!-- ============================================================
         Цены и долгое проживание
         ============================================================ -->
    <section class="section section--sand" id="prices" aria-labelledby="prices-title">
        <div class="container prices">
            <div class="prices__text">
                <p class="eyebrow">Долгое проживание</p>
                <h2 class="h2" id="prices-title">Приехали на всё <em>время лечения?</em></h2>
                <p>Чем дольше вы живёте, тем ниже цена. Продлить можно в любой день — просто скажите администратору.</p>
<?php if ($weekTier):
    $exRoom = $rooms[array_key_first($rooms)];
    $exNights = 21;
    $ex = estimate(array_key_first($rooms), $exNights);
?>
                <p class="prices__example">
                    <?= icon('info') ?>
                    <span>Например, 3 недели в номере «<?= e($exRoom['name']) ?>»: <strong><?= e(money((int) $ex['total'])) ?></strong> вместо <?= e(money($ex['base'])) ?> — экономия <?= e(money($ex['base'] - (int) $ex['total'])) ?>.</span>
                </p>
<?php endif; ?>
                <h3 class="prices__subtitle">В цену уже входит</h3>
                <ul class="checklist">
<?php foreach ($h['included'] as $item): ?>
                    <li><?= icon('check') ?><?= e($item) ?></li>
<?php endforeach; ?>
                </ul>
                <p class="prices__pay">Оплата: <?= e($h['payment']) ?>. Чек выдаём всегда.</p>
            </div>

            <div class="price-table-wrap">
                <table class="price-table">
                    <caption class="visually-hidden">Цена за номер в сутки в зависимости от срока проживания</caption>
                    <thead>
                        <tr>
                            <th scope="col">Номер</th>
                            <th scope="col">Посуточно</th>
<?php foreach ($h['long_stay'] as $tier): ?>
                            <th scope="col" class="<?= $tier === $weekTier ? 'is-accent' : '' ?>"><?= e($tier['label']) ?><span><?= e($tier['text']) ?></span></th>
<?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($rooms as $key => $r): ?>
                        <tr>
                            <th scope="row"><?= e($r['name']) ?></th>
                            <td data-label="Посуточно"><?= e(room_price($r)) ?></td>
<?php foreach ($h['long_stay'] as $tier): ?>
                            <td data-label="<?= e($tier['label']) ?>" class="<?= $tier === $weekTier ? 'is-accent' : '' ?>"><?php
    if ($tier['percent'] === null) {
        echo e($tier['text']);
    } else {
        $p = discounted((int) $r['price'], (int) $tier['percent']);
        echo e(!empty($r['price_tbd']) ? '[' . number_format($p, 0, ',', "\u{00A0}") . "]\u{00A0}₽" : money($p));
    }
?></td>
<?php endforeach; ?>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
                <p class="price-table__note">Цена за номер в сутки. Скидка действует на весь срок проживания.</p>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Бронирование
         ============================================================ -->
    <section class="section section--cream" id="booking" aria-labelledby="booking-title">
        <div class="container">
            <div class="section-head section-head--stack">
                <p class="eyebrow">Заявка на бронирование</p>
                <h2 class="h2" id="booking-title">Узнайте цену <em>на ваши даты</em></h2>
                <p class="section-head__lead">Это заявка, а не оплата — она ни к чему не обязывает. Администратор проверит наличие и перезвонит в течение <?= e($h['response_time']) ?> (<?= e($h['admin_hours']) ?>).</p>
            </div>

<?php if ($bookingResult === 'ok'): ?>
            <div class="notice notice--ok" role="status">
                <?= icon('check') ?>
                <div><strong>Заявка отправлена.</strong> Перезвоним в течение <?= e($h['response_time']) ?>. Если не дозвонимся — напишем.</div>
            </div>
<?php elseif ($bookingResult === 'error'): ?>
            <div class="notice notice--error" role="alert">
                <?= icon('info') ?>
                <div><strong>Заявка не отправилась.</strong> Пожалуйста, позвоните нам: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a> — или напишите в <a href="<?= e(whatsapp_href()) ?>">WhatsApp</a>.</div>
            </div>
<?php endif; ?>

            <div class="booking">
                <form class="booking__form card" action="/api/booking.php" method="post" data-booking>
                    <input type="hidden" name="type" value="booking">
                    <input type="hidden" name="ts" value="<?= time() ?>">
                    <div class="hp" aria-hidden="true">
                        <label for="b-website">Не заполняйте это поле</label>
                        <input id="b-website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <fieldset class="booking__group">
                        <legend>Даты и номер</legend>
                        <div class="grid-2">
                            <div class="field">
                                <label for="b-in">Заезд <span class="req" aria-hidden="true">*</span></label>
                                <input id="b-in" type="date" name="check_in" required min="<?= $today->format('Y-m-d') ?>" value="<?= e($form['check_in']) ?>" data-date-in>
                            </div>
                            <div class="field">
                                <label for="b-out">Выезд <span class="req" aria-hidden="true">*</span></label>
                                <input id="b-out" type="date" name="check_out" required min="<?= $today->modify('+1 day')->format('Y-m-d') ?>" value="<?= e($form['check_out']) ?>" data-date-out>
                            </div>
                            <div class="field">
                                <label for="b-room">Номер</label>
                                <select id="b-room" name="room" data-room>
                                    <option value="">Подберите мне подходящий</option>
<?php foreach ($rooms as $key => $r): ?>
                                    <option value="<?= e($key) ?>"<?= $form['room'] === $key ? ' selected' : '' ?>><?= e($r['name']) ?> — <?= e(room_price($r)) ?></option>
<?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="b-guests">Сколько гостей</label>
                                <select id="b-guests" name="guests" data-guests>
<?php for ($g = 1; $g <= max(array_column($rooms, 'guests')); $g++): ?>
                                    <option value="<?= $g ?>"<?= $form['guests'] === (string) $g ? ' selected' : '' ?>><?= $g ?></option>
<?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <p class="field-hint" data-capacity-hint hidden></p>
                        <div class="summary summary--inline" aria-live="polite">
                            <div data-summary-body></div>
                        </div>
                    </fieldset>

                    <fieldset class="booking__group">
                        <legend>Как с вами связаться</legend>
                        <div class="grid-2">
                            <div class="field">
                                <label for="b-name">Имя <span class="req" aria-hidden="true">*</span></label>
                                <input id="b-name" type="text" name="name" required maxlength="80" autocomplete="name" placeholder="Как к вам обращаться">
                            </div>
                            <div class="field">
                                <label for="b-phone">Телефон <span class="req" aria-hidden="true">*</span></label>
                                <input id="b-phone" type="tel" name="phone" required maxlength="25" autocomplete="tel" inputmode="tel" placeholder="+7 978 000-00-00" data-phone>
                            </div>
                        </div>
                        <div class="field">
                            <span class="field__label" id="b-contact-label">Удобнее, если мы</span>
                            <div class="chips" role="radiogroup" aria-labelledby="b-contact-label">
                                <label class="chip"><input type="radio" name="contact" value="call" checked><span>Позвоним</span></label>
<?php foreach (messenger_links() as $m): ?>
                                <label class="chip"><input type="radio" name="contact" value="<?= e($m['key']) ?>"><span>Напишем в <?= e($m['label']) ?></span></label>
<?php endforeach; ?>
                            </div>
                            <p class="field-hint">Если вы на процедурах и не можете ответить на звонок — выберите мессенджер.</p>
                        </div>
                        <div class="field">
                            <label for="b-comment">Комментарий</label>
                            <textarea id="b-comment" name="comment" rows="3" maxlength="1000" placeholder="Например: приедем в 6 утра, нужен номер без ступенек" aria-describedby="b-comment-hint"></textarea>
                            <p class="field-hint" id="b-comment-hint">Диагноз и другие сведения о здоровье писать не нужно — для брони они не важны.</p>
                        </div>
                    </fieldset>

                    <div class="consent">
                        <label class="check">
                            <input type="checkbox" name="consent" value="1" required>
                            <span>Даю <a href="/consent.php" target="_blank">согласие на обработку персональных данных</a> и подтверждаю, что ознакомлен(а) с <a href="/privacy.php" target="_blank">политикой</a>.</span>
                        </label>
                    </div>

                    <div class="form-error" role="alert" data-form-error hidden></div>

                    <button class="btn btn--primary btn--lg btn--block" type="submit" data-submit>
                        <span data-submit-label>Отправить заявку</span>
                    </button>
                    <p class="booking__alt">Или позвоните: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a></p>
                </form>

                <aside class="booking__side" aria-label="Расчёт стоимости">
                    <div class="summary card" aria-live="polite">
                        <h3 class="summary__title">Предварительный расчёт</h3>
                        <div data-summary-body>
                            <p class="summary__empty">Выберите даты и номер — посчитаем стоимость с учётом скидки за длительное проживание.</p>
                        </div>
                    </div>
                    <ol class="steps">
                        <li><strong>Вы отправляете заявку.</strong> Это бесплатно и ни к чему не обязывает.</li>
                        <li><strong>Мы перезваниваем</strong> в течение <?= e($h['response_time']) ?>, подтверждаем наличие и точную цену.</li>
                        <li><strong>Предоплата</strong> <?= e($h['prepayment']) ?> — и номер закреплён за вами.</li>
                    </ol>
                </aside>
            </div>

            <div class="booking-success card" data-booking-success hidden tabindex="-1">
                <span class="booking-success__icon"><?= icon('check') ?></span>
                <h3 class="h3">Заявка отправлена</h3>
                <p>Спасибо! Администратор перезвонит в течение <?= e($h['response_time']) ?> и подтвердит бронь. Если не дозвонимся — напишем.</p>
                <p class="booking-success__muted">Срочный вопрос? Звоните: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a></p>
            </div>
        </div>
    </section>

<?php if (!empty($h['bath']['enabled'])): ?>
    <!-- ============================================================
         Баня и хамам
         ============================================================ -->
    <section class="section section--dark bath" id="bath" aria-labelledby="bath-title">
        <div class="container bath__grid">
            <div class="bath__media">
                <?= photo_placeholder('баня', 'ph--dark bath__ph') ?>
                <?= photo_placeholder('хамам', 'ph--dark arch bath__ph') ?>
            </div>
            <div class="bath__text">
                <p class="eyebrow eyebrow--light"><?= e($h['bath']['status']) ?> · <?= e($h['bath']['opening']) ?></p>
                <h2 class="h2" id="bath-title">Баня <em>и хамам</em></h2>
                <p>Место, где можно выдохнуть после долгого дня. Для гостей гостиницы и жителей города — с отдельным входом, так что в гостинице остаётся тихо.</p>
                <p class="bath__note">Перед посещением бани во время лечения посоветуйтесь с лечащим врачом.</p>
<?php if ($notifyResult === 'ok'): ?>
                <p class="notice notice--light" role="status"><?= icon('check') ?>Спасибо! Сообщим, как только откроемся.</p>
<?php elseif ($notifyResult === 'error'): ?>
                <p class="notice notice--light" role="alert"><?= icon('info') ?>Не получилось сохранить номер. Позвоните нам: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a></p>
<?php endif; ?>
                <details class="bath__notify">
                    <summary class="btn btn--light">Узнать об открытии</summary>
                    <form class="bath__form" action="/api/booking.php" method="post" data-notify>
                        <input type="hidden" name="type" value="bath">
                        <input type="hidden" name="ts" value="<?= time() ?>">
                        <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                        <div class="field">
                            <label for="n-phone">Телефон — сообщим, когда откроемся</label>
                            <div class="inline-form">
                                <input id="n-phone" type="tel" name="phone" required maxlength="25" autocomplete="tel" inputmode="tel" placeholder="+7 978 000-00-00" data-phone>
                                <button class="btn btn--copper" type="submit">Сообщить мне</button>
                            </div>
                        </div>
                        <label class="check check--light">
                            <input type="checkbox" name="consent" value="1" required>
                            <span>Даю <a href="/consent.php" target="_blank">согласие на обработку персональных данных</a></span>
                        </label>
                        <p class="form-msg" role="status" data-notify-msg hidden></p>
                    </form>
                </details>
            </div>
        </div>
    </section>
<?php endif; ?>

    <!-- ============================================================
         Вопросы
         ============================================================ -->
    <section class="section" id="faq" aria-labelledby="faq-title">
        <div class="container faq">
            <div class="faq__head">
                <h2 class="h2" id="faq-title">Частые <em>вопросы</em></h2>
                <p>Не нашли ответа? Позвоните — администратор подскажет.</p>
                <a class="btn btn--outline" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
            </div>
            <div class="faq__list">
<?php foreach ($faq as $i => $q): ?>
                <details class="faq__item"<?= $i === 0 ? ' open' : '' ?>>
                    <summary><?= e($q[0]) ?><?= icon('chevron-down') ?></summary>
                    <p><?= e($q[1]) ?></p>
                </details>
<?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Отзывы — честно отправляем на независимые площадки
         ============================================================ -->
    <section class="section section--tight reviews" aria-labelledby="reviews-title">
        <div class="container">
            <div class="reviews__inner">
                <div>
                    <h2 class="h3" id="reviews-title">Отзывы гостей</h2>
                    <p>Мы не публикуем отзывы на своём сайте — их легко подделать. Почитайте настоящие на независимых площадках.</p>
                </div>
                <div class="reviews__links">
                    <a class="btn btn--outline" href="<?= e(filled($h['listings']['yandex']) ? $h['listings']['yandex'] : map_href()) ?>" target="_blank" rel="noopener"><?= icon('star') ?>Яндекс Карты</a>
                    <a class="btn btn--outline" href="<?= e(twogis_href()) ?>" target="_blank" rel="noopener"><?= icon('star') ?>2ГИС</a>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Контакты
         ============================================================ -->
    <section class="section section--cream" id="contacts" aria-labelledby="contacts-title">
        <div class="container contacts">
            <div class="contacts__info">
                <h2 class="h2" id="contacts-title">Как нас <em>найти</em></h2>
                <dl class="contacts__list">
                    <div><dt>Адрес</dt><dd><?= e(full_address()) ?><br><span class="muted"><?= e($h['address']['entrance']) ?></span></dd></div>
                    <div><dt>Телефон</dt><dd><a class="contacts__phone" href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a><br><span class="muted">Администратор на связи <?= e($h['admin_hours']) ?>. Отвечаем в течение <?= e($h['response_time']) ?>.</span></dd></div>
                    <div><dt>Заселение</dt><dd>с <?= e($h['check_in']) ?>, выезд до <?= e($h['check_out']) ?></dd></div>
                </dl>
                <div class="contacts__buttons">
<?php foreach (messenger_links() as $i => $m): ?>
                    <a class="btn <?= $i === 0 ? 'btn--primary' : 'btn--outline' ?>" href="<?= e($m['href']) ?>" target="_blank" rel="noopener"><?= icon($m['icon']) ?><?= e($m['label']) ?></a>
<?php endforeach; ?>
                </div>

                <h3 class="contacts__subtitle">Что рядом</h3>
                <ul class="nearby">
<?php foreach ($h['nearby'] as $n): ?>
                    <li><?= icon($n['icon']) ?><span><?= e($n['name']) ?></span><span class="nearby__time"><?= e($n['time']) ?></span></li>
<?php endforeach; ?>
                </ul>
            </div>

            <div class="contacts__map">
                <div class="map" data-map<?= map_widget_src() ? ' data-map-src="' . e(map_widget_src()) . '"' : '' ?>>
                    <div class="map__placeholder">
                        <?= icon('map-pin', 'map__pin') ?>
                        <p class="map__address"><?= e(full_address()) ?></p>
<?php if (map_widget_src()): ?>
                        <button class="btn btn--light" type="button" data-map-load>Показать карту</button>
                        <p class="map__hint">Карта загружается с сервиса Яндекса</p>
<?php else: ?>
                        <p class="map__hint"><?= e('[Яндекс Карта — появится, когда в конфиге будут координаты]') /* проверяется в tools/check.php через address.lat/lon */ ?></p>
<?php endif; ?>
                    </div>
                </div>
                <div class="map__actions">
                    <a class="btn btn--outline btn--sm" href="<?= e(route_href()) ?>" target="_blank" rel="noopener"><?= icon('navigation') ?>Маршрут в Яндекс Картах</a>
                    <a class="btn btn--outline btn--sm" href="<?= e(twogis_href()) ?>" target="_blank" rel="noopener"><?= icon('map-pin') ?>Открыть в 2ГИС</a>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- Панель действий внизу экрана на телефоне -->
<div class="action-bar" data-action-bar>
    <a class="btn btn--ghost" href="<?= e(tel_href()) ?>"><?= icon('phone') ?>Позвонить</a>
    <a class="btn btn--primary" href="#booking">Забронировать</a>
</div>

<script type="application/json" id="pricing-data"><?= pricing_json() ?></script>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
