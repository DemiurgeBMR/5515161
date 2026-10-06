/**
 * Календарь выездов — режим телефона (≤ 768px). Подключается из pages/events_calendar.php ДО основного
 * скрипта страницы; страница вызывает хуки RRCalMobile.* (все — no-op на десктопе).
 *
 *  - вид по умолчанию на телефоне — список месяца (listMonth) карточками; переключатель «Список / Месяц»
 *    (месяц — компактная сетка с точками, под ней список выбранного дня); выбор запоминается в localStorage;
 *  - смена ширины/ориентации (matchMedia + windowResize FullCalendar) переключает телефон ⇄ десктоп
 *    без перезагрузки: на десктопе возвращаются прежние вид, тулбар и dayMaxEvents;
 *  - шторка фильтров: чипы строятся из десктопных <select> (они остаются источником правды) — выбор чипа
 *    ставит значение селекта и шлёт 'change', дальше работает прежняя логика страницы;
 *  - модалки календаря (.cal-modal-overlay) на телефоне выглядят как шторки (стили — _m-events-calendar.css),
 *    здесь: кнопка «Назад» и жест «вниз» закрывают их, страница под ними не прокручивается;
 *  - дата/время в формах — нативные type=date/time, синхронизируются со скрытым datetime-local.
 */
