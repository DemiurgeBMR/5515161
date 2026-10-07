/**
 * Чаты на телефоне (≤ 768px): pages/application_chat.php и pages/service_order_chat.php.
 * Стили — assets/css/pages/m/_m-application-chat.css (общий экран-мессенджер) и
 * _m-service-order-chat.css. На десктопе скрипт ничего не меняет: все ветки проверяют PHONE,
 * а RRChatM.afterAppend() там возвращает false — страница прокручивает ленту как раньше.
 *
 *  - лента: при открытии — к последнему сообщению; новые сообщения не уводят вниз, если
 *    человек читает историю (вместо этого — кнопка «вниз» со счётчиком #chatJumpBtn);
 *    лента «держится» за низ при появлении клавиатуры, росте поля ввода, загрузке фото;
 *    разделитель даты для сообщений, пришедших в новый день;
 *  - поле ввода: нажатие «Отправить» не убирает экранную клавиатуру;
 *  - шторка деталей #chatSidebar: открыта = класс .open (+ .show у #sidebarBackdrop) — тот же
 *    механизм, что у обучения (rr-tour.js, setChatSidebar). Здесь — «Назад» закрывает шторку,
 *    смахивание вниз за шапку, фокус и aria. Пока идёт обучение, историю не трогаем.
 */
