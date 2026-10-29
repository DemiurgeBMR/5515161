/*
 * Сайт гостиницы — поведение страницы.
 *
 * Без сборки и библиотек: один файл, работает в любом современном браузере.
 * Всё, что здесь есть, — улучшение поверх обычного HTML: без JS формы
 * отправляются как обычно, а меню и карта просто остаются статичными.
 *
 * Цену и наличие номера считает сервер (/api/quote.php), свободные даты
 * для календаря отдаёт /api/availability.php — здесь только показ.
 */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    var site = null;
    try { site = JSON.parse(($('#site-data') || {}).textContent || 'null'); } catch (e) { site = null; }

    // ---------------------------------------------------------------
    // Яндекс Метрика — только после согласия на cookie.
    // Номер счётчика задаётся в config/hotel.php (analytics.metrika_id);
    // без него здесь ничего не происходит.
    // ---------------------------------------------------------------

    var metrikaId = +(document.body.getAttribute('data-metrika') || 0);
    var CONSENT_KEY = 'cookie_consent';
    var metrikaOn = false;

    function getConsent() {
        try { return localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
    }
    function setConsent(value) {
        try { if (value) localStorage.setItem(CONSENT_KEY, value); else localStorage.removeItem(CONSENT_KEY); } catch (e) { /* приватный режим */ }
    }

    function loadMetrika() {
        if (metrikaOn || !metrikaId) return;
        metrikaOn = true;
        window.ym = window.ym || function () { (window.ym.a = window.ym.a || []).push(arguments); };
        window.ym.l = 1 * new Date();
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://mc.yandex.ru/metrika/tag.js';
        document.head.appendChild(s);
        window.ym(metrikaId, 'init', {
            clickmap: true,
            trackLinks: true,
            accurateTrackBounce: true,
            webvisor: document.body.getAttribute('data-webvisor') === '1'
        });
    }

    // Отказ: удаляем cookie Метрики, если она успела их поставить.
    function dropMetrikaCookies() {
        document.cookie.split(';').forEach(function (c) {
            var name = c.split('=')[0].trim();
            if (name.indexOf('_ym') !== 0) return;
            var host = location.hostname;
            [host, '.' + host, '.' + host.split('.').slice(-2).join('.')].forEach(function (d) {
                document.cookie = name + '=; Max-Age=0; path=/; domain=' + d;
            });
            document.cookie = name + '=; Max-Age=0; path=/';
        });
    }

    /** Цель в Метрике (цели заводятся в Метрике как «JavaScript-событие» с тем же именем). */
    function goal(name) {
        if (metrikaOn && window.ym) window.ym(metrikaId, 'reachGoal', name);
    }

    function showCookieBar() {
        if (document.querySelector('.cookie-bar')) return;
        var bar = document.createElement('div');
        bar.className = 'cookie-bar';
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', 'Согласие на cookie');
        bar.innerHTML = '<p class="cookie-bar__text">Мы используем cookie и Яндекс Метрику, чтобы понимать, как сделать сайт удобнее. '
            + '<a href="/privacy.php#cookies">Подробнее</a></p>'
            + '<div class="cookie-bar__actions">'
            + '<button type="button" class="btn btn--primary btn--sm" data-cookie="yes">Хорошо</button>'
            + '<button type="button" class="btn btn--outline btn--sm" data-cookie="no">Отказаться</button></div>';
        document.body.appendChild(bar);
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-cookie]');
            if (!btn) return;
            var yes = btn.getAttribute('data-cookie') === 'yes';
            setConsent(yes ? 'yes' : 'no');
            bar.parentNode.removeChild(bar);
            if (yes) loadMetrika();
            else if (metrikaOn) { dropMetrikaCookies(); location.reload(); }
            else dropMetrikaCookies();
        });
    }

    if (metrikaId) {
        var consent = getConsent();
        if (consent === 'yes') loadMetrika();
        else if (consent !== 'no') showCookieBar();
        // В подвале — «Настройки cookie»: можно передумать в любой момент.
        Array.prototype.forEach.call(document.querySelectorAll('[data-cookie-settings]'), function (btn) {
            btn.addEventListener('click', function () { setConsent(null); showCookieBar(); });
        });
        // Звонки и мессенджеры — главные цели для гостиницы.
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href]');
            if (!a) return;
            var href = a.getAttribute('href');
            if (href.indexOf('tel:') === 0) goal('phone_click');
            else if (/^https:\/\/(wa\.me|t\.me|max\.ru)\//.test(href)) goal('messenger_click');
        });
        // Вебвизор не записывает, что гость печатает в формах.
        Array.prototype.forEach.call(document.querySelectorAll('input, textarea'), function (el) { el.classList.add('ym-disable-keys'); });
    }

    // ---------------------------------------------------------------
    // Утилиты
    // ---------------------------------------------------------------

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function money(n) {
        return Math.round(n).toLocaleString('ru-RU').replace(/\s/g, ' ') + ' ₽';
    }

    function plural(n, one, few, many) {
        var n10 = n % 10, n100 = n % 100;
        if (n10 === 1 && n100 !== 11) return one;
        if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) return few;
        return many;
    }

    // Дата в формате input[type=date] по местному времени (toISOString дал бы UTC).
    function isoDate(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function parseDate(s) {
        return /^\d{4}-\d{2}-\d{2}$/.test(s || '') ? new Date(s + 'T00:00:00') : null;
    }

    function nightsBetween(a, b) {
        var d1 = parseDate(a), d2 = parseDate(b);
        return d1 && d2 ? Math.round((d2 - d1) / 86400000) : 0;
    }

    function shortDate(s) {
        var d = parseDate(s);
        return d ? d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : '';
    }

    function phoneDigits(s) {
        return String(s || '').replace(/\D+/g, '');
    }

    function addDays(s, n) {
        var d = parseDate(s);
        d.setDate(d.getDate() + n);
        return isoDate(d);
    }

    function getJSON(url) {
        return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function scrollToEl(el) {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    }

    // ---------------------------------------------------------------
    // Шапка и меню
    // ---------------------------------------------------------------

    var header = $('[data-header]');
    if (header) {
        var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    var navToggle = $('[data-nav-toggle]');
    var nav = $('[data-nav]');
    if (navToggle && nav) {
        var setNav = function (open) {
            navToggle.setAttribute('aria-expanded', String(open));
            nav.classList.toggle('is-open', open);
        };
        navToggle.addEventListener('click', function () {
            setNav(navToggle.getAttribute('aria-expanded') !== 'true');
        });
        nav.addEventListener('click', function (e) {
            if (e.target.closest('a')) setNav(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.classList.contains('is-open')) {
                setNav(false);
                navToggle.focus();
            }
        });
    }

    // ---------------------------------------------------------------
    // Даты: выезд всегда позже заезда
    // ---------------------------------------------------------------

    function linkDates(root) {
        var dIn = $('[data-date-in]', root), dOut = $('[data-date-out]', root);
        if (!dIn || !dOut) return;
        var today = new Date();
        dIn.min = isoDate(today);
        var sync = function () {
            var d = parseDate(dIn.value);
            if (!d) return;
            d.setDate(d.getDate() + 1);
            dOut.min = isoDate(d);
            if (!dOut.value || dOut.value <= dIn.value) dOut.value = isoDate(d);
        };
        dIn.addEventListener('change', sync);
        if (dIn.value) sync();
    }

    $$('form').forEach(linkDates);

    // ---------------------------------------------------------------
    // Телефон: +7 при фокусе и аккуратный формат после ввода
    // ---------------------------------------------------------------

    $$('[data-phone]').forEach(function (input) {
        input.addEventListener('focus', function () {
            if (!input.value) input.value = '+7 ';
        });
        input.addEventListener('blur', function () {
            var d = phoneDigits(input.value);
            if (d.length === 0 || input.value.trim() === '+7') { input.value = ''; return; }
            if (d.length === 10 && d[0] === '9') d = '7' + d;
            if (d.length === 11 && d[0] === '8') d = '7' + d.slice(1);
            // К подставленному «+7» дописали номер целиком: +7 8978… или +7 +7978…
            if (d.length === 12 && d[0] === '7' && (d[1] === '8' || d[1] === '7')) d = '7' + d.slice(2);
            if (d.length === 11 && d[0] === '7') {
                input.value = '+7 ' + d.slice(1, 4) + ' ' + d.slice(4, 7) + '-' + d.slice(7, 9) + '-' + d.slice(9);
            }
        });
    });

    // ---------------------------------------------------------------
    // Форма бронирования: расчёт стоимости и проверка, свободен ли номер
    // ---------------------------------------------------------------

    var form = $('[data-booking]');
    var summaryBodies = $$('[data-summary-body]');
    var capacityHint = $('[data-capacity-hint]');
    var quoteTimer = null;
    var quoteSeq = 0;
    var lastQuote = null;

    function field(name) { return form ? form.elements[name] : null; }

    // Расчёт показывается в двух местах: справа от формы и (на телефоне) внутри неё.
    function setSummary(html) {
        summaryBodies.forEach(function (el) { el.innerHTML = html; });
    }

    function capacityCheck() {
        if (!capacityHint || !site) return;
        var type = site.types[field('room').value];
        var guests = parseInt(field('guests').value, 10) || 1;
        if (type && guests > type.capacity) {
            var fits = Object.keys(site.types).filter(function (k) { return site.types[k].capacity >= guests; })
                .map(function (k) { return '«' + site.types[k].name + '»'; });
            capacityHint.textContent = 'В номере «' + type.name + '» — до ' + type.capacity + ' ' + plural(type.capacity, 'гостя', 'гостей', 'гостей') + '.'
                + (fits.length ? ' Для ' + guests + ' ' + plural(guests, 'гостя', 'гостей', 'гостей') + ' подойдёт ' + fits.join(' или ') + '.' : ' Позвоните нам — что-нибудь придумаем.');
            capacityHint.classList.add('field-hint--warn');
            capacityHint.hidden = false;
        } else {
            capacityHint.hidden = true;
        }
    }

    function renderQuote(q, checkIn, checkOut) {
        var nights = q.nights;
        var period = shortDate(checkIn) + ' — ' + shortDate(checkOut) + ', ' + nights + ' ' + plural(nights, 'ночь', 'ночи', 'ночей');
        var html = '';
        if (!q.available) {
            html += '<p class="summary__warn">' + esc(q.message) + '</p>';
        }
        html += '<dl class="summary__rows"><div class="summary__row"><dt>Даты</dt><dd>' + esc(period) + '</dd></div>';
        var perNight = q.seasonal ? 'в среднем ' + money(q.avg) : money(q.avg);
        html += '<div class="summary__row"><dt>' + esc(q.type.name) + ': ' + esc(perNight) + ' × ' + nights + '</dt><dd>' + esc(money(q.sum)) + '</dd></div>';
        if (q.discount > 0) {
            html += '<div class="summary__row summary__row--discount"><dt>Скидка ' + esc(q.tier.label.toLowerCase()) + ', −' + q.tier.percent + '%</dt><dd>−' + esc(money(q.discount)) + '</dd></div>';
        }
        html += '</dl>';
        if (q.total === null) {
            html += '<dl class="summary__total"><dt>Без скидки</dt><dd>' + esc(money(q.sum)) + '</dd></dl>';
            html += '<p class="summary__nudge">' + esc(q.tier.label) + ' — ' + esc(q.tier.text) + '. Администратор рассчитает индивидуальную цену.</p>';
        } else {
            html += '<dl class="summary__total"><dt>Итого</dt><dd>' + esc(money(q.total)) + '</dd></dl>';
            if (q.next_tier) {
                var more = q.next_tier.nights - nights;
                html += '<p class="summary__nudge">Ещё ' + more + ' ' + plural(more, 'ночь', 'ночи', 'ночей') + ' — и цена станет на ' + q.next_tier.percent + '% ниже. Продлить проживание можно в любой день.</p>';
            }
            if (q.available && q.prepayment > 0) {
                html += '<p class="summary__note">Предоплата после подтверждения: ' + esc(money(q.prepayment)) + '.</p>';
            }
        }
        if (q.available) {
            html += '<p class="summary__ok">' + (field('room').value === '0' ? 'Подберём: «' + esc(q.type.name) + '» — свободен' : 'Номер на эти даты свободен') + '</p>';
        }
        html += '<p class="summary__note">' + (q.seasonal ? 'В эти даты действуют сезонные цены. ' : '') + 'Точную цену подтвердит администратор.' + (q.type.tbd ? ' Цена этого номера уточняется.' : '') + '</p>';
        setSummary(html);
    }

    function renderSummary() {
        if (!form || !summaryBodies.length) return;
        capacityCheck();
        var checkIn = field('check_in').value;
        var checkOut = field('check_out').value;
        if (nightsBetween(checkIn, checkOut) < 1) {
            lastQuote = null;
            setSummary('<p class="summary__empty">Выберите даты и номер — посчитаем стоимость с учётом скидки за длительное проживание и проверим, свободен ли номер.</p>');
            return;
        }
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(function () {
            var seq = ++quoteSeq;
            var url = '/api/quote.php?type=' + encodeURIComponent(field('room').value || '0')
                + '&check_in=' + checkIn + '&check_out=' + checkOut + '&guests=' + encodeURIComponent(field('guests').value || '1');
            getJSON(url).then(function (q) {
                if (seq !== quoteSeq) return;
                if (!q.ok) {
                    lastQuote = null;
                    setSummary('<p class="summary__warn">' + esc(q.message || 'Не получилось посчитать') + '</p>');
                    return;
                }
                lastQuote = q;
                renderQuote(q, checkIn, checkOut);
            }).catch(function () {
                if (seq === quoteSeq) setSummary('<p class="summary__note">Не получилось посчитать — проверьте интернет. Точную цену назовёт администратор.</p>');
            });
        }, 250);
    }

    if (form) {
        ['check_in', 'check_out', 'room', 'guests'].forEach(function (name) {
            field(name).addEventListener('change', renderSummary);
        });
        renderSummary();
    }

    // Перейти к форме, подставив номер или даты
    function goToBooking(values) {
        if (!form) return;
        Object.keys(values).forEach(function (name) {
            if (values[name] !== undefined && values[name] !== '' && field(name)) field(name).value = values[name];
        });
        if (values.check_in && parseDate(values.check_in)) {
            field('check_out').min = addDays(values.check_in, 1);
        }
        renderSummary();
        var section = $('#booking');
        scrollToEl(section);
        var focusTarget = !field('check_in').value ? field('check_in') : (!field('check_out').value ? field('check_out') : field('name'));
        setTimeout(function () { focusTarget.focus({ preventScroll: true }); }, 450);
        if (history.replaceState) history.replaceState(null, '', '#booking');
    }

    $$('[data-book-room]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!form) return;
            e.preventDefault();
            goToBooking({ room: link.getAttribute('data-book-room') });
        });
    });

    var quick = $('[data-quick]');
    if (quick && form) {
        quick.addEventListener('submit', function (e) {
            e.preventDefault();
            goToBooking({
                check_in: quick.elements.check_in.value,
                check_out: quick.elements.check_out.value,
                room: quick.elements.room.value,
                guests: quick.elements.guests.value
            });
        });
    }

    // ---------------------------------------------------------------
    // Форма бронирования: проверка и отправка
    // ---------------------------------------------------------------

    function clearErrors(f) {
        $$('.field__error', f).forEach(function (el) { el.remove(); });
        $$('.has-error', f).forEach(function (el) { el.classList.remove('has-error'); });
        $$('[aria-invalid]', f).forEach(function (el) { el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby'); });
    }

    function showErrors(f, errors) {
        var first = null;
        Object.keys(errors).forEach(function (name) {
            var input = f.elements[name];
            if (!input) return;
            if (input.length && !input.tagName) input = input[0];
            var wrap = input.closest('.field') || input.closest('.check');
            var id = 'err-' + (f.getAttribute('data-booking') !== null ? 'b' : 'n') + '-' + name;
            var msg = document.createElement('p');
            msg.className = 'field__error';
            msg.id = id;
            msg.textContent = errors[name];
            if (wrap) {
                wrap.classList.add('has-error');
                if (wrap.classList.contains('check')) wrap.insertAdjacentElement('afterend', msg); else wrap.appendChild(msg);
            }
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', id);
            if (!first) first = input;
        });
        if (first) first.focus();
    }

    function validateBooking(f) {
        var e = {};
        if (!f.elements.check_in.value) e.check_in = 'Выберите дату заезда';
        if (!f.elements.check_out.value) e.check_out = 'Выберите дату выезда';
        else if (nightsBetween(f.elements.check_in.value, f.elements.check_out.value) < 1) e.check_out = 'Выезд должен быть позже заезда';
        if (!f.elements.name.value.trim()) e.name = 'Как к вам обращаться?';
        var d = phoneDigits(f.elements.phone.value);
        if (d.length < 10 || d.length > 15) e.phone = 'Проверьте номер телефона';
        var email = f.elements.email ? f.elements.email.value.trim() : '';
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) e.email = 'Проверьте адрес почты';
        if (!f.elements.consent.checked) e.consent = 'Без согласия мы не можем принять заявку';
        return e;
    }

    // Текст заявки для мессенджера — если сайт не смог её отправить сам.
    function bookingText(f) {
        var roomKey = f.elements.room.value;
        var lines = ['Здравствуйте! Хочу забронировать номер.'];
        if (f.elements.check_in.value && f.elements.check_out.value) {
            var n = nightsBetween(f.elements.check_in.value, f.elements.check_out.value);
            lines.push('Даты: ' + shortDate(f.elements.check_in.value) + ' — ' + shortDate(f.elements.check_out.value) + ' (' + n + ' ' + plural(n, 'ночь', 'ночи', 'ночей') + ')');
        }
        lines.push('Номер: ' + (site && site.types[roomKey] ? site.types[roomKey].name : 'подберите, пожалуйста'));
        lines.push('Гостей: ' + f.elements.guests.value);
        if (f.elements.name.value.trim()) lines.push('Имя: ' + f.elements.name.value.trim());
        if (f.elements.phone.value.trim()) lines.push('Телефон: ' + f.elements.phone.value.trim());
        if (f.elements.comment.value.trim()) lines.push('Комментарий: ' + f.elements.comment.value.trim());
        return lines.join('\n');
    }

    function showFallback(f, box, reason) {
        var phone = site ? site.phone : '';
        var digits = phoneDigits(phone);
        var tel = digits.length === 11 ? 'tel:+' + digits : '#contacts';
        var wa = site && site.whatsapp && site.whatsapp.indexOf('https://') === 0
            ? site.whatsapp + '?text=' + encodeURIComponent(bookingText(f)) : '';
        var title = reason === 'rate_limit' ? 'Слишком много заявок подряд.'
            : reason === 'network' ? 'Не получилось отправить заявку — похоже, пропал интернет.'
            : reason === 'no_rooms' ? 'На эти даты свободных номеров уже нет — их только что заняли.'
            : 'Не получилось отправить заявку.';
        box.innerHTML = '<strong>' + esc(title) + '</strong> Пожалуйста, позвоните нам или отправьте заявку в мессенджер — мы уже подготовили текст.'
            + '<div class="form-error__actions">'
            + '<a class="btn btn--primary btn--sm" href="' + esc(tel) + '">Позвонить ' + esc(phone) + '</a>'
            + (wa ? '<a class="btn btn--outline btn--sm" href="' + esc(wa) + '" target="_blank" rel="noopener">Отправить в WhatsApp</a>' : '')
            + '</div>';
        box.hidden = false;
        box.scrollIntoView({ block: 'nearest' });
    }

    function submitForm(f, onSuccess, onFail) {
        var btn = $('[type=submit]', f);
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        return fetch(f.action, {
            method: 'POST',
            body: new FormData(f),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.ok && data.ok) onSuccess(data);
                else if (data.error === 'no_rooms') onFail('no_rooms');
                else if (res.status === 422 && data.fields) showErrors(f, data.fields);
                else onFail(data.error || 'server');
            });
        }).catch(function () {
            onFail('network');
        }).then(function () {
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        });
    }

    if (form && window.fetch && window.FormData) {
        form.noValidate = true;
        var errorBox = $('[data-form-error]', form);
        var label = $('[data-submit-label]', form);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(form);
            errorBox.hidden = true;
            var errors = validateBooking(form);
            if (Object.keys(errors).length) { showErrors(form, errors); return; }

            var initial = label.textContent;
            label.textContent = 'Отправляем…';
            submitForm(form, function (data) {
                var success = $('[data-booking-success]');
                var b = data.booking;
                if (b) {
                    $('[data-success-number]', success).textContent = b.number;
                    $('[data-success-details]', success).textContent = b.dates + ', ' + b.type_name + (b.total ? ', ' + b.total : '') + '.';
                    $('[data-success-link]', success).href = b.url;
                    var em = $('[data-success-email]', success);
                    if (b.email) {
                        em.textContent = b.email_sent ? 'Письмо со ссылкой на бронь отправили на ' + b.email + '.' : 'Ссылку на бронь сохраните сейчас — письмо на ' + b.email + ' отправить не получилось.';
                        em.hidden = false;
                    }
                }
                goal('booking_sent');
                $('.booking').hidden = true;
                success.hidden = false;
                success.scrollIntoView({ block: 'center' });
                success.focus({ preventScroll: true });
                if (calendar) calendar.refresh();
            }, function (reason) {
                showFallback(form, errorBox, reason);
            }).then(function () {
                label.textContent = initial;
            });
        });
    }

    // ---------------------------------------------------------------
    // Календарь свободных дат: сколько номеров свободно в каждую ночь.
    // Данные обновляются раз в минуту, пока вкладка открыта.
    // ---------------------------------------------------------------

    var calendar = (function () {
        var root = $('[data-calendar]');
        if (!root || !window.fetch) return null;
        var monthsEl = $('[data-cal-months]', root);
        var pickText = $('[data-cal-pick-text]', root);
        var bookBtn = $('[data-cal-book]', root);
        var prevBtn = $('[data-cal-prev]', root);
        var nextBtn = $('[data-cal-next]', root);
        var typeBtns = $$('[data-type]', root);
        var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        var WEEK = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
        var wide = window.matchMedia('(min-width: 900px)');
        var today = isoDate(new Date());
        var thisMonth = today.slice(0, 7);
        var state = { type: '0', start: thisMonth, data: null, checkIn: null, checkOut: null, note: '' };

        function shiftMonth(key, n) {
            var d = new Date(key + '-01T00:00:00');
            d.setMonth(d.getMonth() + n);
            return isoDate(d).slice(0, 7);
        }

        // Свободно комнат в ночь: по выбранному типу или всего.
        function freeFor(date) {
            var day = state.data && state.data.free[date];
            if (!day) return null;
            if (state.type !== '0') return day[state.type] || 0;
            var sum = 0;
            Object.keys(day).forEach(function (k) { sum += day[k]; });
            return sum;
        }

        function totalRooms() {
            var n = 0;
            (state.data ? state.data.types : []).forEach(function (t) {
                if (state.type === '0' || String(t.id) === state.type) n += t.rooms;
            });
            return n;
        }

        // Свободны ли все ночи периода хотя бы в одном номере одного типа.
        // null — часть дат за пределами загруженных месяцев, проверит сервер.
        function rangeFree(a, b) {
            var types = state.type === '0' ? (state.data ? state.data.types.map(function (t) { return String(t.id); }) : []) : [state.type];
            var unknown = false;
            var ok = types.some(function (t) {
                for (var d = a; d < b; d = addDays(d, 1)) {
                    var day = state.data.free[d];
                    if (!day) { unknown = true; return true; }
                    if (!(day[t] > 0)) return false;
                }
                return true;
            });
            return unknown ? null : ok;
        }

        function load(quiet) {
            var months = wide.matches ? 2 : 1;
            if (!quiet) monthsEl.setAttribute('aria-busy', 'true');
            return getJSON('/api/availability.php?month=' + state.start + '&months=' + months).then(function (data) {
                if (!data.ok) throw new Error('availability');
                state.data = data;
                if (data.today) { today = data.today; thisMonth = today.slice(0, 7); }
                render(months);
            }).catch(function () {
                if (!quiet || !state.data) {
                    monthsEl.innerHTML = '<p class="cal__loading">Не получилось загрузить календарь. Свободные даты подскажут по телефону ' + esc(site ? site.phone : '') + '.</p>';
                }
            }).then(function () { monthsEl.removeAttribute('aria-busy'); });
        }

        function dayState(date) {
            if (date < today) return 'past';
            var f = freeFor(date);
            if (f === null) return 'unknown';
            if (f < 1) return 'full';
            return f === 1 && totalRooms() > 1 ? 'few' : 'free';
        }

        function selectable(date, st) {
            if (st === 'past') return false;
            if (state.checkIn && !state.checkOut && date > state.checkIn) return rangeFree(state.checkIn, date) !== false;
            return st === 'free' || st === 'few';
        }

        function renderMonth(key) {
            var first = new Date(key + '-01T00:00:00');
            var y = first.getFullYear();
            var m = first.getMonth();
            var html = '<div class="cal__month"><h3 class="cal__title">' + MONTHS[m] + ' ' + y + '</h3><div class="cal__grid">';
            WEEK.forEach(function (w, i) { html += '<span class="cal__wd' + (i > 4 ? ' cal__wd--end' : '') + '" aria-hidden="true">' + w + '</span>'; });
            var lead = (first.getDay() + 6) % 7;
            for (var i = 0; i < lead; i++) html += '<span class="cal__blank"></span>';
            var days = new Date(y, m + 1, 0).getDate();
            for (var d = 1; d <= days; d++) {
                var date = key + '-' + (d < 10 ? '0' : '') + d;
                var st = dayState(date);
                var cls = 'cal__day is-' + st;
                if (date === today) cls += ' is-today';
                if (date === state.checkIn) cls += ' is-in';
                if (date === state.checkOut) cls += ' is-out';
                if (state.checkIn && state.checkOut && date > state.checkIn && date < state.checkOut) cls += ' is-range';
                var label = d + ' ' + shortDate(date).split(' ')[1] + ', ' + ({ past: 'прошло', full: 'занято', few: 'остался 1 номер', free: 'свободно', unknown: '' })[st];
                var can = selectable(date, st);
                html += '<button type="button" class="' + cls + '" data-date="' + date + '" aria-label="' + esc(label) + '"'
                    + (date === state.checkIn || date === state.checkOut ? ' aria-pressed="true"' : '') + (can ? '' : ' disabled') + '>'
                    + '<span class="cal__num">' + d + '</span></button>';
            }
            return html + '</div></div>';
        }

        function render(months) {
            var html = '';
            for (var i = 0; i < months; i++) html += renderMonth(shiftMonth(state.start, i));
            monthsEl.innerHTML = html;
            prevBtn.disabled = state.start <= thisMonth;
            nextBtn.disabled = state.start >= shiftMonth(thisMonth, 17);
            updatePick();
        }

        function updatePick() {
            var text;
            if (state.note) {
                text = state.note;
            } else if (!state.checkIn) {
                text = 'Выберите дату заезда';
            } else if (!state.checkOut) {
                text = 'Заезд ' + shortDate(state.checkIn) + '. Теперь выберите дату выезда';
            } else {
                var n = nightsBetween(state.checkIn, state.checkOut);
                text = shortDate(state.checkIn) + ' — ' + shortDate(state.checkOut) + ', ' + n + ' ' + plural(n, 'ночь', 'ночи', 'ночей');
            }
            pickText.textContent = text;
            bookBtn.hidden = !(state.checkIn && state.checkOut);
        }

        monthsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-date]');
            if (!btn || btn.disabled) return;
            var date = btn.getAttribute('data-date');
            state.note = '';
            if (!state.checkIn || state.checkOut || date <= state.checkIn) {
                state.checkIn = date;
                state.checkOut = null;
            } else if (rangeFree(state.checkIn, date) === false) {
                state.note = 'Между этими датами есть занятые ночи — выберите другие даты';
                state.checkIn = null;
            } else {
                state.checkOut = date;
                goal('calendar_dates');
            }
            render(wide.matches ? 2 : 1);
            var again = $('[data-date="' + date + '"]', monthsEl);
            if (again && !again.disabled) again.focus();
        });

        typeBtns.forEach(function (btn) {
            btn.addEventListener('click', function () { setType(btn.getAttribute('data-type')); });
        });

        function setType(type) {
            state.type = String(type);
            typeBtns.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-type') === state.type)); });
            if (state.checkIn && state.checkOut && rangeFree(state.checkIn, state.checkOut) === false) {
                state.checkOut = null;
                state.note = 'В этом номере выбранные даты заняты — выберите другие';
            }
            if (state.data) render(wide.matches ? 2 : 1);
        }

        prevBtn.addEventListener('click', function () { state.start = shiftMonth(state.start, -1); load(); });
        nextBtn.addEventListener('click', function () { state.start = shiftMonth(state.start, 1); load(); });
        if (wide.addEventListener) wide.addEventListener('change', function () { load(true); });

        bookBtn.addEventListener('click', function () {
            goal('calendar_to_booking');
            goToBooking({ check_in: state.checkIn, check_out: state.checkOut, room: state.type });
        });

        // Номера на сайте у всех на виду — обновляем раз в минуту, если вкладка открыта.
        setInterval(function () { if (document.visibilityState === 'visible') load(true); }, 60000);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') load(true); });

        // Календарь грузим, когда до него долистали.
        if ('IntersectionObserver' in window) {
            var seen = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) { seen.disconnect(); load(); }
            }, { rootMargin: '400px' });
            seen.observe(root);
        } else {
            load();
        }

        return { refresh: function () { return load(true); }, setType: setType };
    })();

    // Ссылки «Свободные даты» в карточках номеров
    $$('[data-show-calendar]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!calendar) return;
            e.preventDefault();
            calendar.setType(link.getAttribute('data-show-calendar'));
            scrollToEl($('#calendar'));
        });
    });

    // ---------------------------------------------------------------
    // Фото номеров: стрелки и точки в карточке, а по нажатию на фото или
    // название — окно номера с большой листаемой галереей и описанием.
    // ---------------------------------------------------------------

    function slideIndex(track) {
        return track.clientWidth ? Math.round(track.scrollLeft / track.clientWidth) : 0;
    }
    function slideTo(track, i, smooth) {
        var n = track.children.length;
        i = Math.max(0, Math.min(n - 1, i));
        track.scrollTo({ left: i * track.clientWidth, behavior: smooth === false ? 'auto' : 'smooth' });
    }
    function onSlide(track, fn) {
        var raf = 0;
        track.addEventListener('scroll', function () {
            if (raf) return;
            raf = requestAnimationFrame(function () { raf = 0; fn(slideIndex(track)); });
        });
        fn(slideIndex(track));
    }

    var roomModal = $('[data-room-modal]');
    var canModal = roomModal && typeof roomModal.showModal === 'function';
    var openRoom = function () {};

    if (canModal) {
        var rm = {
            track: $('[data-rm-track]', roomModal), thumbs: $('[data-rm-thumbs]', roomModal),
            counter: $('[data-rm-counter]', roomModal), prev: $('[data-rm-prev]', roomModal), next: $('[data-rm-next]', roomModal),
            name: $('[data-rm-name]', roomModal), price: $('[data-rm-price]', roomModal), facts: $('[data-rm-facts]', roomModal),
            summary: $('[data-rm-summary]', roomModal), features: $('[data-rm-features]', roomModal),
            card: null, pushed: false
        };

        var updateModal = function (i) {
            var n = rm.track.children.length;
            rm.counter.textContent = n > 1 ? (i + 1) + ' / ' + n : '';
            rm.prev.hidden = rm.next.hidden = n < 2;
            rm.prev.disabled = i <= 0;
            rm.next.disabled = i >= n - 1;
            $$('button', rm.thumbs).forEach(function (b, k) { b.setAttribute('aria-current', String(k === i)); });
        };
        onSlide(rm.track, updateModal);

        openRoom = function (card, index) {
            rm.card = card;
            rm.name.textContent = $('.room__open', card).textContent;
            // Цена, удобства и список «Что в номере» — уже готовая разметка карточки
            rm.price.innerHTML = $('.room__price', card).innerHTML;
            rm.facts.innerHTML = $('.room__facts', card).innerHTML;
            rm.summary.textContent = ($('.room__summary', card) || {}).textContent || '';
            var list = $('.room__more .checklist', card);
            rm.features.innerHTML = list ? '<h3 class="room-modal__subtitle">Что в номере</h3>' + list.outerHTML : '';
            roomModal.classList.toggle('room-modal--featured', card.classList.contains('room--featured'));

            rm.track.innerHTML = '';
            rm.thumbs.innerHTML = '';
            var photos = $$('[data-gallery] img', card);
            photos.forEach(function (img, k) {
                var big = document.createElement('img');
                big.src = img.getAttribute('src');
                big.alt = img.alt;
                big.decoding = 'async';
                rm.track.appendChild(big);
                if (photos.length > 1) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.setAttribute('aria-label', 'Фото ' + (k + 1));
                    var t = document.createElement('img');
                    var set = img.getAttribute('srcset');
                    t.src = set ? set.split(' ')[0] : img.getAttribute('src'); // лёгкая копия, если есть
                    t.alt = '';
                    b.appendChild(t);
                    b.addEventListener('click', function () { slideTo(rm.track, k); });
                    rm.thumbs.appendChild(b);
                }
            });
            if (!photos.length) {
                var ph = $('.ph', card);
                if (ph) rm.track.appendChild(ph.cloneNode(true));
            }
            rm.thumbs.hidden = photos.length < 2;

            roomModal.showModal();
            document.documentElement.classList.add('is-locked');
            requestAnimationFrame(function () {
                slideTo(rm.track, index || 0, false);
                updateModal(index || 0);
                rm.track.focus({ preventScroll: true });
            });
            // Кнопка «Назад» на телефоне закрывает окно, а не уходит со страницы.
            if (history.pushState && !rm.pushed) {
                history.pushState({ roomModal: true }, '', location.pathname + location.search + '#nomer-' + card.getAttribute('data-room'));
                rm.pushed = true;
            }
            goal('room_open');
        };

        // after — что сделать, когда окно закрылось и адрес вернулся назад
        // (перейти к форме или календарю): иначе «Назад» сбил бы прокрутку.
        var after = null;
        var runAfter = function () { var f = after; after = null; if (f) f(); };
        var closeRoom = function () { if (roomModal.open) roomModal.close(); };
        roomModal.addEventListener('close', function () {
            document.documentElement.classList.remove('is-locked');
            if (rm.pushed) { rm.pushed = false; history.back(); } else runAfter();
        });
        window.addEventListener('popstate', function () {
            if (roomModal.open) { rm.pushed = false; roomModal.close(); } else setTimeout(runAfter, 0);
        });
        // Нажатие мимо окна закрывает его
        roomModal.addEventListener('click', function (e) { if (e.target === roomModal) closeRoom(); });
        $('[data-rm-close]', roomModal).addEventListener('click', closeRoom);
        rm.prev.addEventListener('click', function () { slideTo(rm.track, slideIndex(rm.track) - 1); });
        rm.next.addEventListener('click', function () { slideTo(rm.track, slideIndex(rm.track) + 1); });
        roomModal.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { e.preventDefault(); slideTo(rm.track, slideIndex(rm.track) - 1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); slideTo(rm.track, slideIndex(rm.track) + 1); }
        });
        // Кнопки окна повторяют кнопки карточки: сначала закрываем окно, потом идём к форме или календарю.
        [['[data-rm-book]', '[data-book-room]'], ['[data-rm-dates]', '[data-show-calendar]']].forEach(function (pair) {
            $(pair[0], roomModal).addEventListener('click', function () {
                var link = rm.card && $(pair[1], rm.card);
                after = function () { if (link) link.click(); };
                closeRoom();
            });
        });
    }

    $$('.room').forEach(function (card) {
        var gal = $('[data-gallery]', card);
        if (gal) {
            var prev = $('[data-gal-prev]', card), next = $('[data-gal-next]', card), dots = $$('.room__dots i', card);
            onSlide(gal, function (i) {
                dots.forEach(function (d, k) { d.classList.toggle('is-on', k === i); });
                if (prev) prev.disabled = i <= 0;
                if (next) next.disabled = i >= gal.children.length - 1;
            });
            if (prev) prev.addEventListener('click', function () { slideTo(gal, slideIndex(gal) - 1); });
            if (next) next.addEventListener('click', function () { slideTo(gal, slideIndex(gal) + 1); });
            if (canModal) {
                gal.addEventListener('click', function () { openRoom(card, slideIndex(gal)); });
                gal.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); openRoom(card, slideIndex(gal)); }
                });
            }
        }
        $$('[data-room-open]', card).forEach(function (el) {
            if (!canModal) { if (el.classList.contains('room__more-link')) el.hidden = true; return; }
            el.addEventListener('click', function (e) {
                e.preventDefault();
                openRoom(card, gal ? slideIndex(gal) : 0);
            });
        });
    });

    // Страница брони: дата нужна только для «Продлить проживание»
    var msgForm = $('[data-message-form]');
    if (msgForm) {
        var extendField = $('[data-extend-field]', msgForm);
        var syncKind = function () {
            var k = msgForm.querySelector('input[name=kind]:checked');
            extendField.hidden = !k || k.value !== 'extend';
        };
        $$('input[name=kind]', msgForm).forEach(function (r) { r.addEventListener('change', syncKind); });
        syncKind();
    }

    // «Сообщить об открытии бани»
    var notify = $('[data-notify]');
    if (notify && window.fetch && window.FormData) {
        notify.noValidate = true;
        var notifyMsg = $('[data-notify-msg]', notify);
        notify.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(notify);
            var errors = {};
            var d = phoneDigits(notify.elements.phone.value);
            if (d.length < 10 || d.length > 15) errors.phone = 'Проверьте номер телефона';
            if (!notify.elements.consent.checked) errors.consent = 'Нужно согласие на обработку данных';
            if (Object.keys(errors).length) { showErrors(notify, errors); return; }
            submitForm(notify, function () {
                goal('bath_waitlist');
                notify.innerHTML = '<p class="notice ' + (notify.closest('.section--dark') ? 'notice--light' : 'notice--ok') + '" role="status">Спасибо! Сообщим, как только откроемся.</p>';
            }, function () {
                notifyMsg.textContent = 'Не получилось отправить. Позвоните нам: ' + (site ? site.phone : '');
                notifyMsg.hidden = false;
            });
        });
    }

    // ---------------------------------------------------------------
    // Карта: грузим Яндекс только по нажатию — быстрее и без лишних cookies
    // ---------------------------------------------------------------

    $$('[data-map-load]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var map = btn.closest('[data-map]');
            var iframe = document.createElement('iframe');
            iframe.src = map.getAttribute('data-map-src');
            iframe.title = 'Карта: как нас найти';
            iframe.loading = 'lazy';
            iframe.allowFullscreen = true;
            map.innerHTML = '';
            map.appendChild(iframe);
        });
    });

    // ---------------------------------------------------------------
    // Баня: галерея, свободное время на дату, расчёт стоимости, запись
    // ---------------------------------------------------------------

    var bathTrack = $('[data-bath-track]');
    if (bathTrack) {
        var bathCount = $$('.bath-gallery__item', bathTrack).length;
        var bathCur = $('[data-bath-current]');
        var bathIdx = function () { return Math.round(bathTrack.scrollLeft / Math.max(1, bathTrack.clientWidth)); };
        var bathGo = function (i) {
            i = (i + bathCount) % bathCount;
            bathTrack.scrollTo({ left: i * bathTrack.clientWidth, behavior: 'smooth' });
        };
        var bp = $('[data-bath-prev]'), bn = $('[data-bath-next]');
        if (bp) bp.addEventListener('click', function () { bathGo(bathIdx() - 1); });
        if (bn) bn.addEventListener('click', function () { bathGo(bathIdx() + 1); });
        bathTrack.addEventListener('scroll', function () { if (bathCur) bathCur.textContent = bathIdx() + 1; }, { passive: true });
        bathTrack.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); bathGo(bathIdx() + 1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); bathGo(bathIdx() - 1); }
        });
    }

    var bathForm = $('[data-bath-form]');
    if (bathForm && window.fetch && window.FormData) {
        bathForm.noValidate = true;
        var bcfg = (site && site.bath) || { price: 0, included: 1, extra_guest: 0, discount: 0 };
        var bDate = $('[data-bath-date]', bathForm);
        var bTimes = $('[data-bath-times]', bathForm);
        var bHint = $('[data-bath-times-hint]', bathForm);
        var bHours = $('[data-bath-hours]', bathForm);
        var bGuests = $('[data-bath-guests]', bathForm);
        var bHotel = $('[data-bath-hotel]', bathForm);
        var bSum = $('[data-bath-sum]', bathForm);
        var bError = $('[data-form-error]', bathForm);
        var bSlots = [];
        var bHourPrice = bcfg.price;

        var bTime = function () {
            var r = bathForm.querySelector('input[name=time]:checked');
            return r ? r.value : '';
        };
        var bSet = function (sel, text) { var el = $(sel, bathForm); if (el) el.textContent = text; };
        var bRow = function (sel, show) { var el = $(sel, bathForm); if (el) el.hidden = !show; };

        var bSyncHours = function () {
            var max = 0;
            bSlots.forEach(function (sl) { if (sl.time === bTime()) max = sl.max_hours; });
            $$('option', bHours).forEach(function (o) { o.disabled = max > 0 && +o.value > max; });
            if (max && +bHours.value > max) bHours.value = String(max);
        };

        var bCalc = function () {
            var hours = +bHours.value, guests = +bGuests.value;
            if (!bTime() || !bHourPrice) { bSum.hidden = true; return; }
            var base = bHourPrice * hours;
            var extraGuests = Math.max(0, guests - bcfg.included);
            var extraSum = extraGuests * bcfg.extra_guest * hours;
            var disc = bHotel && bHotel.checked ? Math.round((base + extraSum) * bcfg.discount / 100) : 0;
            var xs = 0;
            $$('[data-bath-extra]', bathForm).forEach(function (el) {
                xs += Math.max(0, Math.min(20, parseInt(el.value, 10) || 0)) * (+el.getAttribute('data-bath-extra'));
            });
            bSet('[data-bath-sum-base]', hours + ' ' + plural(hours, 'час', 'часа', 'часов') + ' × ' + money(bHourPrice));
            bSet('[data-bath-sum-base-v]', money(base));
            bRow('[data-bath-sum-extra-row]', extraSum > 0);
            bSet('[data-bath-sum-extra]', 'Ещё ' + extraGuests + ' ' + plural(extraGuests, 'гость', 'гостя', 'гостей'));
            bSet('[data-bath-sum-extra-v]', money(extraSum));
            bRow('[data-bath-sum-disc-row]', disc > 0);
            bSet('[data-bath-sum-disc-v]', '−' + money(disc));
            bRow('[data-bath-sum-x-row]', xs > 0);
            bSet('[data-bath-sum-x-v]', money(xs));
            bSet('[data-bath-sum-total]', money(base + extraSum - disc + xs));
            bSum.hidden = false;
        };

        var bRender = function (data) {
            bSlots = data.slots || [];
            if (data.hour_price) bHourPrice = data.hour_price;
            var keep = bTime();
            bTimes.innerHTML = '';
            bSlots.forEach(function (sl) {
                var l = document.createElement('label');
                l.className = 'chip';
                l.innerHTML = '<input type="radio" name="time" value="' + esc(sl.time) + '" required><span>' + esc(sl.time) + '</span>';
                bTimes.appendChild(l);
            });
            var again = keep && bathForm.querySelector('input[name=time][value="' + keep + '"]');
            if (again) again.checked = true;
            bHint.textContent = data.working_day === false ? 'В этот день баня не работает — выберите другую дату.'
                : bSlots.length ? 'Свободное время на ' + shortDate(bDate.value) + '.'
                : 'На ' + shortDate(bDate.value) + ' свободного времени нет — выберите другую дату.';
            bSyncHours();
            bCalc();
        };

        var bLoad = function () {
            if (!bDate.value) return;
            bTimes.setAttribute('aria-busy', 'true');
            fetch('/api/bath.php?date=' + encodeURIComponent(bDate.value), { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(bRender)
                .catch(function () { bHint.textContent = 'Не получилось загрузить свободное время — позвоните нам.'; })
                .then(function () { bTimes.removeAttribute('aria-busy'); });
        };

        bDate.addEventListener('change', bLoad);
        bTimes.addEventListener('change', function () { bSyncHours(); bCalc(); });
        [bHours, bGuests, bHotel].forEach(function (el) { if (el) el.addEventListener('change', bCalc); });
        $$('[data-bath-extra]', bathForm).forEach(function (el) { el.addEventListener('input', bCalc); });
        bLoad();

        bathForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(bathForm);
            bError.hidden = true;
            var errors = {};
            if (!bDate.value) errors.date = 'Выберите дату';
            if (!bTime()) errors.time = 'Выберите время начала';
            if (!bathForm.elements.name.value.trim()) errors.name = 'Как к вам обращаться?';
            var d = phoneDigits(bathForm.elements.phone.value);
            if (d.length < 10 || d.length > 15) errors.phone = 'Проверьте номер телефона';
            var em = bathForm.elements.email.value.trim();
            if (em && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) errors.email = 'Проверьте адрес почты';
            if (!bathForm.elements.consent.checked) errors.consent = 'Без согласия мы не можем принять заявку';
            if (Object.keys(errors).length) { showErrors(bathForm, errors); return; }
            var label = $('[data-submit-label]', bathForm);
            var initial = label.textContent;
            label.textContent = 'Отправляем…';
            submitForm(bathForm, function (data) {
                goal('bath_booking_sent');
                location.href = data.booking && data.booking.url ? data.booking.url : '/bath.php';
            }, function (reason) {
                var phone = site ? site.phone : '';
                var digits = phoneDigits(phone);
                bError.innerHTML = '<strong>' + esc(reason === 'rate_limit' ? 'Слишком много заявок подряд.' : reason === 'network' ? 'Похоже, пропал интернет.' : 'Не получилось записаться.')
                    + '</strong> Позвоните нам — запишем по телефону.<div class="form-error__actions"><a class="btn btn--primary btn--sm" href="'
                    + esc(digits.length === 11 ? 'tel:+' + digits : '#') + '">Позвонить ' + esc(phone) + '</a></div>';
                bError.hidden = false;
            }).then(function () {
                label.textContent = initial;
                // Время могли занять, пока заполняли форму, — обновим список.
                if (bathForm.querySelector('.has-error input[name=time]')) bLoad();
            });
        });
    }

    // ---------------------------------------------------------------
    // Нижняя панель на телефоне прячется, когда видна форма или подвал,
    // чтобы не закрывать кнопку «Отправить заявку»
    // ---------------------------------------------------------------

    var bar = $('[data-action-bar]');
    if (bar && 'IntersectionObserver' in window) {
        var visible = new Set();
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) visible.add(en.target); else visible.delete(en.target);
            });
            bar.classList.toggle('is-hidden', visible.size > 0);
        }, { threshold: 0.05 });
        ['#booking', '.site-footer', '.hero__actions'].forEach(function (sel) {
            var el = $(sel);
            if (el) io.observe(el);
        });
    }
})();
