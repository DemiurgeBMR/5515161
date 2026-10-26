/*
 * Сайт гостиницы — поведение страницы.
 *
 * Без сборки и библиотек: один файл, работает в любом современном браузере.
 * Всё, что здесь есть, — улучшение поверх обычного HTML: без JS формы
 * отправляются как обычно, а меню и карта просто остаются статичными.
 *
 * Цены и скидки приходят из #pricing-data — их кладёт туда PHP из
 * config/hotel.php, отдельно здесь ничего менять не нужно.
 */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    var pricing = null;
    try { pricing = JSON.parse(($('#pricing-data') || {}).textContent || 'null'); } catch (e) { pricing = null; }

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

    function tierFor(nights) {
        var found = null;
        (pricing ? pricing.tiers : []).forEach(function (t) {
            if (nights >= t.nights && (!found || t.nights > found.nights)) found = t;
        });
        return found;
    }

    function nextTier(nights) {
        var found = null;
        (pricing ? pricing.tiers : []).forEach(function (t) {
            if (t.nights > nights && (!found || t.nights < found.nights)) found = t;
        });
        return found;
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
    // Форма бронирования: расчёт стоимости
    // ---------------------------------------------------------------

    var form = $('[data-booking]');
    var summaryBodies = $$('[data-summary-body]');
    var capacityHint = $('[data-capacity-hint]');

    function field(name) { return form ? form.elements[name] : null; }

    // Расчёт показывается в двух местах: справа от формы и (на телефоне) внутри неё.
    function setSummary(html) {
        summaryBodies.forEach(function (el) { el.innerHTML = html; });
    }

    function renderSummary() {
        if (!form || !summaryBodies.length || !pricing) return;
        var checkIn = field('check_in').value;
        var checkOut = field('check_out').value;
        var roomKey = field('room').value;
        var guests = parseInt(field('guests').value, 10) || 1;
        var nights = nightsBetween(checkIn, checkOut);
        var room = pricing.rooms[roomKey];

        // Подсказка о вместимости
        if (capacityHint) {
            if (room && guests > room.guests) {
                var fits = Object.keys(pricing.rooms).filter(function (k) { return pricing.rooms[k].guests >= guests; })
                    .map(function (k) { return '«' + pricing.rooms[k].name + '»'; });
                capacityHint.textContent = 'В номере «' + room.name + '» — до ' + room.guests + ' ' + plural(room.guests, 'гостя', 'гостей', 'гостей') + '.'
                    + (fits.length ? ' Для ' + guests + ' ' + plural(guests, 'гостя', 'гостей', 'гостей') + ' подойдёт ' + fits.join(' или ') + '.' : ' Позвоните нам — что-нибудь придумаем.');
                capacityHint.classList.add('field-hint--warn');
                capacityHint.hidden = false;
            } else {
                capacityHint.hidden = true;
            }
        }

        if (nights < 1) {
            setSummary('<p class="summary__empty">Выберите даты и номер — посчитаем стоимость с учётом скидки за длительное проживание.</p>');
            return;
        }

        var period = shortDate(checkIn) + ' — ' + shortDate(checkOut) + ', ' + nights + ' ' + plural(nights, 'ночь', 'ночи', 'ночей');
        var tier = tierFor(nights);
        var next = nextTier(nights);
        var html = '<dl class="summary__rows"><div class="summary__row"><dt>Даты</dt><dd>' + esc(period) + '</dd></div>';

        if (!room) {
            var min = Math.min.apply(null, Object.keys(pricing.rooms).map(function (k) { return pricing.rooms[k].price; }));
            html += '</dl><p class="summary__note">Подберём номер под ваши даты и число гостей — от ' + esc(money(min)) + ' за сутки.'
                + (tier ? ' ' + esc(tier.label) + ' — ' + esc(tier.text) + '.' : '') + '</p>';
            setSummary(html);
            return;
        }

        var base = room.price * nights;
        html += '<div class="summary__row"><dt>' + esc(room.name) + ': ' + esc(money(room.price)) + ' × ' + nights + '</dt><dd>' + esc(money(base)) + '</dd></div>';

        var total = base;
        var individual = false;
        if (tier && tier.percent !== null) {
            total = Math.round(room.price * (100 - tier.percent) / 100) * nights;
            html += '<div class="summary__row summary__row--discount"><dt>Скидка ' + esc(tier.label.toLowerCase()) + ', −' + tier.percent + '%</dt><dd>−' + esc(money(base - total)) + '</dd></div>';
        } else if (tier) {
            individual = true;
        }
        html += '</dl>';
        html += '<dl class="summary__total"><dt>' + (individual ? 'Без скидки' : 'Итого') + '</dt><dd>' + esc(money(total)) + '</dd></dl>';

        if (individual) {
            html += '<p class="summary__nudge">' + esc(tier.label) + ' — ' + esc(tier.text) + '. Администратор рассчитает индивидуальную цену.</p>';
        } else if (next && next.percent !== null) {
            var more = next.nights - nights;
            html += '<p class="summary__nudge">Ещё ' + more + ' ' + plural(more, 'ночь', 'ночи', 'ночей') + ' — и цена станет на ' + next.percent + '% ниже. Если лечение затянется, продлить можно в любой день.</p>';
        }
        html += '<p class="summary__note">Предварительный расчёт. Точную цену и наличие подтвердит администратор.' + (room.tbd ? ' Цена этого номера уточняется.' : '') + '</p>';
        setSummary(html);
    }

    if (form) {
        ['check_in', 'check_out', 'room', 'guests'].forEach(function (name) {
            field(name).addEventListener('change', renderSummary);
            field(name).addEventListener('input', renderSummary);
        });
        renderSummary();
    }

    // Перейти к форме, подставив номер или даты
    function goToBooking(values) {
        if (!form) return;
        Object.keys(values).forEach(function (name) {
            if (values[name] !== undefined && values[name] !== '' && field(name)) field(name).value = values[name];
        });
        if (values.check_in) {
            var d = parseDate(values.check_in);
            if (d) { d.setDate(d.getDate() + 1); field('check_out').min = isoDate(d); }
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
        lines.push('Номер: ' + (pricing && pricing.rooms[roomKey] ? pricing.rooms[roomKey].name : 'подберите, пожалуйста'));
        lines.push('Гостей: ' + f.elements.guests.value);
        if (f.elements.name.value.trim()) lines.push('Имя: ' + f.elements.name.value.trim());
        if (f.elements.phone.value.trim()) lines.push('Телефон: ' + f.elements.phone.value.trim());
        if (f.elements.comment.value.trim()) lines.push('Комментарий: ' + f.elements.comment.value.trim());
        return lines.join('\n');
    }

    function showFallback(f, box, reason) {
        var phone = pricing ? pricing.phone : '';
        var digits = phoneDigits(phone);
        var tel = digits.length === 11 ? 'tel:+' + digits : '#contacts';
        var wa = pricing && pricing.whatsapp && pricing.whatsapp.indexOf('https://') === 0
            ? pricing.whatsapp + '?text=' + encodeURIComponent(bookingText(f)) : '';
        var title = reason === 'rate_limit' ? 'Слишком много заявок подряд.'
            : reason === 'network' ? 'Не получилось отправить заявку — похоже, пропал интернет.'
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
                if (res.ok && data.ok) onSuccess();
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
            submitForm(form, function () {
                var success = $('[data-booking-success]');
                $('.booking').hidden = true;
                success.hidden = false;
                scrollToEl($('#booking'));
                success.focus({ preventScroll: true });
            }, function (reason) {
                showFallback(form, errorBox, reason);
            }).then(function () {
                label.textContent = initial;
            });
        });
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
                notify.innerHTML = '<p class="notice notice--light" role="status">Спасибо! Сообщим, как только откроемся.</p>';
            }, function () {
                notifyMsg.textContent = 'Не получилось отправить. Позвоните нам: ' + (pricing ? pricing.phone : '');
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
