/*
 * Админка — поведение страниц. Без библиотек.
 * Всё работает и без JS (обычные формы), скрипт только добавляет удобство.
 */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    // Меню на телефоне
    var toggle = $('[data-adm-toggle]');
    var side = $('[data-adm-nav]');
    if (toggle && side) {
        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            side.classList.toggle('is-open', open);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && side.classList.contains('is-open')) toggle.click();
        });
        // Тап мимо меню закрывает его
        document.addEventListener('click', function (e) {
            if (side.classList.contains('is-open') && !side.contains(e.target) && !toggle.contains(e.target)) toggle.click();
        });
    }

    // Сообщение «Сохранено» показываем у того блока, к которому вернула страница
    // (адрес с #якорем), а не вверху — иначе на телефоне его не видно.
    var anchor = location.hash.length > 1 && document.getElementById(location.hash.slice(1));
    if (anchor && anchor.tagName === 'SECTION') {
        var title = $('.panel__title', anchor);
        $$('.adm-content > .notice').reverse().forEach(function (n) {
            if (title && title.parentNode === anchor) title.insertAdjacentElement('afterend', n);
            else anchor.insertBefore(n, anchor.firstChild);
        });
    }

    // Подтверждение опасных действий: <button data-confirm="Отменить бронь?">
    document.addEventListener('submit', function (e) {
        var btn = e.submitter || $('[type=submit]', e.target);
        var text = (btn && btn.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
        if (text && !window.confirm(text)) e.preventDefault();
    }, true);

    // Копировать ссылку: <button data-copy="#id-поля">
    $$('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = $(btn.getAttribute('data-copy'));
            if (!input) return;
            input.select();
            var done = function () {
                var old = btn.textContent;
                btn.textContent = 'Скопировано';
                setTimeout(function () { btn.textContent = old; }, 1500);
            };
            if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
            else { document.execCommand('copy'); done(); }
        });
    });

    // Форма отмены брони открывается по кнопке
    $$('[data-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = $(btn.getAttribute('data-open'));
            if (!el) return;
            el.classList.toggle('is-open');
            var input = $('input, textarea', el);
            if (input && el.classList.contains('is-open')) input.focus();
        });
    });

    // Карточка брони: своя цена, число ночей, комнаты выбранного типа
    var bookingForm = $('[data-booking-form]');
    if (bookingForm) {
        var manual = $('[name=price_manual]', bookingForm);
        var total = $('[name=total_price]', bookingForm);
        var syncManual = function () { if (manual && total) total.disabled = !manual.checked; };
        if (manual) manual.addEventListener('change', syncManual);
        syncManual();

        var ci = $('[name=check_in]', bookingForm);
        var co = $('[name=check_out]', bookingForm);
        var nightsEl = $('[data-nights]', bookingForm);
        var syncNights = function () {
            if (!nightsEl) return;
            var a = new Date(ci.value + 'T00:00:00'), b = new Date(co.value + 'T00:00:00');
            var n = Math.round((b - a) / 86400000);
            nightsEl.textContent = n > 0 ? n + ' ' + (n % 10 === 1 && n % 100 !== 11 ? 'ночь' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'ночи' : 'ночей')) : '';
            if (ci.value && (!co.value || co.value <= ci.value)) {
                var d = new Date(ci.value + 'T00:00:00');
                d.setDate(d.getDate() + 1);
                var m = d.getMonth() + 1, day = d.getDate();
                co.value = d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
                syncNights();
            }
        };
        if (ci && co) {
            ci.addEventListener('change', syncNights);
            co.addEventListener('change', syncNights);
            syncNights();
        }

        var typeSel = $('[name=room_type_id]', bookingForm);
        var roomSel = $('[name=room_id]', bookingForm);
        var syncRooms = function () {
            if (!typeSel || !roomSel) return;
            var t = typeSel.value;
            $$('option[data-type]', roomSel).forEach(function (o) {
                o.hidden = t !== '' && o.getAttribute('data-type') !== t;
            });
            var cur = roomSel.options[roomSel.selectedIndex];
            if (cur && cur.hidden) roomSel.value = '0';
        };
        if (typeSel && roomSel) {
            typeSel.addEventListener('change', syncRooms);
            roomSel.addEventListener('change', function () {
                var o = roomSel.options[roomSel.selectedIndex];
                if (o && o.getAttribute('data-type')) typeSel.value = o.getAttribute('data-type');
            });
            syncRooms();
        }
    }

    // Новые данные: раз в 30 секунд спрашиваем сервер, не изменилось ли что-то
    // (новая заявка, сообщение гостя). Страница не перезагружается сама, если
    // вы что-то печатаете, — появляется кнопка «Обновить».
    var body = document.body;
    var bar = $('[data-adm-refresh]');
    var reloadBtn = $('[data-adm-reload]');
    if (reloadBtn) reloadBtn.addEventListener('click', function () { location.reload(); });
    var typing = false;
    document.addEventListener('input', function () { typing = true; });

    function poll() {
        if (document.visibilityState !== 'visible') return;
        fetch('/admin/api/version.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                $$('[data-badge]').forEach(function (b) {
                    var key = b.getAttribute('data-badge');
                    if (data.badges && key in data.badges) {
                        b.textContent = data.badges[key];
                        b.hidden = !data.badges[key];
                    }
                });
                if (data.version && data.version !== body.getAttribute('data-version')) {
                    if (body.classList.contains('adm-autoreload') && !typing) location.reload();
                    else if (bar) bar.hidden = false;
                }
            })
            .catch(function () {});
    }
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', poll);
})();
