<?php
require __DIR__ . '/../src/bootstrap.php';

if (!db_ready()) {
    require __DIR__ . '/../src/partials/maintenance.php';
    exit;
}

$h = hotel();
$types = room_types();
$discounts = stay_discounts();
$prices = array_column($types, 'base_price');
$minPrice = $prices ? min($prices) : 0;
$maxPrice = $prices ? max($prices) : 0;
$maxGuests = $types ? max(array_column($types, 'capacity')) : 1;
$weekTier = null;
foreach ($discounts as $tier) {
    if ($tier['percent'] !== null) {
        $weekTier = $tier;
        break;
    }
}
$badge = $h['location_badge'];
$district = $h['address']['district'];
$today = today();
$prepaymentText = prepayment_mode_label();

// Значения формы: из быстрой формы на первом экране без JS (GET) или пустые.
$form = [
    'check_in'  => get('check_in'),
    'check_out' => get('check_out'),
    'room'      => (int) get('room', '0'),
    'guests'    => get('guests', '1'),
];
if (!isset($types[$form['room']])) {
    $form['room'] = 0;
}
// Результат отправки без JS (api/booking.php возвращает сюда при ошибке).
$bookingResult = get('booking') ?: null;
$bookingMessage = mb_substr(get('msg'), 0, 300);
$notifyResult = get('notify') ?: null;

