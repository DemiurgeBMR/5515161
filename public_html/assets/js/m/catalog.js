/**
 * Каталог на телефоне (≤ 768px): шторка фильтров поверх обычной GET-формы каталога.
 *  - ряды чипов вместо выпадающих списков (тип помещения, проходимость, часы доступа, сортировка) —
 *    чип лишь выставляет значение исходного <select>, отправляется по-прежнему он;
 *  - «Показать N предложений»: N пересчитывается при изменении полей (pages/catalog.php?m_count=1);
 *  - «Сбросить» очищает фильтры в шторке (поиск не трогает), закрытие шторки без «Показать»
 *    возвращает поля к применённым значениям;
 *  - отправка с телефона — короткий URL без пустых параметров (серверу всё равно, а ссылкой удобно делиться).
 * На десктопе скрипт ничего не меняет: чипы/шторки там скрыты, обработчики выходят по PHONE.
 */
(function () {
    'use strict';

    var form = document.getElementById('catFilterForm');
    var sheet = document.getElementById('catFilterSheet');
    if (!form || !sheet) return;

    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
    var submitBtn = document.getElementById('catFilterSubmit');
    var sheets = document.querySelectorAll('.cat-fsheet, .cat-citysheet, .cat-sortsheet');

    // ---------- Доступность шторок: роль диалога — только в режиме телефона ----------
    function syncA11y() {
        for (var i = 0; i < sheets.length; i++) {
            var s = sheets[i];
            if (PHONE.matches) {
                s.setAttribute('role', 'dialog');
                s.setAttribute('aria-modal', 'true');
                if (!s.classList.contains('is-open')) s.setAttribute('aria-hidden', 'true');
            } else {
                s.removeAttribute('role');
                s.removeAttribute('aria-modal');
                s.removeAttribute('aria-hidden');
            }
        }
    }
    syncA11y();
    if (PHONE.addEventListener) PHONE.addEventListener('change', syncA11y);

    // ---------- Чипы ↔ <select> ----------
    var groups = form.querySelectorAll('[data-chips-for]');
    function syncChips(group, sel) {
        var chips = group.querySelectorAll('.m-chip[data-value]');
        var hiddenOn = false;
        for (var i = 0; i < chips.length; i++) {
            var on = chips[i].getAttribute('data-value') === sel.value;
            chips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
            if (on && chips[i].classList.contains('cat-chip-more')) hiddenOn = true;
        }
        if (hiddenOn) setExpanded(group, true);
    }
    function setExpanded(group, on) {
        group.classList.toggle('is-expanded', on);
        var t = group.querySelector('[data-chips-toggle]');
        if (t) t.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    Array.prototype.forEach.call(groups, function (group) {
        var sel = form.elements[group.getAttribute('data-chips-for')];
        if (!sel || sel.tagName !== 'SELECT') return;
        var field = group.closest('.filter-field');
        if (field) field.classList.add('has-chips');
        group.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-chips-toggle]');
            if (toggle) { setExpanded(group, !group.classList.contains('is-expanded')); return; }
            var chip = e.target.closest('.m-chip[data-value]');
            if (!chip) return;
            sel.value = chip.getAttribute('data-value');
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
        sel.addEventListener('change', function () { syncChips(group, sel); });
        syncChips(group, sel);
    });

    // ---------- Применённое состояние полей шторки (для отката при закрытии без «Показать») ----------
    var fields = Array.prototype.filter.call(form.elements, function (el) { return el.name && sheet.contains(el); });
    function snapshot() { return fields.map(function (el) { return el.type === 'checkbox' ? el.checked : el.value; }); }
    function restore(snap) {
        fields.forEach(function (el, i) {
            if (el.type === 'checkbox') el.checked = snap[i]; else el.value = snap[i];
            if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
    var applied = snapshot();

    // ---------- Строка запроса без пустых значений ----------
    function formQuery() {
        var p = new URLSearchParams();
        new FormData(form).forEach(function (v, k) {
            if (typeof v !== 'string' || v === '' || (k === 'sort' && v === 'newest')) return;
            p.append(k, v);
        });
        return p.toString();
    }

    // ---------- «Показать N предложений» ----------
    var forms = (submitBtn && submitBtn.getAttribute('data-plural') || 'предложение|предложения|предложений').split('|');
    function plural(n) {
        var a = Math.abs(n) % 100, r = a % 10;
        if (a >= 11 && a <= 14) return forms[2];
        if (r === 1) return forms[0];
        if (r >= 2 && r <= 4) return forms[1];
        return forms[2];
    }
    function setLabel(n) { if (submitBtn) submitBtn.textContent = 'Показать ' + n + ' ' + plural(n); }
    var initialLabel = submitBtn ? submitBtn.textContent : '';
    var appliedQuery = formQuery();
    var lastQuery = appliedQuery;
    var timer = null, ctrl = null;

    function recount() {
        var q = formQuery();
        if (q === lastQuery) return;
        lastQuery = q;
        if (ctrl && ctrl.abort) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;
        if (submitBtn) submitBtn.classList.add('is-loading');
        fetch('/pages/catalog.php?' + (q ? q + '&' : '') + 'm_count=1', { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && typeof d.total === 'number' && q === lastQuery) setLabel(d.total); })
            .catch(function () {})
            .then(function () { if (q === lastQuery && submitBtn) submitBtn.classList.remove('is-loading'); });
    }
    function schedule(e) {
        if (!PHONE.matches || !sheet.contains(e.target)) return;
        clearTimeout(timer);
        timer = setTimeout(recount, e.type === 'input' ? 450 : 150);
    }
    form.addEventListener('change', schedule);
    form.addEventListener('input', schedule);

    // ---------- «Сбросить» в шапке шторки ----------
    var reset = sheet.querySelector('[data-cat-reset]');
    if (reset) {
        reset.addEventListener('click', function (e) {
            if (!PHONE.matches) return;
            e.preventDefault();
            fields.forEach(function (el) {
                if (el.type === 'checkbox') el.checked = false;
                else if (el.name === 'sort') el.value = 'newest';
                else el.value = '';
                if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            Array.prototype.forEach.call(groups, function (g) { setExpanded(g, false); });
            recount();
        });
    }

    // ---------- Закрыли шторку, не применив — вернуть применённые значения ----------
    var submitting = false;
    sheet.addEventListener('m-sheet:close', function () {
        if (submitting) return;
        restore(applied);
        clearTimeout(timer);
        lastQuery = appliedQuery;
        if (submitBtn) { submitBtn.textContent = initialLabel; submitBtn.classList.remove('is-loading'); }
    });

    // ---------- Отправка с телефона: короткий URL; открытая шторка сначала убирает свою запись истории ----------
    form.addEventListener('submit', function (e) {
        if (!PHONE.matches) return;
        e.preventDefault();
        submitting = true;
        var q = formQuery();
        var url = '/pages/catalog.php' + (q ? '?' + q : '');
        var go = function () { window.location.href = url; };
        if (sheet.classList.contains('is-open') && history.state && history.state.rrSheet === sheet.id) {
            var done = false;
            var once = function () { if (done) return; done = true; window.removeEventListener('popstate', once); go(); };
            window.addEventListener('popstate', once);
            history.back();
            setTimeout(once, 300);
        } else {
            go();
        }
    });
    // Возврат на страницу из bfcache — снова можно откатывать изменения
    window.addEventListener('pageshow', function (e) { if (e.persisted) submitting = false; });
})();
