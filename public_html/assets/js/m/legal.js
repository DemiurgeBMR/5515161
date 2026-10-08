/**
 * Политика и соглашение — липкое оглавление-«чипы» на телефонах (≤ 768px).
 * Подсвечивает чип раздела, который сейчас на экране, и прокручивает ленту чипов к нему
 * (только саму ленту, страница не дёргается). Разметка — pages/privacy_policy.php, terms.php,
 * стили — assets/css/pages/m/_m-legal.css. На десктопе ничего не делает (оглавление там обычное).
 */
(function () {
    'use strict';

    var toc = document.getElementById('legalToc');
    if (!toc || !window.matchMedia) return;
    var mq = window.matchMedia('(max-width: 768px)');

    var links = toc.querySelectorAll('a[href^="#"]');
    var byId = {};
    var sections = [];
    for (var i = 0; i < links.length; i++) {
        var id = links[i].getAttribute('href').slice(1);
        var sec = document.getElementById(id);
        if (sec) { byId[id] = links[i]; sections.push(sec); }
    }
    if (!sections.length) return;

    var current = null;
    function setActive(id) {
        if (!mq.matches || id === current || !byId[id]) return;
        if (current && byId[current]) {
            byId[current].classList.remove('is-on');
            byId[current].removeAttribute('aria-current');
        }
        current = id;
        byId[id].classList.add('is-on');
        byId[id].setAttribute('aria-current', 'true');
        if (toc.scrollWidth > toc.clientWidth) {
            var a = byId[id];
            var left = a.offsetLeft - (toc.clientWidth - a.offsetWidth) / 2;
            if (toc.scrollTo) toc.scrollTo({ left: left, behavior: 'smooth' }); else toc.scrollLeft = left;
        }
    }

    // Активен последний раздел, чей верх уже поднялся выше линии под шапкой и лентой чипов.
    var ticking = false;
    function update() {
        ticking = false;
        if (!mq.matches) return;
        var line = toc.getBoundingClientRect().bottom + 24;
        var pick = sections[0].id;
        for (var j = 0; j < sections.length; j++) {
            if (sections[j].getBoundingClientRect().top <= line) pick = sections[j].id; else break;
        }
        setActive(pick);
    }
    function onScroll() {
        if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    update();

    // Тап по чипу — сразу подсветить (не ждать прокрутки)
    toc.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
        if (a) setActive(a.getAttribute('href').slice(1));
    });
})();
