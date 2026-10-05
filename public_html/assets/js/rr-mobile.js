/**
 * Мобильная оболочка сайта (телефоны ≤ 768px): шторки (bottom sheets), бейджи нижней панели,
 * переключатель темы в меню, определение экранной клавиатуры. Разметка — includes/mobile_nav.php,
 * стили — assets/css/layout/_mobile-shell.css. Подключается из includes/footer.php на всех страницах;
 * на десктопе делать нечего (триггеры скрыты), поэтому скрипт безопасен везде.
 *
 * Шторка — любой элемент .m-sheet с id. Открыть: кнопка/ссылка с [data-m-sheet-open="id"] или
 * RRMobile.openSheet('id'). Закрыть: [data-m-sheet-close], фон, Esc, жест «вниз» за ручку/шапку,
 * кнопка «Назад» (шторка кладёт запись в историю — «Назад» закрывает шторку, а не уходит со страницы).
 * События на самой шторке: 'm-sheet:open', 'm-sheet:close' (всплывают).
 *
 *   RRMobile.openSheet(id) · RRMobile.closeSheet(id) · RRMobile.closeAll(cb) · RRMobile.setBadge(key, n)
 *   CSS-переменные: --m-vvh (видимая высота с учётом клавиатуры), --m-vvt (смещение видимой области).
 *   html.m-kb — экранная клавиатура открыта (нижняя панель в этот момент скрыта).
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var backdrop = document.getElementById('mSheetBackdrop');
    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false, addEventListener: function () {} };
    var stack = [];          // открытые шторки (последняя — верхняя)
    var lastFocus = null;
    var hideTimer = null;

    function byId(id) { return document.getElementById(id); }
    function isOpen(sheet) { return sheet && sheet.classList.contains('is-open'); }

    // ---------- Открытие / закрытие ----------
    function openSheet(sheet) {
        if (typeof sheet === 'string') sheet = byId(sheet);
        if (!sheet || isOpen(sheet)) return;
        clearTimeout(hideTimer);
        lastFocus = document.activeElement;
        sheet.removeAttribute('aria-hidden');
        sheet.classList.add('is-open');
        stack.push(sheet);
        if (backdrop) {
            backdrop.hidden = false;
            // кадр — чтобы transition opacity сработал после снятия hidden
            window.requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
        }
        root.classList.add('m-lock');
        try { history.pushState({ rrSheet: sheet.id }, ''); } catch (e) { /* без истории — «Назад» просто уйдёт со страницы */ }
        if (!sheet.hasAttribute('tabindex')) sheet.setAttribute('tabindex', '-1');
        try { sheet.focus({ preventScroll: true }); } catch (e) {}
        sheet.dispatchEvent(new CustomEvent('m-sheet:open', { bubbles: true }));
    }

    // Фактическое закрытие (без операций с историей)
    function finishClose(sheet) {
        if (!isOpen(sheet)) return;
        sheet.classList.remove('is-open');
        sheet.setAttribute('aria-hidden', 'true');
        sheet.style.transform = '';
        sheet.style.transition = '';
        stack = stack.filter(function (s) { return s !== sheet; });
        if (!stack.length) {
            root.classList.remove('m-lock');
            if (backdrop) {
                backdrop.classList.remove('is-open');
                hideTimer = setTimeout(function () { if (!stack.length) backdrop.hidden = true; }, 220);
            }
            if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
        }
        sheet.dispatchEvent(new CustomEvent('m-sheet:close', { bubbles: true }));
    }

    function closeSheet(sheet) {
        if (typeof sheet === 'string') sheet = byId(sheet);
        if (!sheet || !isOpen(sheet)) return;
        var st = history.state;
        if (st && st.rrSheet === sheet.id) {
            history.back();                       // popstate закроет шторку (см. ниже)
            // запасной вариант, если popstate по какой-то причине не пришёл
            setTimeout(function () { if (isOpen(sheet)) finishClose(sheet); }, 400);
        } else {
            finishClose(sheet);
        }
    }

    function closeTop() { if (stack.length) closeSheet(stack[stack.length - 1]); }

    // Закрыть все шторки и вызвать cb, когда запись истории убрана (после «Назад»). Нужно перед
    // переходом на другую страницу, чтобы страница не оказалась в истории дважды.
    function closeAll(cb) {
        var open = stack.slice();
        if (!open.length) { if (cb) cb(); return; }
        var st = history.state;
        if (st && st.rrSheet) {
            var done = false;
            var once = function () {
                if (done) return;
                done = true;
                window.removeEventListener('popstate', once);
                open.forEach(finishClose);
                if (cb) cb();
            };
            window.addEventListener('popstate', once);
            history.back();
            setTimeout(once, 300);
        } else {
            open.forEach(finishClose);
            if (cb) cb();
        }
    }

    // «Назад» / «Вперёд»: если верхняя шторка открыта, а запись истории уже не её — закрываем
    window.addEventListener('popstate', function () {
        if (!stack.length) return;
        var top = stack[stack.length - 1];
        if (!(history.state && history.state.rrSheet === top.id)) finishClose(top);
    });
    // Возврат на страницу из bfcache: шторки закрыты, прокрутка не заблокирована
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        stack.slice().forEach(finishClose);
        root.classList.remove('m-lock');
        if (backdrop) { backdrop.classList.remove('is-open'); backdrop.hidden = true; }
    });

    // ---------- Делегирование кликов ----------
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-m-sheet-open]');
        if (opener) {
            e.preventDefault();
            openSheet(opener.getAttribute('data-m-sheet-open'));
            return;
        }
        if (e.target.closest('[data-m-sheet-close]')) {
            e.preventDefault();
            var host = e.target.closest('.m-sheet');
            closeSheet(host || stack[stack.length - 1]);
            return;
        }
        if (backdrop && e.target === backdrop) { closeTop(); return; }

        // Переход по ссылке из шторки: сначала закрываем её (убираем запись истории), потом идём —
        // иначе страница осталась бы в истории дважды и «Назад» требовал бы двух нажатий.
        var link = e.target.closest('.m-sheet a[href]');
        if (link && !e.defaultPrevented && !link.hasAttribute('data-rr-tour-start') && !link.hasAttribute('data-m-sheet-keep') &&
            !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && e.button === 0 &&
            (!link.target || link.target === '_self') && link.origin === location.origin &&
            link.getAttribute('href').charAt(0) !== '#') {
            var sheet = link.closest('.m-sheet');
            var st = history.state;
            if (sheet && isOpen(sheet) && st && st.rrSheet === sheet.id) {
                e.preventDefault();
                var go = function () { window.location.href = link.href; };
                var done = false;
                var once = function () { if (done) return; done = true; window.removeEventListener('popstate', once); go(); };
                window.addEventListener('popstate', once);
                history.back();
                setTimeout(once, 250);
            }
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && stack.length) { e.preventDefault(); closeTop(); }
    });

    // ---------- Жест «смахнуть вниз» за ручку или шапку шторки ----------
    var drag = null;
    document.addEventListener('touchstart', function (e) {
        var grip = e.target.closest('.m-sheet-handle, .m-sheet-head');
        var sheet = grip && grip.closest('.m-sheet');
        if (!sheet || !isOpen(sheet) || e.target.closest('button, a, input, select, textarea')) return;
        drag = { sheet: sheet, y0: e.touches[0].clientY, dy: 0 };
        sheet.style.transition = 'none';
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
        if (!drag) return;
        drag.dy = Math.max(0, e.touches[0].clientY - drag.y0);
        drag.sheet.style.transform = 'translateY(' + drag.dy + 'px)';
    }, { passive: true });
    function endDrag() {
        if (!drag) return;
        var d = drag; drag = null;
        d.sheet.style.transition = '';
        d.sheet.style.transform = '';
        if (d.dy > 90) closeSheet(d.sheet);
    }
    document.addEventListener('touchend', endDrag, { passive: true });
    document.addEventListener('touchcancel', endDrag, { passive: true });

    // ---------- Переключатель темы в шторке: единственная логика темы — кнопка в шапке ----------
    var themeBtn = byId('mThemeToggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var headerBtn = byId('themeToggleBtn');
            if (headerBtn) headerBtn.click();
        });
    }

    // ---------- Бейджи (нижняя панель, шторка) ----------
    function setBadge(key, n) {
        n = parseInt(n, 10) || 0;
        var nodes = document.querySelectorAll('[data-m-badge="' + key + '"]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = n > 99 ? '99+' : String(n);
            nodes[i].hidden = n <= 0;
        }
    }

    // ---------- Экранная клавиатура и видимая область ----------
    function isEditable(el) {
        if (!el) return false;
        var t = el.tagName;
        return t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable ||
            (t === 'INPUT' && !/^(checkbox|radio|button|submit|reset|file|range|color|image)$/i.test(el.type));
    }
    var vv = window.visualViewport;
    function onViewport() {
        if (!PHONE.matches) { root.classList.remove('m-kb'); return; }
        var h = vv ? vv.height : window.innerHeight;
        root.style.setProperty('--m-vvh', Math.round(h) + 'px');
        root.style.setProperty('--m-vvt', Math.round(vv ? vv.offsetTop : 0) + 'px');
        var shrunk = vv ? (window.innerHeight - vv.height) > 140 : false;
        root.classList.toggle('m-kb', shrunk && isEditable(document.activeElement));
    }
    if (vv) {
        vv.addEventListener('resize', onViewport);
        vv.addEventListener('scroll', onViewport);
    }
    window.addEventListener('resize', onViewport);
    document.addEventListener('focusin', function () { setTimeout(onViewport, 50); });
    document.addEventListener('focusout', function () { setTimeout(onViewport, 50); });
    onViewport();

    window.RRMobile = { openSheet: openSheet, closeSheet: closeSheet, closeAll: closeAll, setBadge: setBadge };
})();
