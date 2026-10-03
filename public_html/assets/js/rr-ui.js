/**
 * Общие UI-хелперы сайта: подтверждение действия и всплывающие уведомления —
 * замена нативным confirm()/alert() браузера, которые не оформляются под
 * дизайн сайта и не поддерживают тему (тёмная страница — светлое системное
 * окно). Подключается один раз в includes/footer.php, доступен на любой
 * странице без дополнительных <script>.
 *
 * showToast(message, type) — как раньше в events_calendar.php/
 * application_chat.php, только теперь один экземпляр на весь сайт.
 *
 * rrConfirm(message, options?) -> Promise<boolean> — модалка вместо
 * window.confirm(). options: { okText, cancelText, danger }.
 *   if (!(await rrConfirm('Удалить локацию?', { okText: 'Удалить', danger: true }))) return;
 *
 * Поля пароля: каждому <input type="password"> автоматически добавляется
 * «глазик» (показать/скрыть), а <input data-rr-match="[name=password]">
 * сверяется с указанным полем — см. блок «ПОЛЯ ПАРОЛЯ» ниже.
 */
(function () {
    'use strict';

    function ensureToastContainer() {
        var el = document.getElementById('rrToastContainer');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rrToastContainer';
            el.className = 'rr-toast-container';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        return el;
    }

    window.showToast = function (message, type) {
        var container = ensureToastContainer();
        var toast = document.createElement('div');
        toast.className = 'rr-toast' + (type ? ' ' + type : '');
        toast.textContent = message;
        container.appendChild(toast);
        // requestAnimationFrame — чтобы transition сыграл, а не применился
        // сразу с начальным состоянием (элемент только что вставлен в DOM).
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { toast.classList.add('visible'); });
        });
        setTimeout(function () {
            toast.classList.remove('visible');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3500);
    };

    var pendingConfirm = null;
    var confirmOverlay = null;

    function buildConfirmModal() {
        var overlay = document.createElement('div');
        overlay.id = 'rrConfirmOverlay';
        overlay.className = 'modal-overlay rr-confirm-overlay';
        overlay.innerHTML =
            '<div class="modal-box rr-confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="rrConfirmMessage">' +
                '<p id="rrConfirmMessage" class="rr-confirm-message"></p>' +
                '<div class="rr-confirm-actions">' +
                    '<button type="button" class="rr-confirm-btn rr-confirm-cancel"></button>' +
                    '<button type="button" class="rr-confirm-btn rr-confirm-ok"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        var okBtn = overlay.querySelector('.rr-confirm-ok');
        var cancelBtn = overlay.querySelector('.rr-confirm-cancel');

        function close(result) {
            overlay.classList.remove('active');
            var resolve = pendingConfirm;
            pendingConfirm = null;
            if (resolve) resolve(result);
        }

        okBtn.addEventListener('click', function () { close(true); });
        cancelBtn.addEventListener('click', function () { close(false); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close(false);
        });
        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close(false);
        });

        return overlay;
    }

    window.rrConfirm = function (message, options) {
        options = options || {};
        if (!confirmOverlay) confirmOverlay = buildConfirmModal();

        confirmOverlay.querySelector('#rrConfirmMessage').textContent = message;
        var okBtn = confirmOverlay.querySelector('.rr-confirm-ok');
        var cancelBtn = confirmOverlay.querySelector('.rr-confirm-cancel');
        okBtn.textContent = options.okText || 'Подтвердить';
        cancelBtn.textContent = options.cancelText || 'Отмена';
        cancelBtn.hidden = !!options.alertOnly;
        okBtn.classList.toggle('rr-confirm-ok-danger', !!options.danger);

        confirmOverlay.classList.add('active');
        return new Promise(function (resolve) {
            pendingConfirm = resolve;
            okBtn.focus();
        });
    };

    /**
     * rrAlert(message, okText?) -> Promise<void> — та же модалка, но с одной
     * кнопкой, вместо window.alert() для сообщений, которые нужно прочитать
     * и осознанно закрыть (а не просто мелькнувший тост).
     */
    window.rrAlert = function (message, okText) {
        return window.rrConfirm(message, { okText: okText || 'Понятно', alertOnly: true });
    };

    /**
     * Хелпер для ссылок вида <a href="...?action=delete&csrf=..." data-rr-confirm="Удалить?">.
     * Ставится один раз здесь, а не в каждом файле отдельно — перехватывает
     * клик, спрашивает подтверждение и сам переходит по ссылке, если
     * пользователь согласился.
     */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-rr-confirm]');
        if (!link) return;
        e.preventDefault();
        var message = link.getAttribute('data-rr-confirm');
        var okText = link.getAttribute('data-rr-confirm-ok') || undefined;
        var danger = link.hasAttribute('data-rr-confirm-danger');
        rrConfirm(message, { okText: okText, danger: danger }).then(function (ok) {
            if (ok) window.location.href = link.href;
        });
    });

    /**
     * Кнопка "в избранное" — <button class="favorite-btn" data-location-id="123"
     * aria-pressed="true|false">. Один обработчик на весь сайт вместо
     * дублирования fetch-логики в catalog.php/location.php/operator_favorites.php —
     * везде, где кнопка встречается, она уже работает без отдельного <script>.
     * На operator_favorites.php (список избранного) клик снимает карточку со
     * страницы целиком, а не просто гасит иконку — там кнопка это "убрать",
     * а не "переключить".
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.favorite-btn');
        if (!btn || btn.disabled) return;
        e.preventDefault();

        var locationId = btn.dataset.locationId;
        if (!locationId) return;

        btn.disabled = true;
        var formData = new FormData();
        formData.append('action', 'toggle');
        formData.append('location_id', locationId);

        fetch('/api/favorites.php', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) {
                    showToast('Ошибка: ' + data.error, 'error');
                    return;
                }
                if (btn.classList.contains('favorite-btn-remove')) {
                    var card = btn.closest('.favorite-remove-scope');
                    if (card) card.remove();
                    showToast('Убрано из избранного', 'success');
                    return;
                }
                btn.classList.toggle('active', data.favorited);
                btn.setAttribute('aria-pressed', data.favorited ? 'true' : 'false');
                btn.title = data.favorited ? 'Убрать из избранного' : 'В избранное';
            })
            .catch(function () {
                showToast('Ошибка соединения', 'error');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });

    /* ===== ПОЛЯ ПАРОЛЯ =====
       Подключается автоматически ко всем <input type="password"> на сайте
       (вход, регистрация, сброс и смена пароля) — отдельный <script> на
       странице не нужен. Отключить для конкретного поля: data-rr-no-toggle. */

    var ICON_ATTRS = ' class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    // Те же контуры, что у 'eye' / 'eye-off' в includes/icons.php.
    var ICON_EYE = '<svg' + ICON_ATTRS + '><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
    var ICON_EYE_OFF = '<svg' + ICON_ATTRS + '><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';

    var passwordToggles = [];

    function setupPasswordToggle(input) {
        if (input.dataset.rrPwReady === '1' || input.hasAttribute('data-rr-no-toggle')) return;
        input.dataset.rrPwReady = '1';

        // Обёртка нужна, чтобы позиционировать кнопку поверх поля: у
        // .form-group могут быть и другие элементы (подсказка, ошибка).
        var wrap = document.createElement('div');
        wrap.className = 'rr-pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('rr-pw-input');

        // Когда пароль виден как обычный текст — не подчёркиваем его
        // красным как «опечатку» и не отдаём на автоисправление.
        input.spellcheck = false;
        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('autocorrect', 'off');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rr-pw-toggle';
        wrap.appendChild(btn);

        function render(visible) {
            input.type = visible ? 'text' : 'password';
            btn.innerHTML = visible ? ICON_EYE_OFF : ICON_EYE;
            btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
            var label = visible ? 'Скрыть пароль' : 'Показать пароль';
            btn.setAttribute('aria-label', label);
            btn.title = label;
        }
        render(false);
        passwordToggles.push(function () { render(false); });

        // Клик мышью/пальцем не должен отнимать фокус у поля (на телефоне
        // это ещё и закрывало бы клавиатуру).
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            var hadFocus = document.activeElement === input;
            var start = input.selectionStart;
            var end = input.selectionEnd;
            render(input.type === 'password');
            // С клавиатуры (Tab → Enter/Space) фокус остаётся на кнопке.
            if (hadFocus) {
                input.focus();
                try { input.setSelectionRange(start, end); } catch (e) { /* не критично */ }
            }
        });
    }

    /**
     * <input data-rr-match="[name=password]"> — поле подтверждения: живая
     * подсказка «совпадают / не совпадают» и setCustomValidity(), чтобы
     * браузер сам не дал отправить форму (и шаг регистрации — «Продолжить»,
     * который проверяет поля через checkValidity()) с разными паролями.
     * Сервер всё равно перепроверяет — это только удобство, не защита.
     */
    function setupPasswordMatch(confirmInput) {
        if (confirmInput.dataset.rrMatchReady === '1') return;
        var scope = confirmInput.form || document;
        var source = scope.querySelector(confirmInput.getAttribute('data-rr-match'));
        if (!source) return;
        confirmInput.dataset.rrMatchReady = '1';

        var hint = document.createElement('small');
        hint.className = 'form-hint rr-match-hint';
        hint.setAttribute('aria-live', 'polite');
        hint.hidden = true;
        var anchor = confirmInput.closest('.rr-pw-wrap') || confirmInput;
        anchor.insertAdjacentElement('afterend', hint);

        var touched = false;

        function update() {
            var matches = confirmInput.value === source.value;
            confirmInput.setCustomValidity(matches ? '' : 'Пароли не совпадают');

            // Пока человек ещё допечатывает повтор, «не совпадают» было бы
            // ложной тревогой — ругаемся, только если поле уже покинули
            // или повтор длиннее/равен исходному.
            var showBad = !matches && (touched || confirmInput.value.length >= source.value.length);
            hint.hidden = confirmInput.value === '' || (!matches && !showBad);
            hint.textContent = matches ? 'Пароли совпадают' : 'Пароли не совпадают';
            hint.classList.toggle('is-ok', matches);
            hint.classList.toggle('is-bad', !matches);
        }

        source.addEventListener('input', update);
        source.addEventListener('change', update);
        confirmInput.addEventListener('input', update);
        confirmInput.addEventListener('change', update);
        confirmInput.addEventListener('blur', function () { touched = true; update(); });
        // Значения может вернуть сам браузер (кнопка «Назад», автозаполнение,
        // сброс формы) — часто без событий input/change.
        window.addEventListener('pageshow', update);
        if (confirmInput.form) {
            confirmInput.form.addEventListener('reset', function () { setTimeout(update, 0); });
        }
        update();
    }

    function initPasswordFields() {
        document.querySelectorAll('input[type="password"]').forEach(setupPasswordToggle);
        document.querySelectorAll('input[data-rr-match]').forEach(setupPasswordMatch);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPasswordFields);
    } else {
        initPasswordFields();
    }

    // Возврат на страницу кнопкой «Назад» (bfcache) не должен оставлять
    // введённый пароль открытым текстом.
    window.addEventListener('pagehide', function () {
        passwordToggles.forEach(function (hide) { hide(); });
    });
})();
