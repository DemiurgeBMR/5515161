<?php
$h = hotel();
$nav = [
    '/#rooms'    => 'Номера',
    '/#calendar' => 'Свободные даты',
    '/#prices'   => 'Цены',
    '/#faq'      => 'Вопросы',
    '/#contacts' => 'Контакты',
    '/account.php' => 'Мои брони',
];
if (bath_enabled()) {
    // «Сауна» — после «Цен»; «Вопросы» уходят, чтобы шапка не переполнялась.
    $nav = ['/#rooms' => 'Номера', '/#calendar' => 'Свободные даты', '/#prices' => 'Цены', '/bath.php' => 'Сауна', '/#contacts' => 'Контакты', '/account.php' => 'Мои брони'];
}
?>
<header class="site-header" data-header>
    <div class="container site-header__inner">
        <a class="logo" href="/" aria-label="<?= e($h['kind']) ?> <?= e($h['name']) ?> — на главную">
            <svg class="logo__mark" viewBox="0 0 64 64" aria-hidden="true"><path d="M5 53 30 7l28 46H42L30 33 19 53H5Zm18-9h19L30 13 23 26l8 0 7 13H28l-5 5Z" fill="currentColor" fill-rule="evenodd"/></svg>
            <span class="logo__text"><span class="logo__sub"><?= e($h['kind']) ?></span><span class="logo__name"><?= e($h['name']) ?></span></span>
        </a>

        <nav class="nav" id="site-nav" aria-label="Разделы сайта" data-nav>
            <ul class="nav__list">
<?php foreach ($nav as $href => $label): ?>
                <li><a class="nav__link" href="<?= e($href) ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
            </ul>
            <div class="nav__contacts">
                <a class="nav__phone" href="<?= e(tel_href()) ?>"><?= icon('phone') ?><?= e($h['phone']) ?></a>
                <p class="nav__hours">Администратор: <?= e($h['admin_hours']) ?></p>
            </div>
        </nav>

        <div class="site-header__actions">
            <a class="header-phone" href="<?= e(tel_href()) ?>">
                <?= icon('phone') ?><span class="header-phone__num"><?= e($h['phone']) ?></span>
            </a>
            <a class="btn btn--primary btn--sm header-book" href="/#booking">Забронировать</a>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
                <span class="nav-toggle__open"><?= icon('menu') ?></span>
                <span class="nav-toggle__close"><?= icon('x') ?></span>
                <span class="visually-hidden">Меню</span>
            </button>
        </div>
    </div>
</header>
