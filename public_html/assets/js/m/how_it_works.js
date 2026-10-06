/**
 * «Как это работает» — поведение для телефонов (≤ 768px).
 *  1. Переключатель «Собственникам / Операторам» (две колонки шагов → одна выбранная).
 *  2. FAQ — аккордеон (вопрос раскрывает ответ).
 * Разметка — pages/how_it_works.php, стили — assets/css/pages/m/_m-how-it-works.css.
 * На десктопе скрипт ничего не меняет: переключатель скрыт (m-only), а правила аккордеона
 * и скрытия колонки действуют только в @media (max-width: 768px); атрибуты доступности
 * вопросов ставятся только пока экран «телефонный».
 */
(function () {
    'use strict';

    var mq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };

    // ---------- 1. Переключатель ролей ----------
    var columns = document.querySelector('.hiw-columns');
    var seg = document.querySelector('.hiw-seg');
    if (columns && seg) {
        var buttons = seg.querySelectorAll('[data-hiw-role]');

        var selectRole = function (role) {
            columns.setAttribute('data-m-role', role);
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].setAttribute('aria-selected', buttons[i].getAttribute('data-hiw-role') === role ? 'true' : 'false');
            }
        };

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                selectRole(this.getAttribute('data-hiw-role'));
            });
        }

        var wanted = (location.hash || '').replace('#', '');
        selectRole(wanted === 'owner' || wanted === 'operator' ? wanted : (columns.getAttribute('data-m-default') || 'owner'));
        seg.removeAttribute('hidden');
    }

    // ---------- 2. FAQ-аккордеон ----------
    var faq = document.querySelector('.hiw-faq[data-m-acc]');
    if (faq) {
        var items = faq.querySelectorAll('.hiw-faq-item');

        var setOpen = function (item, open) {
            item.classList.toggle('is-open', open);
            var q = item.querySelector('.hiw-faq-q');
            if (q && mq.matches) q.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        var syncA11y = function () {
            for (var i = 0; i < items.length; i++) {
                var q = items[i].querySelector('.hiw-faq-q');
                if (!q) continue;
                if (mq.matches) {
                    q.setAttribute('role', 'button');
                    q.setAttribute('tabindex', '0');
                    q.setAttribute('aria-expanded', items[i].classList.contains('is-open') ? 'true' : 'false');
                } else {
                    q.removeAttribute('role');
                    q.removeAttribute('tabindex');
                    q.removeAttribute('aria-expanded');
                }
            }
        };

        var toggle = function (item) {
            setOpen(item, !item.classList.contains('is-open'));
        };

        faq.addEventListener('click', function (e) {
            if (!mq.matches) return;
            var q = e.target.closest ? e.target.closest('.hiw-faq-q') : null;
            if (q && faq.contains(q)) toggle(q.parentNode);
        });
        faq.addEventListener('keydown', function (e) {
            if (!mq.matches || (e.key !== 'Enter' && e.key !== ' ')) return;
            var q = e.target.closest ? e.target.closest('.hiw-faq-q') : null;
            if (q && faq.contains(q)) {
                e.preventDefault();
                toggle(q.parentNode);
            }
        });

        faq.classList.add('is-acc');
        syncA11y();
        if (mq.addEventListener) mq.addEventListener('change', syncA11y);
        else if (mq.addListener) mq.addListener(syncA11y);
    }
})();
