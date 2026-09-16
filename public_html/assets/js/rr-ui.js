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
})();
