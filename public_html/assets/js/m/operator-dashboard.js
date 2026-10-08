/**
 * Телефон (≤ 768px): дашборд оператора — группы «Требует внимания» раскрываются по нажатию
 * на заголовок (аккордеон). На десктопе ничего не делает: атрибуты и классы ставятся только
 * в телефонном режиме, стили — assets/css/pages/m/_m-operator-dashboard.css.
 */
(function () {
    'use strict';
    var wrap = document.querySelector('.attention-groups');
    if (!wrap) return;
    var groups = wrap.querySelectorAll('.attention-group[data-m-acc]');
    if (!groups.length) return;
    var PHONE = window.matchMedia('(max-width: 768px)');

    function head(g) { return g.querySelector('.attention-group-head > span'); }

    function apply() {
        var on = PHONE.matches;
        wrap.classList.toggle('m-acc', on);
        Array.prototype.forEach.call(groups, function (g) {
            var h = head(g);
            if (!h) return;
            if (on) {
                h.setAttribute('role', 'button');
                h.setAttribute('tabindex', '0');
                h.setAttribute('aria-expanded', g.classList.contains('is-open') ? 'true' : 'false');
            } else {
                h.removeAttribute('role');
                h.removeAttribute('tabindex');
                h.removeAttribute('aria-expanded');
            }
        });
    }

    function toggle(g) {
        var open = !g.classList.contains('is-open');
        g.classList.toggle('is-open', open);
        var h = head(g);
        if (h) h.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // Одна группа — сразу раскрыта; несколько — компактный список с числами, раскрываются по нажатию
    if (groups.length === 1) groups[0].classList.add('is-open');

    wrap.addEventListener('click', function (e) {
        if (!PHONE.matches) return;
        var h = e.target.closest('.attention-group-head > span');
        if (!h || !wrap.contains(h)) return;
        toggle(h.closest('.attention-group'));
    });
    wrap.addEventListener('keydown', function (e) {
        if (!PHONE.matches || (e.key !== 'Enter' && e.key !== ' ')) return;
        var h = e.target.closest('.attention-group-head > span');
        if (!h) return;
        e.preventDefault();
        toggle(h.closest('.attention-group'));
    });

    apply();
    if (PHONE.addEventListener) PHONE.addEventListener('change', apply);
    else if (PHONE.addListener) PHONE.addListener(apply);
})();
