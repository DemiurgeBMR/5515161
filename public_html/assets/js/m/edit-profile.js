/*
 * Настройки аккаунта на телефоне: вкладки «Профиль / Уведомления».
 * На телефоне показывается одна из двух форм (у каждой своя липкая кнопка «Сохранить»),
 * на десктопе обе формы идут подряд — атрибуты, которые ставит скрипт, там ни на что не влияют
 * (все правила — в @media (max-width: 768px), assets/css/pages/m/_m-edit-profile.css).
 * Без скрипта страница остаётся длинной формой с кнопками внутри карточек.
 */
(function () {
    'use strict';

    var page = document.querySelector('.ep-page');
    var tabs = page && page.querySelector('.ep-tabs');
    if (!tabs) return;
    var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-ep-tab]'));

    function select(name, userAction) {
        page.setAttribute('data-ep-tab', name);
        buttons.forEach(function (b) {
            var on = b.getAttribute('data-ep-tab') === name;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
        });
        if (userAction) window.scrollTo(0, 0);
    }

    tabs.hidden = false;
    select(page.getAttribute('data-ep-initial') === 'notif' ? 'notif' : 'profile', false);

    buttons.forEach(function (b, i) {
        b.addEventListener('click', function () { select(b.getAttribute('data-ep-tab'), true); });
        b.addEventListener('keydown', function (e) {
            var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : -1;
            if (n < 0 || n >= buttons.length) return;
            e.preventDefault();
            buttons[n].focus();
            select(buttons[n].getAttribute('data-ep-tab'), true);
        });
    });
})();