(function () {
    'use strict';

    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
    var box = document.getElementById('chatMessages');
    if (!box) return;

    var jumpBtn = document.getElementById('chatJumpBtn');
    var jumpCount = jumpBtn ? jumpBtn.querySelector('.chat-m-jump-count') : null;
    var atBottom = true;
    var stickUntil = 0;     // идёт наша плавная прокрутка вниз — считаем, что мы внизу
    var unread = 0;

    function isPhone() { return PHONE.matches; }
    function distance() { return box.scrollHeight - box.scrollTop - box.clientHeight; }

    function toBottom(smooth) {
        stickUntil = Date.now() + (smooth ? 700 : 100);
        atBottom = true;
        unread = 0;
        try {
            box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        } catch (e) {
            box.scrollTop = box.scrollHeight;
        }
        updateJump();
    }

    function updateJump() {
        if (!jumpBtn) return;
        var show = isPhone() && distance() > 240;
        jumpBtn.hidden = !show;
        if (jumpCount) {
            jumpCount.hidden = !(show && unread > 0);
            jumpCount.textContent = unread > 99 ? '99+' : String(unread);
        }
    }

    box.addEventListener('scroll', function () {
        atBottom = distance() < 80 || Date.now() < stickUntil;
        if (distance() < 80) unread = 0;
        updateJump();
    }, { passive: true });

    if (jumpBtn) jumpBtn.addEventListener('click', function () { toBottom(true); });

    // Высота ленты меняется (клавиатура, многострочное поле ввода, поворот) — держимся за низ.
    if (window.ResizeObserver) {
        new ResizeObserver(function () {
            if (isPhone() && atBottom) toBottom(false);
            updateJump();
        }).observe(box);
    }
    // Фото во вложениях догружаются позже и удлиняют ленту.
    box.addEventListener('load', function (e) {
        if (isPhone() && e.target && e.target.tagName === 'IMG' && atBottom) toBottom(false);
    }, true);

    // ---------- Новые сообщения ----------
    var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function dateLabel(d) {
        var today = new Date();
        var yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
        if (ymd(d) === ymd(today)) return 'Сегодня';
        if (ymd(d) === ymd(yesterday)) return 'Вчера';
        return d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
    }
    function addDateSeparator(el, msg) {
        if (!msg || !msg.created_at) return;
        var d = new Date(msg.created_at * 1000);
        if (isNaN(d.getTime())) return;
        var seps = box.querySelectorAll('.date-separator[data-date]');
        var last = seps.length ? seps[seps.length - 1].getAttribute('data-date') : null;
        var key = ymd(d);
        if (key === last) return;
        var sep = document.createElement('div');
        sep.className = 'date-separator';
        sep.setAttribute('data-date', key);
        var span = document.createElement('span');
        span.textContent = dateLabel(d);
        sep.appendChild(span);
        box.insertBefore(sep, el);
    }

    var keepFocus = false;    // поле ввода было в фокусе в момент отправки

    window.RRChatM = {
        // Вызывается страницей сразу после добавления сообщения в ленту.
        afterAppend: function (el, isOwn, msg) {
            // Заглушка «Сообщений пока нет» не должна оставаться над первым сообщением.
            var empty = box.querySelector('.chat-empty');
            if (empty) empty.parentNode.removeChild(empty);
            if (!isPhone()) return false;
            addDateSeparator(el, msg);
            if (isOwn || atBottom) {
                toBottom(true);
            } else {
                unread++;
                updateJump();
            }
            if (isOwn && keepFocus && textarea) {
                try { textarea.focus({ preventScroll: true }); } catch (e) {}
            }
            return true;
        }
    };

    // ---------- Поле ввода ----------
    var form = document.querySelector('.chat-input form');
    var textarea = form ? form.querySelector('textarea') : null;
    var sendBtn = form ? form.querySelector('button[type="submit"]') : null;
    if (sendBtn && textarea) {
        // Нажатие на «Отправить» не забирает фокус у поля — клавиатура остаётся на экране.
        var holdFocus = function (e) {
            if (isPhone() && document.activeElement === textarea) e.preventDefault();
        };
        sendBtn.addEventListener('mousedown', holdFocus);
        sendBtn.addEventListener('pointerdown', holdFocus);
        form.addEventListener('submit', function () {
            keepFocus = isPhone() && document.activeElement === textarea;
        }, true);
    }
    if (textarea) {
        textarea.addEventListener('focus', function () {
            if (isPhone() && atBottom) setTimeout(function () { toBottom(false); }, 300);
        });
    }

    // ---------- «Назад» в шапке чата ----------
    document.addEventListener('click', function (e) {
        var back = e.target.closest('[data-chat-back]');
        if (!back) return;
        var sameOrigin = false;
        try { sameOrigin = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (err) {}
        if (sameOrigin && history.length > 1) {
            e.preventDefault();
            history.back();
        }
    });

    // ---------- Шторка деталей (только чат по заявке) ----------
    var sheet = document.getElementById('chatSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggle = document.getElementById('detailsToggleBtn');
    if (sheet) {
        var wasOpen = false;
        var isOpen = function () { return sheet.classList.contains('open'); };
        var tourActive = function () { return !!document.querySelector('[data-rr-tour-layer]'); };
        var inSheetState = function () { return !!(history.state && history.state.rrChatSheet); };
        var setOpen = function (open) {
            sheet.classList.toggle('open', open);
            if (backdrop) backdrop.classList.toggle('show', open);
        };

        // Перезагрузка/возврат на страницу с «шторочной» записью истории — шторка закрыта.
        if (inSheetState()) { try { history.replaceState(null, ''); } catch (e) {} }

        var applyAria = function () {
            if (isPhone()) {
                sheet.setAttribute('role', 'dialog');
                sheet.setAttribute('aria-modal', 'true');
                sheet.setAttribute('aria-labelledby', 'chatSidebarTitle');
                if (!sheet.hasAttribute('tabindex')) sheet.setAttribute('tabindex', '-1');
            } else {
                ['role', 'aria-modal', 'aria-labelledby'].forEach(function (a) { sheet.removeAttribute(a); });
            }
        };
        applyAria();
        if (PHONE.addEventListener) PHONE.addEventListener('change', applyAria);

        var sync = function () {
            var open = isOpen();
            if (open === wasOpen) return;
            wasOpen = open;
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!isPhone()) return;
            if (open) {
                // Обучение открывает шторку само и само же закрывает — запись истории не нужна.
                if (!tourActive() && !inSheetState()) {
                    try { history.pushState({ rrChatSheet: 1 }, ''); } catch (e) {}
                }
                if (!tourActive()) {
                    sheet.scrollTop = 0;
                    try { sheet.focus({ preventScroll: true }); } catch (e) {}
                }
            } else {
                sheet.style.transform = '';
                sheet.style.transition = '';
                if (inSheetState() && !tourActive()) history.back();
                if (!tourActive() && toggle) { try { toggle.focus({ preventScroll: true }); } catch (e) {} }
            }
        };
        new MutationObserver(sync).observe(sheet, { attributes: true, attributeFilter: ['class'] });

        window.addEventListener('popstate', function () {
            if (isOpen() && !inSheetState() && !tourActive()) setOpen(false);
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted && isOpen() && !tourActive()) setOpen(false);
        });

        // Открыть детали: шапка собеседника, кнопка в закрытом чате
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-chat-details]');
            if (!opener || !isPhone()) return;
            e.preventDefault();
            setOpen(true);
        });

        // Переход по ссылке из шторки: сначала убираем её запись истории, потом идём —
        // иначе «Назад» со следующей страницы вернул бы на этот же чат дважды.
        sheet.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (!link || !isPhone() || e.defaultPrevented || link.hasAttribute('data-rr-confirm')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || (link.target && link.target !== '_self')) return;
            if (!inSheetState()) return;
            e.preventDefault();
            var done = false;
            var go = function () {
                if (done) return;
                done = true;
                window.removeEventListener('popstate', go);
                window.location.href = link.href;
            };
            window.addEventListener('popstate', go);
            history.back();
            setTimeout(go, 300);
        });

        // Смахнуть вниз за ручку/шапку — закрыть.
        var drag = null;
        sheet.addEventListener('touchstart', function (e) {
            if (!isPhone() || !isOpen() || tourActive()) return;
            if (!e.target.closest('.sidebar-header') || e.target.closest('button, a')) return;
            drag = { y0: e.touches[0].clientY, dy: 0 };
            sheet.style.transition = 'none';
        }, { passive: true });
        sheet.addEventListener('touchmove', function (e) {
            if (!drag) return;
            drag.dy = Math.max(0, e.touches[0].clientY - drag.y0);
            sheet.style.transform = 'translateY(' + drag.dy + 'px)';
        }, { passive: true });
        var endDrag = function () {
            if (!drag) return;
            var d = drag;
            drag = null;
            sheet.style.transition = '';
            sheet.style.transform = '';
            if (d.dy > 90) setOpen(false);
        };
        sheet.addEventListener('touchend', endDrag, { passive: true });
        sheet.addEventListener('touchcancel', endDrag, { passive: true });
    }

    // ---------- Старт ----------
    function init() {
        if (isPhone()) toBottom(false);
        updateJump();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.addEventListener('load', function () { if (isPhone() && atBottom) toBottom(false); });
})();
