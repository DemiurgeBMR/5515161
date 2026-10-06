/*
 * Телефонное поведение форм входа, регистрации, восстановления пароля, 2FA и настроек
 * профиля (экраны ≤ 768px). Подключается из pages/login.php, register.php,
 * forgot_password.php, reset_password.php, verify_2fa.php, edit_profile.php.
 *
 * Что делает (только на телефоне — на десктопе скрипт ничего не меняет):
 *   1. Ошибки проверки полей — под полем, а не «пузырём» браузера (его на телефонах часто
 *      закрывает клавиатура): событие invalid перехватывается, под полем появляется
 *      <div class="m-field-error">, поле подсвечивается и получает фокус.
 *   2. Активное поле всегда видно: при фокусе и при открытии экранной клавиатуры страница
 *      прокручивается так, чтобы поле не пряталось под липкой шапкой, нижней панелью или
 *      липкой кнопкой.
 *   3. Мастер регистрации: после «Продолжить»/«Назад» экран прокручивается к началу шага.
 *
 * Серверная валидация и логика форм не затрагиваются.
 */
(function () {
    'use strict';

    var mq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
    function isPhone() { return mq.matches; }

    // ---------- Тексты ошибок ----------
    function messageFor(el) {
        var v = el.validity;
        if (el.type === 'checkbox') return 'Нужно ваше согласие, чтобы продолжить';
        if (v.valueMissing) {
            if (el.type === 'password') return 'Введите пароль';
            if (el.type === 'email') return 'Введите email';
            return 'Заполните это поле';
        }
        if (v.typeMismatch) return el.type === 'email' ? 'Введите корректный email, например ivan@example.com' : 'Проверьте значение';
        if (v.patternMismatch) return el.getAttribute('data-m-error') || el.getAttribute('title') || 'Неверный формат';
        if (v.tooShort) return 'Минимум ' + el.minLength + ' символов';
        if (v.customError && el.validationMessage) return el.validationMessage;
        return el.validationMessage || 'Проверьте значение';
    }

    // Куда вставлять сообщение: после поля (или его обёртки с «глазком»), у флажка — после строки-метки.
    function anchorFor(el) {
        if (el.type === 'checkbox' || el.type === 'radio') return el.closest('label') || el;
        return el.closest('.rr-pw-wrap') || el;
    }
    function errorNodeFor(el, create) {
        var anchor = anchorFor(el);
        var next = anchor.nextElementSibling;
        // между полем и нашим сообщением может стоять подсказка «пароли совпадают»
        while (next && next.classList.contains('rr-match-hint')) next = next.nextElementSibling;
        if (next && next.classList.contains('m-field-error')) return next;
        if (!create) return null;
        var node = document.createElement('div');
        node.className = 'm-field-error';
        node.setAttribute('role', 'alert');
        anchor.insertAdjacentElement('afterend', node);
        return node;
    }
    function clearError(el) {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
        var node = errorNodeFor(el, false);
        if (node) node.parentNode.removeChild(node);
        var box = el.closest('.reg-consent');
        if (box) box.classList.remove('is-invalid');
    }
    function showError(el) {
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        var box = el.closest('.reg-consent');
        if (box) box.classList.add('is-invalid');
        // «Пароли не совпадают» — это уже показывает живая подсказка под полем (rr-ui.js)
        var hint = el.closest('.form-group') && el.closest('.form-group').querySelector('.rr-match-hint');
        if (el.validity.customError && hint && !hint.hidden) return;
        errorNodeFor(el, true).textContent = messageFor(el);
    }

    // ---------- Видимость активного поля ----------
    function bottomObstruction() {
        var h = 0;
        var fixedBars = document.querySelectorAll('.m-sticky-cta, .reg-panel-actions');
        for (var i = 0; i < fixedBars.length; i++) {
            var cs = getComputedStyle(fixedBars[i]);
            if (cs.position === 'fixed' && cs.display !== 'none' && fixedBars[i].offsetHeight) {
                h = Math.max(h, window.innerHeight - fixedBars[i].getBoundingClientRect().top);
            }
        }
        var tab = document.querySelector('.m-tabbar');
        if (!h && tab && getComputedStyle(tab).display !== 'none') h = tab.offsetHeight;
        return h;
    }
    function ensureVisible(el) {
        if (!isPhone() || !el || !el.getBoundingClientRect) return;
        var vv = window.visualViewport;
        var vh = vv ? vv.height : window.innerHeight;
        var vt = vv ? vv.offsetTop : 0;
        var header = document.querySelector('.header');
        var topLimit = (header ? Math.max(0, header.getBoundingClientRect().bottom) : 0) + 10;
        var bottomLimit = vt + vh - bottomObstruction() - 12;
        // видимой должна быть вся «строка формы» (подпись + поле + ошибка), если она помещается
        var row = el.closest('.form-group, .reg-consent, .ep-toggle') || el;
        var r = row.getBoundingClientRect();
        if (r.height > bottomLimit - topLimit) r = el.getBoundingClientRect();
        var delta = 0;
        if (r.top < topLimit) delta = r.top - topLimit;
        else if (r.bottom > bottomLimit) delta = r.bottom - bottomLimit;
        if (Math.abs(delta) > 1) window.scrollBy(0, delta);
    }
    function isTextField(el) {
        return el && el.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|file|range|color|image|hidden)$/i.test(el.type) ||
            (el && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT'));
    }

    document.addEventListener('focusin', function (e) {
        if (!isPhone() || !isTextField(e.target)) return;
        var el = e.target;
        // клавиатура выезжает не мгновенно — подправляем прокрутку дважды
        setTimeout(function () { if (document.activeElement === el) ensureVisible(el); }, 120);
        setTimeout(function () { if (document.activeElement === el) ensureVisible(el); }, 420);
    });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            var el = document.activeElement;
            if (isPhone() && isTextField(el)) ensureVisible(el);
        });
    }

    // ---------- Ошибки под полями ----------
    var pending = [];
    var flushScheduled = false;
    function flush() {
        flushScheduled = false;
        var list = pending; pending = [];
        if (!list.length) return;
        list.sort(function (a, b) {
            return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
        });
        var first = list[0];
        try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
        ensureVisible(first);
    }
    document.addEventListener('invalid', function (e) {
        if (!isPhone()) return;
        var el = e.target;
        if (!el || !el.form) return;
        e.preventDefault();               // без «пузыря» браузера — сообщение рисуем сами
        showError(el);
        pending.push(el);
        if (!flushScheduled) { flushScheduled = true; Promise.resolve().then(flush); }
    }, true);

    // Поправили значение — убираем сообщение (при следующей проверке появится снова, если надо)
    function onEdit(e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('is-invalid')) clearError(el);
    }
    document.addEventListener('input', onEdit);
    document.addEventListener('change', onEdit);

    // ---------- Мастер регистрации: к началу шага ----------
    // Слушаем в фазе перехвата: запоминаем активный шаг ДО обработчика страницы и смотрим,
    // сменился ли он (если проверка полей не пройдена — шаг прежний, прокручивать не нужно).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.reg-next, .reg-back');
        if (!btn || !isPhone()) return;
        var before = document.querySelector('.reg-panel.active');
        setTimeout(function () {
            var after = document.querySelector('.reg-panel.active');
            var steps = document.querySelector('.reg-steps');
            if (!steps || after === before) return;
            var header = document.querySelector('.header');
            var top = steps.getBoundingClientRect().top + window.pageYOffset - (header ? header.offsetHeight : 52) - 10;
            if (Math.abs(window.pageYOffset - top) > 4) window.scrollTo(0, Math.max(0, top));
        }, 30);
    }, true);
})();