$faq = [
    ['Что нужно для заселения?',
     'Паспорт каждого взрослого гостя, для детей — свидетельство о рождении. Мы обязаны зарегистрировать всех, кто живёт в номере, — это требование закона. Иностранных гостей ставим на миграционный учёт: нужны паспорт и миграционная карта.'],
    ['Мы приезжаем ночным поездом. Можно заселиться рано утром?',
     'Заселение с ' . $h['check_in'] . ', выезд до ' . $h['check_out'] . '. Если номер свободен — заселим раньше. Если нет — оставьте багаж у нас и спокойно идите по делам. Напишите время приезда в заявке, и мы постараемся подготовить номер заранее.'],
    ['Планы могут измениться. Что будет с бронью?',
     'Предупредите нас как можно раньше — перенесём даты или продлим проживание. Условия отмены: ' . $h['cancellation'] . '.'],
    ['Можно продлить проживание?',
     'Да, в любой день — скажите администратору или напишите со страницы своей брони (ссылка приходит в письме и есть в разделе «Мои брони»). Если ваш номер на новые даты уже занят, предложим другой. Чем дольше живёте, тем ниже цена за сутки.'],
    ['Можно заселиться семьёй или компанией?',
     'Да. Цена — за номер, а не за человека. ' . implode(', ', array_map(
         fn ($t) => '«' . $t['name'] . '» — до ' . $t['capacity'] . ' ' . plural($t['capacity'], 'гостя', 'гостей', 'гостей'),
         array_values($types)
     )) . '.'],
    ['Можно готовить?',
     'В каждом номере есть холодильник, чайник и посуда. [Общая кухня с плитой и микроволновкой.] Продуктовые магазины и аптеки — в шаговой доступности.'],
    ['Нужен номер без ступенек или на первом этаже',
     'Напишите об этом в комментарии к заявке или скажите по телефону — подберём подходящий номер. [Есть ли номера на первом этаже, лифт, пандус.]'],
    ['Как оплатить? Будут ли документы?',
     'Оплата: ' . $h['payment'] . '. Предоплата — ' . $prepaymentText . ', остальное при заселении. Всегда выдаём кассовый чек, а если нужно — договор и справку о проживании для работодателя или бухгалтерии.'],
    ['Как добраться от вокзала?',
     'Мы в ' . $district . ' (' . $h['address']['street'] . '). От ж/д вокзала и Центрального автовокзала — около 15 минут на такси. Остановка общественного транспорта «Улица Генерала Родионова» — на нашей улице, удобный маршрут от вашей точки покажут Яндекс Карты или 2ГИС. Если нужно, подскажем дорогу по телефону.'],
    ['Как добраться до моря?',
     'Симферополь — транспортный центр Крыма. Троллейбусы и автобусы в Алушту и Ялту идут от автостанции «Курортная» у ж/д вокзала, в Евпаторию, Феодосию, Керчь и другие города — от Центрального автовокзала. До обоих — около 15 минут на такси, так что от нас удобно ездить по всему полуострову.'],
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
    'title'       => $h['name'] . ' — гостиница в Симферополе, ' . $district,
    'description' => 'Номера от ' . number_format($minPrice, 0, ',', ' ') . ' ₽ в сутки в Симферополе: ' . $district
        . ', рядом студгородок КФУ, 15 минут до вокзала. Для командировок, поездок по Крыму и долгого проживания: тишина, холодильник в номере, скидки, документы для отчёта.',
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
                <h1 class="display" id="hero-title">Спокойное место <em>в&nbsp;самом центре Крыма</em></h1>
                <p class="lead">Уютные номера в Симферополе — <?= e($district) ?>, рядом студгородок КФУ. Удобно в командировку, проездом к морю или надолго — от одной ночи до нескольких месяцев.</p>
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
                <?= site_photo('hero', 'Уютный номер в тёплом свете', 'arch hero__photo', true) ?>
                <p class="walk-badge"><?= icon('navigation') ?><span><strong><?= e($badge['title']) ?></strong> <?= e($badge['text']) ?></span></p>
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
                        <option value="0">Любой подходящий</option>
<?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"<?= $form['room'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?> — <?= e(type_price_label($t)) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="field field--narrow">
                    <label for="q-guests">Гостей</label>
                    <select id="q-guests" name="guests">
<?php for ($g = 1; $g <= $maxGuests; $g++): ?>
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
                    <h2 class="feature__title">Удобное расположение</h2>
                    <p><?= e($district) ?>, рядом студгородок КФУ. До вокзалов и центра — около 15 минут на такси, остановка транспорта на нашей улице.</p>
                </li>
                <li class="feature">
                    <span class="feature__icon"><?= icon('moon') ?></span>
                    <h2 class="feature__title">Тишина и отдых</h2>
                    <p>Тихие часы с <?= e(str_replace('–', ' до ', $h['quiet_hours'])) ?>, в номерах не курят. Удобные кровати и чистое бельё — можно выспаться и днём.</p>
                </li>
                <li class="feature">
                    <span class="feature__icon"><?= icon('house') ?></span>
                    <h2 class="feature__title">Как дома</h2>
                    <p>Холодильник, чайник и посуда в каждом номере — завтрак и ужин можно устроить самим, не тратясь на кафе.</p>
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
<?php foreach ($types as $t): ?>
                <article class="room<?= $t['featured'] ? ' room--featured' : '' ?>" aria-labelledby="room-<?= $t['id'] ?>" data-room="<?= $t['id'] ?>">
                    <div class="room__media">
<?php if ($t['photos']): ?>
                        <div class="room__gallery" tabindex="0" data-gallery aria-label="Фотографии номера «<?= e($t['name']) ?>»: листайте вбок, нажмите, чтобы открыть крупно">
<?php foreach ($t['photos'] as $i => $photo): ?>
                            <img src="/<?= e($photo['path']) ?>"<?= img_srcset($photo['path'], '(max-width: 760px) 92vw, 420px', (int) $photo['width']) ?> alt="Номер «<?= e($t['name']) ?>», фото <?= $i + 1 ?>" loading="lazy" decoding="async" width="<?= (int) $photo['width'] ?>" height="<?= (int) $photo['height'] ?>">
<?php endforeach; ?>
                        </div>
<?php if (count($t['photos']) > 1): ?>
                        <button class="room__nav room__nav--prev" type="button" data-gal-prev aria-label="Предыдущее фото"><?= icon('arrow-right') ?></button>
                        <button class="room__nav room__nav--next" type="button" data-gal-next aria-label="Следующее фото"><?= icon('arrow-right') ?></button>
                        <span class="room__dots" aria-hidden="true"><?= str_repeat('<i></i>', count($t['photos'])) ?></span>
<?php endif; ?>
                        <button class="room__count" type="button" data-room-open><?= icon('camera') ?><?= count($t['photos']) ?> фото</button>
<?php else: ?>
                        <div class="room__ph-open" data-room-open><?= photo_placeholder('«' . $t['name'] . '»', 'room__ph') ?></div>
<?php endif; ?>
<?php if ($t['badge'] !== ''): ?>
                        <span class="room__badge"><?= e($t['badge']) ?></span>
<?php endif; ?>
                    </div>
                    <div class="room__body">
                        <header class="room__head">
                            <h3 class="room__name" id="room-<?= $t['id'] ?>"><button class="room__open" type="button" data-room-open><?= e($t['name']) ?></button></h3>
                            <p class="room__price"><strong><?= e(type_price_label($t)) ?></strong> / сутки</p>
                        </header>
                        <ul class="room__facts">
                            <li><?= icon('users') ?>до <?= $t['capacity'] ?> <?= plural($t['capacity'], 'гостя', 'гостей', 'гостей') ?></li>
<?php if ($t['area'] !== ''): ?>
                            <li><?= icon('ruler') ?><?= e($t['area']) ?> м²</li>
<?php endif; ?>
<?php if ($t['bed'] !== ''): ?>
                            <li><?= icon('bed-double') ?><?= e($t['bed']) ?></li>
<?php endif; ?>
                        </ul>
                        <p class="room__summary"><?= e($t['summary']) ?></p>
<?php if ($t['features_list']): ?>
                        <details class="room__more">
                            <summary>Что в номере<?= icon('chevron-down') ?></summary>
                            <ul class="checklist">
<?php foreach ($t['features_list'] as $f): ?>
                                <li><?= icon('check') ?><?= e($f) ?></li>
<?php endforeach; ?>
                            </ul>
                        </details>
<?php endif; ?>
                        <div class="room__actions">
                            <a class="btn <?= $t['featured'] ? 'btn--copper' : 'btn--outline' ?> room__cta" href="?room=<?= $t['id'] ?>#booking" data-book-room="<?= $t['id'] ?>">Забронировать</a>
                            <a class="room__dates" href="#calendar" data-show-calendar="<?= $t['id'] ?>">Свободные даты</a>
                            <button class="room__dates room__more-link" type="button" data-room-open>Подробнее</button>
                        </div>
                    </div>
                </article>
<?php endforeach; ?>
            </div>

            <!-- Окно номера: большие фото и описание. Заполняется из карточки (main.js). -->
            <dialog class="room-modal" data-room-modal aria-labelledby="room-modal-title">
                <div class="room-modal__inner">
                    <button class="room-modal__close" type="button" data-rm-close aria-label="Закрыть"><?= icon('x') ?></button>
                    <div class="room-modal__media">
                        <div class="room-modal__track" data-rm-track tabindex="0" aria-label="Фотографии номера, листайте вбок"></div>
                        <button class="room-modal__nav room-modal__nav--prev" type="button" data-rm-prev aria-label="Предыдущее фото"><?= icon('arrow-right') ?></button>
                        <button class="room-modal__nav room-modal__nav--next" type="button" data-rm-next aria-label="Следующее фото"><?= icon('arrow-right') ?></button>
                        <span class="room-modal__counter" data-rm-counter aria-live="polite"></span>
                    </div>
                    <div class="room-modal__thumbs" data-rm-thumbs></div>
                    <div class="room-modal__info">
                        <h2 class="room-modal__name" id="room-modal-title" data-rm-name></h2>
                        <p class="room__price" data-rm-price></p>
                        <ul class="room__facts" data-rm-facts></ul>
                        <p class="room-modal__summary" data-rm-summary></p>
                        <div data-rm-features></div>
                        <div class="room-modal__actions">
                            <button class="btn btn--primary" type="button" data-rm-book>Забронировать</button>
                            <button class="btn btn--outline" type="button" data-rm-dates>Свободные даты</button>
                        </div>
                    </div>
                </div>
            </dialog>
        </div>
    </section>

    <!-- ============================================================
         Удобно в любой поездке
         ============================================================ -->
    <section class="section" id="care" aria-labelledby="care-title">
        <div class="container">
            <div class="section-head section-head--stack">
                <p class="eyebrow">Удобно в любой поездке</p>
                <h2 class="h2" id="care-title">Продумали то, что важно, <em>когда вы в&nbsp;дороге</em></h2>
                <p class="section-head__lead">Командировка, остановка по пути к морю, учёба, экзамены или долгий рабочий проект — быт устроен так, чтобы думать только о своих делах.</p>
            </div>
            <ul class="care">
                <li class="care__item">
                    <span class="care__icon"><?= icon('luggage') ?></span>
                    <h3>Ранний заезд и хранение багажа</h3>
                    <p>Приезжаете ночным поездом или первым автобусом? Если номер свободен — заселим раньше. Если нет — оставите вещи и пойдёте по делам налегке.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('calendar-clock') ?></span>
                    <h3>Планы поменялись — перенесём</h3>
                    <p>Командировку продлили, поездку сдвинули — так бывает. Предупредите нас, и мы перенесём или продлим бронь.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('refrigerator') ?></span>
                    <h3>Холодильник в каждом номере</h3>
                    <p>И чайник с посудой: продукты из магазина рядом, завтрак в номере — как дома.</p>
                </li>
                <li class="care__item">
                    <span class="care__icon"><?= icon('users') ?></span>
                    <h3>Семьёй или компанией</h3>
                    <p>Цена за номер, а не за человека: в номерах до <?= $maxGuests ?> <?= plural($maxGuests, 'гостя', 'гостей', 'гостей') ?>. Удобно для семьи, коллег в командировке или друзей.</p>
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
                <h2 class="h2" id="prices-title">Приехали <em>надолго?</em></h2>
                <p>Чем дольше вы живёте, тем ниже цена. Продлить можно в любой день — просто скажите администратору.</p>
<?php if ($weekTier && $types && $weekTier['min_nights'] <= 21):
    $exType = reset($types);
    $ex = simple_estimate($exType, 21);
?>
                <p class="prices__example">
                    <?= icon('info') ?>
                    <span>Например, 3 недели в номере «<?= e($exType['name']) ?>»: <strong><?= e(money($ex['total'])) ?></strong> вместо <?= e(money($ex['sum'])) ?> — экономия <?= e(money($ex['sum'] - $ex['total'])) ?>.</span>
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
<?php foreach ($discounts as $tier): ?>
                            <th scope="col" class="<?= $tier === $weekTier ? 'is-accent' : '' ?>"><?= e($tier['label']) ?><span><?= e($tier['text']) ?></span></th>
<?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($types as $t): ?>
                        <tr>
                            <th scope="row"><?= e($t['name']) ?></th>
                            <td data-label="Посуточно"><?= e(type_price_label($t)) ?></td>
<?php foreach ($discounts as $tier): ?>
                            <td data-label="<?= e($tier['label']) ?>" class="<?= $tier === $weekTier ? 'is-accent' : '' ?>"><?= e($tier['percent'] === null ? $tier['text'] : type_price_label($t, (int) round($t['base_price'] * (100 - $tier['percent']) / 100))) ?></td>
<?php endforeach; ?>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
                <p class="price-table__note">Цена за номер в сутки. Скидка действует на весь срок проживания. В праздники цена может отличаться — точную сумму на ваши даты покажет форма брони.</p>
            </div>
        </div>
    </section>

    <!-- ============================================================
         Свободные даты
         ============================================================ -->
    <section class="section" id="calendar" aria-labelledby="calendar-title">
        <div class="container">
            <div class="section-head">
                <h2 class="h2" id="calendar-title">Свободные <em>даты</em></h2>
                <p class="section-head__note">Календарь обновляется сам каждую минуту. Выберите дату заезда, затем дату выезда — и мы перенесём их в заявку.</p>
            </div>
            <div class="cal" data-calendar aria-live="polite">
                <div class="cal__toolbar">
                    <div class="cal__types" role="group" aria-label="Тип номера" data-cal-types>
                        <button type="button" class="cal__type" data-type="0" aria-pressed="true">Любой номер</button>
<?php foreach ($types as $t): ?>
                        <button type="button" class="cal__type" data-type="<?= $t['id'] ?>" aria-pressed="false"><?= e($t['name']) ?></button>
<?php endforeach; ?>
                    </div>
                    <div class="cal__nav">
                        <button type="button" class="cal__arrow" data-cal-prev aria-label="Предыдущий месяц"><?= icon('arrow-right') ?></button>
                        <button type="button" class="cal__arrow" data-cal-next aria-label="Следующий месяц"><?= icon('arrow-right') ?></button>
                    </div>
                </div>
                <div class="cal__months" data-cal-months>
                    <p class="cal__loading">Загружаем свободные даты…</p>
                </div>
                <div class="cal__footer">
                    <ul class="cal__legend">
                        <li><span class="cal__dot cal__dot--free"></span>свободно</li>
                        <li><span class="cal__dot cal__dot--few"></span>остался 1 номер</li>
                        <li><span class="cal__dot cal__dot--full"></span>занято</li>
                    </ul>
                    <div class="cal__pick" data-cal-pick>
                        <p class="cal__pick-text" data-cal-pick-text>Выберите дату заезда</p>
                        <button type="button" class="btn btn--primary" data-cal-book hidden>Забронировать эти даты<?= icon('arrow-right') ?></button>
                    </div>
                </div>
                <noscript><p class="cal__noscript">Календарь свободных дат работает с включённым JavaScript. Узнать, свободен ли номер, можно по телефону <?= e($h['phone']) ?>.</p></noscript>
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

<?php if ($bookingResult === 'error'): ?>
            <div class="notice notice--error" role="alert">
                <?= icon('info') ?>
                <div><strong>Заявка не отправилась.</strong> <?= $bookingMessage !== '' ? e($bookingMessage) . ' ' : '' ?>Можно позвонить нам: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a> — или написать в <a href="<?= e(whatsapp_href()) ?>">WhatsApp</a>.</div>
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
                                <input id="b-in" type="date" name="check_in" required min="<?= $today->format('Y-m-d') ?>" value="<?= e($form['check_in']) ?>" data-date-in aria-describedby="b-in-hint">
                                <p class="field-hint" id="b-in-hint">Заезд с <?= e($h['check_in']) ?></p>
                            </div>
                            <div class="field">
                                <label for="b-out">Выезд <span class="req" aria-hidden="true">*</span></label>
                                <input id="b-out" type="date" name="check_out" required min="<?= $today->modify('+1 day')->format('Y-m-d') ?>" value="<?= e($form['check_out']) ?>" data-date-out aria-describedby="b-out-hint">
                                <p class="field-hint" id="b-out-hint">Выезд до <?= e($h['check_out']) ?></p>
                            </div>
                            <div class="field">
                                <label for="b-room">Номер</label>
                                <select id="b-room" name="room" data-room>
                                    <option value="0">Подберите мне подходящий</option>
<?php foreach ($types as $t): ?>
                                    <option value="<?= $t['id'] ?>"<?= $form['room'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?> — <?= e(type_price_label($t)) ?></option>
<?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="b-guests">Сколько гостей</label>
                                <select id="b-guests" name="guests" data-guests>
<?php for ($g = 1; $g <= $maxGuests; $g++): ?>
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
                            <label for="b-email">Почта</label>
                            <input id="b-email" type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" placeholder="name@mail.ru" aria-describedby="b-email-hint">
                            <p class="field-hint" id="b-email-hint">Необязательно. Пришлём подтверждение и ссылку на бронь — по ней можно написать администратору или попросить продлить проживание.</p>
                        </div>
                        <div class="field">
                            <span class="field__label" id="b-contact-label">Удобнее, если мы</span>
                            <div class="chips" role="radiogroup" aria-labelledby="b-contact-label">
                                <label class="chip"><input type="radio" name="contact" value="call" checked><span>Позвоним</span></label>
<?php foreach (messenger_links() as $m): ?>
                                <label class="chip"><input type="radio" name="contact" value="<?= e($m['key']) ?>"><span>Напишем в <?= e($m['label']) ?></span></label>
<?php endforeach; ?>
                            </div>
                            <p class="field-hint">Если вам неудобно говорить по телефону — выберите мессенджер.</p>
                        </div>
                        <div class="field">
                            <label for="b-comment">Комментарий</label>
                            <textarea id="b-comment" name="comment" rows="3" maxlength="1000" placeholder="Например: приедем в 6 утра, нужен номер без ступенек"></textarea>
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
<?php if ($prepaymentText !== 'без предоплаты'): ?>
                        <li><strong>Предоплата</strong> — <?= e($prepaymentText) ?>, и номер закреплён за вами.</li>
<?php else: ?>
                        <li><strong>Номер закреплён за вами</strong> — оплата при заселении.</li>
<?php endif; ?>
                    </ol>
                </aside>
            </div>

            <div class="booking-success card" data-booking-success hidden tabindex="-1">
                <span class="booking-success__icon"><?= icon('check') ?></span>
                <h3 class="h3">Заявка <span data-success-number></span> принята</h3>
                <p data-success-details></p>
                <p>Номер уже держим за вами. Администратор перезвонит в течение <?= e($h['response_time']) ?> и подтвердит бронь.</p>
                <p data-success-email hidden></p>
                <p class="booking-success__action"><a class="btn btn--primary" href="#" data-success-link>Открыть мою бронь<?= icon('arrow-right') ?></a></p>
                <p class="booking-success__muted">Сохраните ссылку на бронь — по ней видно статус и можно написать администратору. Срочный вопрос? Звоните: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a></p>
            </div>
        </div>
    </section>

<?php if (bath_enabled()): $bs = bath_settings(); $bathPhotos = bath_photos(); ?>
    <!-- ============================================================
         Баня и хамам: вид зависит от статуса, который ставит владелец
         (скоро открытие / работает / временно закрыта)
         ============================================================ -->
    <section class="section section--dark bath bath--<?= e($bs['state']) ?>" id="bath" aria-labelledby="bath-title">
        <div class="container bath__grid">
            <a class="bath__media" href="/bath.php" aria-label="<?= e($bs['title']) ?> — фото и подробности">
                <?= isset($bathPhotos[0]) ? bath_photo_img($bathPhotos[0], $bs['title'], 'ph--dark bath__ph', '(max-width: 760px) 50vw, 25vw') : site_photo('bath', 'Баня', 'ph--dark bath__ph') ?>
                <?= isset($bathPhotos[1]) ? bath_photo_img($bathPhotos[1], $bs['title'], 'ph--dark arch bath__ph', '(max-width: 760px) 50vw, 25vw') : site_photo('hammam', 'Хамам', 'ph--dark arch bath__ph') ?>
<?php if (count($bathPhotos) > 2): ?>
                <span class="bath__more"><?= icon('camera') ?>Ещё <?= count($bathPhotos) - 2 ?> фото</span>
<?php endif; ?>
            </a>
            <div class="bath__text">
                <p class="eyebrow eyebrow--light"><?= e(bath_eyebrow()) ?></p>
                <h2 class="h2" id="bath-title"><?= bath_title_html() ?></h2>
                <p><?= e($bs['lead']) ?></p>
<?php if ($bs['features']): ?>
                <ul class="bath__features">
<?php foreach (array_slice($bs['features'], 0, 5) as $f): ?>
                    <li><?= icon('check') ?><?= e($f) ?></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
<?php if ($bs['state'] === 'open'): ?>
                <p class="bath__price"><strong><?= e(bath_price_from()) ?></strong> · от <?= $bs['min_hours'] ?> <?= plural($bs['min_hours'], 'часа', 'часов', 'часов') ?>, до <?= $bs['guests_included'] ?> <?= plural($bs['guests_included'], 'гостя', 'гостей', 'гостей') ?><?php if ($bs['hotel_discount'] > 0): ?><br>Гостям гостиницы — скидка <?= $bs['hotel_discount'] ?>%<?php endif; ?></p>
                <div class="bath__actions">
                    <a class="btn btn--copper" href="/bath.php#book"><?= icon('calendar-days') ?>Выбрать время</a>
                    <a class="btn btn--light" href="/bath.php">Фото и цены</a>
                </div>
<?php elseif ($bs['state'] === 'closed'): ?>
                <p class="bath__price"><?= e($bs['closed_note'] !== '' ? $bs['closed_note'] : 'Баня временно не работает. Подробности — по телефону.') ?></p>
                <div class="bath__actions">
                    <a class="btn btn--light" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
                </div>
<?php else: ?>
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
<?php if ($bathPhotos): ?>
                <p class="bath__link"><a href="/bath.php">Фото и подробности<?= icon('arrow-right') ?></a></p>
<?php endif; ?>
<?php endif; ?>
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

<script type="application/json" id="site-data"><?= json_encode([
    'types'    => array_map(fn ($t) => ['name' => $t['name'], 'capacity' => $t['capacity']], $types),
    'phone'    => $h['phone'],
    'whatsapp' => whatsapp_href(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
