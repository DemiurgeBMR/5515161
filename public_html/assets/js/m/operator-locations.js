/**
 * Телефон (≤ 768px): «Мои точки» — аккордеон «Подробнее» в карточке точки и выбор
 * «что сделано» плитками в шторке «Отметить обслуживание» (плитки синхронизированы со
 * штатным <select id="serviceType">, который и уходит на сервер). Кнопки и плитки — телефонная
 * разметка (.m-only), на десктопе скрипт ничего не меняет.
 */
(function () {
    'use strict';
    var list = document.querySelector('.locations-container');
    if (!list) return;

    // ---------- Аккордеон карточки ----------
    function setOpen(card, open) {
        card.classList.toggle('is-open', open);
        var b = card.querySelector('.ol-more');
        if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    list.classList.add('m-acc');
    list.addEventListener('click', function (e) {
        var b = e.target.closest('.ol-more');
        if (!b) return;
        var card = b.closest('.ol-location-card');
        if (card) setOpen(card, !card.classList.contains('is-open'));
    });
    // Переход с дашборда по ссылке #ol-loc-N — карточка сразу раскрыта
    if (location.hash && /^#ol-loc-\d+$/.test(location.hash)) {
        var target = document.getElementById(location.hash.slice(1));
        if (target) setOpen(target, true);
    }

    // ---------- Шторка «Отметить обслуживание»: плитки ↔ select ----------
    var select = document.getElementById('serviceType');
    var opts = document.querySelector('.ol-svc-opts');
    if (!select || !opts) return;
    function syncFromSelect() {
        var r = opts.querySelector('input[value="' + select.value + '"]');
        if (r) r.checked = true;
    }
    opts.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'm_service_type') select.value = e.target.value;
    });
    if (typeof window.openServiceModal === 'function') {
        var orig = window.openServiceModal;
        window.openServiceModal = function (btn) {
            orig(btn);
            syncFromSelect();
        };
    }
    syncFromSelect();
})();
