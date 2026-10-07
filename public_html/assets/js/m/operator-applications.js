/**
 * Телефон (≤ 768px): «Мои заявки» — фильтр карточек по статусу чипами (.oa-chips).
 * Чипы и карточки есть только в телефонной разметке (.m-only), на десктопе скрипт ничего не делает.
 */
(function () {
    'use strict';
    var chips = document.querySelector('.oa-chips');
    var list = document.getElementById('oaList');
    if (!chips || !list) return;
    var empty = document.getElementById('oaFilterEmpty');

    chips.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-oa-filter]');
        if (!chip) return;
        var f = chip.getAttribute('data-oa-filter');
        Array.prototype.forEach.call(chips.querySelectorAll('[data-oa-filter]'), function (c) {
            var on = c === chip;
            c.classList.toggle('is-on', on);
            c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var shown = 0;
        Array.prototype.forEach.call(list.children, function (li) {
            var ok = !f || (f === 'unread' ? li.hasAttribute('data-unread') : li.getAttribute('data-status') === f);
            li.hidden = !ok;
            if (ok) shown++;
        });
        if (empty) empty.hidden = shown > 0;
        try { chip.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (err) {}
    });
})();