(function () {
    'use strict';

    var MQ = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
    function isPhone() { return !!(MQ && MQ.matches); }

    var cfg = { role: '', userId: 0, icons: {} };
    var cal = null;
    var api = null;
    var applied = null;              // 'phone' | 'desktop' — что сейчас настроено в FullCalendar
    var desktopToolbar = null;
    var desktopView = 'dayGridMonth';
    var DESKTOP_DAY_MAX = 4;
    var PHONE_TB = { left: 'prev', center: 'title', right: 'next' };
    var KEY = 'rr_cal_m_view';
    var mode = readMode();           // 'list' | 'month'
    var picked = null;               // выбранный день в режиме «Месяц», 'YYYY-MM-DD'
    var pickAuto = true;             // выбран автоматически (сегодня / первый день с выездами)

    var TYPE = { installation: 'Установка', maintenance: 'Обслуживание', restock: 'Пополнение', repair: 'Ремонт', removal: 'Демонтаж' };
    var WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
    var MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    function readMode() { try { return localStorage.getItem(KEY) === 'month' ? 'month' : 'list'; } catch (e) { return 'list'; } }
    function saveMode() { try { localStorage.setItem(KEY, mode); } catch (e) { /* приватный режим — не страшно */ } }
    function phoneView() { return mode === 'month' ? 'dayGridMonth' : 'listMonth'; }
    function $(id) { return document.getElementById(id); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function hhmm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function parseYmd(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function esc(v) { var div = document.createElement('div'); div.textContent = v == null ? '' : String(v); return div.innerHTML; }
    function icon(name) { return cfg.icons[name] || ''; }
    function plural(n, one, few, many) {
        var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return one;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
        return many;
    }
    function dayTitle(d) {
        var s = d.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
        return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function phoneActive() { return applied === 'phone'; }

    // ---------- Карточка выезда (список месяца и список дня) ----------
    function statusPill(p, attn) {
        if (p.kind === 'quicklog') return '<span class="m-pill is-muted">' + icon('edit') + 'Отметка постфактум</span>';
        switch (p.status) {
            case 'requested':
            case 'reviewing':
                return attn
                    ? '<span class="m-pill is-warning cal-pill-strong">' + icon('clock') + 'Ждёт вашего подтверждения</span>'
                    : '<span class="m-pill is-warning">' + icon('clock') + (p.status === 'reviewing' ? 'На рассмотрении' : 'Ожидает подтверждения') + '</span>';
            case 'confirmed': return '<span class="m-pill is-info">' + icon('check') + 'Подтверждён</span>';
            case 'completed': return '<span class="m-pill">' + icon('check') + 'Завершён</span>';
            case 'cancelled': return '<span class="m-pill is-muted">Отменён</span>';
            default: return '<span class="m-pill is-muted">' + esc(p.status || '') + '</span>';
        }
    }

    function cardHtml(ev, extraClass) {
        var p = ev.extendedProps || {};
        var d = ev.start || new Date();
        var quick = p.kind === 'quicklog';
        var st = quick ? 'quicklog' : (p.status || '');
        var pending = !quick && (st === 'requested' || st === 'reviewing');
        var attn = pending && String(p.requested_by) !== String(cfg.userId);
        var type = TYPE[p.event_type] ? p.event_type : 'other';
        var isToday = ymd(d) === ymd(new Date());
        var isPast = !isToday && d < new Date();
        var cls = 'cal-mcard s-' + st + (p.is_emergency ? ' is-emergency' : '') + (attn ? ' is-attn' : '') +
            (isPast ? ' is-past' : '') + (extraClass ? ' ' + extraClass : '');
        var who = cfg.role === 'owner' ? p.operator_name : p.owner_name;
        var label = (TYPE[p.event_type] || p.event_type || 'Выезд') + ', ' + (p.location_title || '') + ', ' + d.getDate() + ' ' + MONTHS_GEN[d.getMonth()] + ', ' + hhmm(d);
        return '<div class="' + cls + '" role="button" tabindex="0" data-ev="' + esc(ev.id) + '" aria-label="' + esc(label) + '">' +
            '<div class="cal-mcard-date' + (isToday ? ' is-today' : '') + '" aria-hidden="true"><b>' + d.getDate() + '</b><span>' + (isToday ? 'сег.' : WD[d.getDay()]) + '</span></div>' +
            '<div class="cal-mcard-body">' +
                '<div class="cal-mcard-tags">' +
                    '<span class="cal-mtype t-' + type + '">' + icon(type) + esc(TYPE[p.event_type] || p.event_type || 'Выезд') + '</span>' +
                    (p.is_emergency ? '<span class="m-pill is-danger cal-pill-strong">' + icon('warning') + 'Срочно</span>' : '') +
                '</div>' +
                '<div class="cal-mcard-title">' + esc(p.location_title || ev.title) + '</div>' +
                '<div class="cal-mcard-meta">' +
                    '<span>' + icon('clock') + hhmm(d) + '</span>' +
                    (p.city ? '<span>' + icon('pin') + esc(p.city) + '</span>' : '') +
                    (who ? '<span>' + icon('user') + esc(who) + '</span>' : '') +
                '</div>' +
                '<div class="cal-mcard-foot">' + statusPill(p, attn) + '</div>' +
            '</div>' +
        '</div>';
    }

    // ---------- Фильтры ----------
    function filterSelects() {
        return ['typeFilter', 'statusFilter', 'emergencyFilter', 'operatorFilter'].map($).filter(Boolean);
    }
    var FILTER_LABELS = { typeFilter: 'Тип выезда', statusFilter: 'Статус', emergencyFilter: 'Срочность', operatorFilter: 'Оператор' };

    function activeFilterCount() {
        return filterSelects().filter(function (s) { return s.value !== 'all'; }).length;
    }
    function anyFilterActive() {
        var q = $('eventSearch');
        return activeFilterCount() > 0 || (q && q.value.trim() !== '') || !!document.querySelector('.stat-card.active');
    }

    function renderFilterChips() {
        var host = $('calFilterChips');
        if (!host) return;
        host.innerHTML = filterSelects().map(function (sel) {
            var chips = Array.prototype.map.call(sel.options, function (o) {
                var on = o.value === sel.value;
                return '<button type="button" class="m-chip" data-sel="' + sel.id + '" data-val="' + esc(o.value) + '" aria-pressed="' + on + '">' + esc(o.textContent.trim()) + '</button>';
            }).join('');
            return '<div class="cal-m-fgroup" role="group" aria-label="' + esc(FILTER_LABELS[sel.id] || '') + '">' +
                '<div class="m-label">' + esc(FILTER_LABELS[sel.id] || '') + '</div>' +
                '<div class="m-chips is-wrap">' + chips + '</div></div>';
        }).join('');
    }

    function updateFilterUi() {
        var n = activeFilterCount();
        var btn = $('calFilterBtn');
        if (btn) {
            var dot = btn.querySelector('.cal-m-dot');
            if (dot) dot.hidden = n === 0;
            btn.setAttribute('aria-label', n ? 'Фильтры, выбрано: ' + n : 'Фильтры');
            btn.classList.toggle('is-on', n > 0);
        }
        var apply = $('calFilterApply');
        if (apply && cal) {
            var total = cal.getEvents().length;
            apply.textContent = total ? 'Показать ' + total + ' ' + plural(total, 'выезд', 'выезда', 'выездов') : 'Ничего не найдено';
        }
        var reset = $('calFilterReset');
        if (reset) reset.disabled = n === 0;
    }

    function resetFilters(includeSearch) {
        var sels = filterSelects();
        sels.forEach(function (s) { s.value = 'all'; });
        if (includeSearch) {
            var q = $('eventSearch');
            if (q && q.value) { q.value = ''; }
        }
        var active = document.querySelector('.stat-card.active');
        if (active) active.click();                       // снимает быстрый фильтр плитки
        if (sels[0]) sels[0].dispatchEvent(new Event('change', { bubbles: true }));
        renderFilterChips();
        updateFilterUi();
    }

    // ---------- Пустой список ----------
    function emptyHtml() {
        if (anyFilterActive()) {
            return '<div class="cal-m-empty m-empty">' + icon('calendar') +
                '<b>Ничего не найдено</b><p>По выбранным фильтрам и поиску в этом месяце выездов нет.</p>' +
                '<button type="button" class="m-btn m-btn--soft" data-cal-reset>Сбросить фильтры</button></div>';
        }
        var next = null;
        if (cal) {
            var end = cal.view.currentEnd;
            cal.getEvents().forEach(function (e) { if (e.start && e.start >= end && (!next || e.start < next.start)) next = e; });
        }
        return '<div class="cal-m-empty m-empty">' + icon('calendar') +
            '<b>В этом месяце выездов нет</b>' +
            '<p>Запланируйте обслуживание, пополнение или ремонт — вторая сторона получит уведомление.</p>' +
            '<button type="button" class="m-btn" data-cal-proxy="newEventBtn">' + icon('plus') + 'Запланировать выезд</button>' +
            (next ? '<button type="button" class="m-btn m-btn--ghost" data-cal-goto="' + ymd(next.start) + '">Ближайший: ' + next.start.getDate() + ' ' + MONTHS_GEN[next.start.getMonth()] + '</button>' : '') +
            '</div>';
    }

    // ---------- Режим «Месяц»: выбранный день и его список ----------
    var pickStyle = null;
    function paintPicked() {
        if (!pickStyle) {
            pickStyle = document.createElement('style');
            pickStyle.id = 'calPickStyle';
            document.head.appendChild(pickStyle);
        }
        var on = phoneActive() && mode === 'month' && picked;
        // Подсветка через стиль, а не класс ячейки: FullCalendar перерисовывает ячейки и снимает чужие классы
        pickStyle.textContent = on
            ? '@media (max-width: 768px){#calendar td.fc-daygrid-day[data-date="' + picked + '"] .fc-daygrid-day-frame{background:var(--accent-soft);box-shadow:inset 0 0 0 1.5px var(--accent)}' +
              '#calendar td.fc-daygrid-day[data-date="' + picked + '"]:not(.fc-day-today) .fc-daygrid-day-number{color:var(--accent)}}'
            : '';
    }

    function eventsOn(dateStr) {
        if (!cal) return [];
        return cal.getEvents().filter(function (e) { return e.start && ymd(e.start) === dateStr; })
            .sort(function (a, b) { return a.start - b.start; });
    }

    function defaultPick() {
        var v = cal.view, s = v.currentStart, e = v.currentEnd, now = new Date();
        if (now >= s && now < e) return ymd(now);
        var first = null;
        cal.getEvents().forEach(function (ev) { if (ev.start >= s && ev.start < e && (!first || ev.start < first)) first = ev.start; });
        return ymd(first || s);
    }

    function renderDay() {
        var box = $('calDayList');
        if (!box) return;
        paintPicked();
        if (!(phoneActive() && mode === 'month' && picked)) { box.hidden = true; box.innerHTML = ''; return; }
        var d = parseYmd(picked);
        var list = eventsOn(picked);
        var head = '<div class="cal-m-day-head"><h2 class="cal-m-day-title">' + esc(dayTitle(d)) + '</h2>' +
            '<span class="cal-m-day-count">' + (list.length ? list.length + ' ' + plural(list.length, 'выезд', 'выезда', 'выездов') : 'нет выездов') + '</span></div>';
        var body = list.length
            ? '<div class="cal-m-day-list">' + list.map(function (e) { return cardHtml(e); }).join('') + '</div>'
            : '<div class="cal-m-day-empty">' +
                (anyFilterActive() ? 'По фильтрам на этот день ничего нет.' : 'На этот день ничего не запланировано.') +
              '</div>';
        var add = '<button type="button" class="m-btn m-btn--ghost m-btn--block cal-m-day-add" data-cal-create="' + picked + '">' + icon('plus') +
            'Запланировать на ' + d.getDate() + ' ' + MONTHS_GEN[d.getMonth()] + '</button>';
        box.innerHTML = head + body + add;
        box.hidden = false;
    }

    function pick(dateStr, reveal) {
        picked = dateStr;
        pickAuto = false;
        renderDay();
        if (reveal) {
            var box = $('calDayList');
            if (box && !box.hidden) {
                var r = box.getBoundingClientRect(), vh = window.innerHeight;
                if (r.top > vh - 140) window.scrollBy({ top: r.top - vh + 240, behavior: 'smooth' });
            }
        }
    }

    // ---------- Переключение телефон ⇄ десктоп ----------
    function updateSeg() {
        document.querySelectorAll('[data-cal-view]').forEach(function (b) {
            b.setAttribute('aria-selected', String(b.getAttribute('data-cal-view') === mode));
            b.classList.toggle('is-on', b.getAttribute('data-cal-view') === mode);
        });
    }

    function syncRequired() {
        // На телефоне видимы поля type=date/time, а datetime-local скрыт: его required блокировал бы отправку
        // без подсказки. Пустую дату ловит проверка в скрипте страницы («Выберите дату и время»).
        ['eventDatetime', 'rescheduleDatetime'].forEach(function (id) {
            var el = $(id);
            if (el) el.required = !isPhone();
        });
    }

    function sync() {
        syncRequired();
        if (!cal) return;
        var want = isPhone() ? 'phone' : 'desktop';
        if (want === applied) return;
        applied = want;
        cal.batchRendering(function () {
            if (want === 'phone') {
                cal.setOption('headerToolbar', PHONE_TB);
                cal.setOption('dayMaxEvents', false);
                cal.changeView(phoneView());
            } else {
                cal.setOption('headerToolbar', desktopToolbar);
                cal.setOption('dayMaxEvents', DESKTOP_DAY_MAX);
                cal.changeView(desktopView);
            }
        });
        updateSeg();
        renderDay();
        if (want === 'desktop') closeAllOverlaysHistory();
    }

    // ---------- Модалки календаря как шторки: «Назад», жест, блокировка прокрутки ----------
    var OVERLAYS = {
        eventModal: 'closeModal', detailsModal: 'closeDetailsModal', rescheduleModal: 'closeRescheduleModal',
        completeModal: 'closeCompleteModal', photoLightbox: 'closePhotoLightbox'
    };
    var openStack = [];
    var histArmed = false;
    var ignorePop = 0;

    function closeOverlay(el) {
        var fn = window[OVERLAYS[el.id]];
        if (typeof fn === 'function') fn(); else el.classList.remove('active');
    }

    function pullDateTime(overlay) {
        overlay.querySelectorAll('[data-dt-for]').forEach(function (g) {
            var target = $(g.getAttribute('data-dt-for'));
            var ins = g.querySelectorAll('input');
            if (!target || ins.length < 2) return;
            var v = target.value || '';
            ins[0].value = v ? v.slice(0, 10) : '';
            ins[1].value = v ? v.slice(11, 16) : '';
        });
    }

    function onOverlayChange(el) {
        var active = el.classList.contains('active');
        var idx = openStack.indexOf(el);
        if (active && idx === -1) {
            openStack.push(el);
            pullDateTime(el);
            if (isPhone()) {
                document.documentElement.classList.add('cal-m-lock');
                if (!histArmed) {
                    try { history.pushState({ rrCalSheet: 1 }, ''); histArmed = true; } catch (e) { /* без истории */ }
                }
                var box = el.querySelector('.cal-modal-box');
                if (box) { box.scrollTop = 0; box.style.transform = ''; }
            }
        } else if (!active && idx !== -1) {
            openStack.splice(idx, 1);
            if (!openStack.length) {
                document.documentElement.classList.remove('cal-m-lock');
                if (histArmed) {
                    histArmed = false;
                    if (history.state && history.state.rrCalSheet) { ignorePop++; history.back(); }
                }
            }
        }
    }

    function closeAllOverlaysHistory() {
        document.documentElement.classList.remove('cal-m-lock');
    }

    window.addEventListener('popstate', function () {
        if (ignorePop > 0) { ignorePop--; return; }
        if (!histArmed || !openStack.length) return;
        histArmed = false;                               // запись истории уже снята «Назад»
        closeOverlay(openStack[openStack.length - 1]);
        if (openStack.length) {                          // под ней ещё шторка — снова вооружаем «Назад»
            try { history.pushState({ rrCalSheet: 1 }, ''); histArmed = true; } catch (e) {}
        }
    });

    function bindOverlays() {
        Object.keys(OVERLAYS).forEach(function (id) {
            var el = $(id);
            if (!el) return;
            new MutationObserver(function () { onOverlayChange(el); }).observe(el, { attributes: true, attributeFilter: ['class'] });
        });

        // Жест «смахнуть вниз» за шапку шторки
        var drag = null;
        document.addEventListener('touchstart', function (e) {
            if (!isPhone()) return;
            var head = e.target.closest('.cal-modal-header');
            if (!head || e.target.closest('button, a, input, select, textarea')) return;
            var box = head.closest('.cal-modal-box');
            var overlay = head.closest('.cal-modal-overlay');
            if (!box || !overlay || box.scrollTop > 0) return;
            drag = { box: box, overlay: overlay, y0: e.touches[0].clientY, dy: 0 };
            box.style.transition = 'none';
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (!drag) return;
            drag.dy = Math.max(0, e.touches[0].clientY - drag.y0);
            drag.box.style.transform = 'translateY(' + drag.dy + 'px)';
        }, { passive: true });
        function end() {
            if (!drag) return;
            var d = drag; drag = null;
            d.box.style.transition = '';
            d.box.style.transform = '';
            if (d.dy > 90) closeOverlay(d.overlay);
        }
        document.addEventListener('touchend', end, { passive: true });
        document.addEventListener('touchcancel', end, { passive: true });

        // Дата/время: нативные поля телефона → скрытое datetime-local, которое читает скрипт страницы
        document.querySelectorAll('[data-dt-for]').forEach(function (g) {
            var target = $(g.getAttribute('data-dt-for'));
            var ins = g.querySelectorAll('input');
            if (!target || ins.length < 2) return;
            function push() { target.value = (ins[0].value && ins[1].value) ? ins[0].value + 'T' + ins[1].value : ''; }
            for (var i = 0; i < 2; i++) { ins[i].addEventListener('input', push); ins[i].addEventListener('change', push); }
        });
    }

    // ---------- Делегирование кликов ----------
    function runAfterSheetClose(btn, fn) {
        var sheet = btn.closest('.m-sheet');
        if (sheet && sheet.classList.contains('is-open') && window.RRMobile) {
            // сначала закрываем шторку (она снимает свою запись истории), потом действие — иначе «Назад» спутается
            var done = false;
            var go = function () { if (done) return; done = true; sheet.removeEventListener('m-sheet:close', go); fn(); };
            sheet.addEventListener('m-sheet:close', go);
            window.RRMobile.closeSheet(sheet);
            setTimeout(go, 500);
        } else {
            fn();
        }
    }

    function bindClicks() {
        document.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-cal-view]'))) {
                mode = t.getAttribute('data-cal-view') === 'month' ? 'month' : 'list';
                saveMode();
                updateSeg();
                if (phoneActive() && cal) cal.changeView(phoneView());
                renderDay();
                return;
            }
            if ((t = e.target.closest('[data-cal-today]'))) {
                if (!cal) return;
                cal.today();
                if (mode === 'month') pick(ymd(new Date()), false);
                return;
            }
            if ((t = e.target.closest('[data-cal-proxy]'))) {
                var target = $(t.getAttribute('data-cal-proxy'));
                if (target) runAfterSheetClose(t, function () { target.click(); });
                return;
            }
            if ((t = e.target.closest('[data-cal-reset]'))) { resetFilters(true); return; }
            if ((t = e.target.closest('[data-cal-goto]'))) {
                var ds = t.getAttribute('data-cal-goto');
                if (cal) cal.gotoDate(ds);
                if (mode === 'month') pick(ds, false);
                return;
            }
            if ((t = e.target.closest('[data-cal-create]'))) {
                if (api && api.openCreate) api.openCreate(t.getAttribute('data-cal-create'), '10:00');
                return;
            }
            if ((t = e.target.closest('#calFilterChips [data-sel]'))) {
                var sel = $(t.getAttribute('data-sel'));
                if (!sel) return;
                sel.value = t.getAttribute('data-val');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                renderFilterChips();
                updateFilterUi();
                return;
            }
            if ((t = e.target.closest('#calFilterReset'))) { resetFilters(false); return; }
            // Карточка в списке выбранного дня (в списке месяца клик ловит сам FullCalendar)
            if ((t = e.target.closest('#calDayList [data-ev]'))) {
                var obj = cal && cal.getEventById(t.getAttribute('data-ev'));
                if (obj && api) api.openDetails(obj);
            }
        });
        // Клавиатура: Enter/пробел на карточке = нажатие
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('cal-mcard')) {
                e.preventDefault();
                e.target.click();
            }
        });
        document.addEventListener('m-sheet:open', function (e) {
            if (e.target && e.target.id === 'calFilterSheet') { renderFilterChips(); updateFilterUi(); }
        });
        var q = $('eventSearch');
        if (q) q.addEventListener('keydown', function (e) { if (e.key === 'Enter' && isPhone()) q.blur(); });
    }

    // ---------- Публичные хуки для страницы ----------
    window.RRCalMobile = {
        isPhone: isPhone,
        setup: function (c) { for (var k in c) if (Object.prototype.hasOwnProperty.call(c, k)) cfg[k] = c[k]; },
        initialView: function (def) {
            desktopView = def;
            applied = isPhone() ? 'phone' : 'desktop';
            return applied === 'phone' ? phoneView() : def;
        },
        initialToolbar: function (tb) {
            desktopToolbar = tb;
            return isPhone() ? PHONE_TB : tb;
        },
        eventContent: function (arg) {
            if (!phoneActive()) return undefined;                       // десктоп — стандартная отрисовка
            var type = arg.view.type;
            if (type.indexOf('list') === 0) return { html: cardHtml(arg.event) };
            if (type === 'dayGridMonth') return { html: '' };           // точка-индикатор (стили — CSS)
            return undefined;
        },
        noEventsContent: function () {
            return phoneActive() ? { html: emptyHtml() } : undefined;
        },
        onDatesSet: function (info) {
            if (!phoneActive()) { desktopView = info.view.type; return; }
            if (mode === 'month') {
                var s = info.view.currentStart, e = info.view.currentEnd;
                var p = picked ? parseYmd(picked) : null;
                if (!p || p < s || p >= e) { picked = defaultPick(); pickAuto = true; }
            }
            renderDay();
        },
        onEventsSet: function () {
            if (phoneActive() && mode === 'month' && pickAuto && cal) picked = defaultPick();
            renderDay();
            updateFilterUi();
        },
        handleDateClick: function (info) {
            if (!(phoneActive() && cal && cal.view.type === 'dayGridMonth')) return false;
            pick(info.dateStr, true);
            return true;
        },
        pickDate: function (d) {
            if (phoneActive() && mode === 'month' && d) pick(ymd(d), false);
        },
        sync: sync,
        init: function (calendar, pageApi) {
            cal = calendar;
            api = pageApi;
            if (applied === 'phone') cal.setOption('dayMaxEvents', false);
            updateSeg();
            renderDay();
            updateFilterUi();
            sync();
        }
    };

    function boot() {
        bindOverlays();
        bindClicks();
        syncRequired();
        if (MQ) {
            if (MQ.addEventListener) MQ.addEventListener('change', sync);
            else if (MQ.addListener) MQ.addListener(sync);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
