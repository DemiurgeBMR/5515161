<?php
$h = hotel();
$l = $h['legal'];
?>
<footer class="site-footer">
    <div class="container site-footer__grid">
        <div class="site-footer__brand">
            <p class="logo__name"><?= e($h['name']) ?></p>
            <p class="site-footer__muted"><?= e($h['kind']) ?> в Симферополе, <?= e($h['address']['street']) ?></p>
            <p><a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a></p>
        </div>
        <nav class="site-footer__links" aria-label="Документы">
            <a href="/rules.php">Правила проживания</a>
            <a href="/privacy.php">Политика обработки персональных данных</a>
            <a href="/consent.php">Согласие на обработку персональных данных</a>
<?php if (!empty($metrika)): ?>
            <button type="button" class="link-button site-footer__cookie" data-cookie-settings>Настройки cookie</button>
<?php endif; ?>
        </nav>
        <div class="site-footer__legal">
            <p><?= e($l['operator']) ?>, ИНН <?= e($l['inn']) ?>, <?= ogrn_label() ?> <?= e($l['ogrn']) ?></p>
            <p>Номер в реестре средств размещения: <?= e($l['registry_number']) ?></p>
            <p class="site-footer__muted">Цены на сайте — справочные и не являются публичной офертой. Наличие и итоговую стоимость подтверждает администратор.</p>
        </div>
    </div>
    <div class="container">
        <p class="site-footer__bottom">© <?= date('Y') ?> <?= e($h['name']) ?></p>
    </div>
</footer>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
