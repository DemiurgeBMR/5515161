/**
 * Карта на телефоне (≤ 768px), pages/map.php с доступом к карте: карта на весь экран под шапкой,
 * выдвижная панель со списком локаций (ближайшие к центру карты — сверху), карточка-превью
 * по нажатию на метку с кнопкой «Открыть». Подключается до Leaflet; встроенный скрипт карты
 * зовёт RRMapM.preview/attach/fitPadding/noMap только в режиме телефона — десктоп не меняется.
 */
(function () {
    'use strict';

    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };

    function byId(id) { return document.getElementById(id); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return Number(n || 0).toLocaleString('ru-RU'); }
    function stars(n) {
        n = Math.max(0, Math.min(5, parseInt(n, 10) || 0));
        if (!n) return '';
        return '<span class="cat-stars" role="img" aria-label="Проходимость: ' + n + ' из 5">' + '★★★★★'.slice(0, n) + '<i>' + '★★★★★'.slice(0, 5 - n) + '</i></span>';
    }
    var ICON = function (paths) {
        return '<svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    };
    var PIN = ICON('<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>');
    var LOCK = ICON('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>');

    var page, panel, head, list, preview, map = null;

    // ---------- Высота карты: всё между шапкой (и плашками под ней) и нижней панелью ----------
    function fitHeight() {
        if (!page || !PHONE.matches || page.classList.contains('is-nomap')) { if (page) page.style.height = ''; return; }
        var top = page.getBoundingClientRect().top + window.pageYOffset;
        var vh = (window.visualViewport ? window.visualViewport.height : window.innerHeight);
        var bar = document.querySelector('.m-tabbar');
        var barH = bar && getComputedStyle(bar).display !== 'none' ? bar.getBoundingClientRect().height : 0;
        page.style.height = Math.max(180, Math.round(vh - top - barH)) + 'px';
        if (map) map.invalidateSize();
    }

    // ---------- Панель списка ----------
    function setOpen(on) {
        if (!panel) return;
        panel.classList.toggle('is-open', on);
        head.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) hidePreview();
    }

    // ---------- Превью метки ----------
    function hidePreview() { if (preview) preview.hidden = true; }
    function showPreview(loc) {
        if (!preview) return;
        setOpen(false);
        preview.querySelector('.map-preview-img').src = loc.photo;
        preview.querySelector('.map-preview-price').innerHTML = esc(money(loc.price)) + ' ₽ <small>/ мес</small>';
        preview.querySelector('.map-preview-title').textContent = loc.title;
        preview.querySelector('.map-preview-sub').innerHTML = (loc.address ? PIN : LOCK) + ' ' +
            esc(loc.address ? loc.city + ', ' + loc.address : loc.city);
        preview.querySelector('.map-preview-stars').innerHTML = stars(loc.traffic);
        preview.querySelector('.map-preview-open').href = loc.url;
        preview.hidden = false;
    }

    // ---------- Список: ближайшие к центру карты — сверху ----------
    function sortByCenter() {
        if (!map || !list) return;
        var c = map.getCenter();
        var rows = Array.prototype.slice.call(list.children);
        var k = Math.cos(c.lat * Math.PI / 180);
        rows.forEach(function (li) {
            var a = li.querySelector('.map-row');
            var dy = parseFloat(a.getAttribute('data-lat')) - c.lat;
            var dx = (parseFloat(a.getAttribute('data-lng')) - c.lng) * k;
            li._d = dx * dx + dy * dy;
        });
        rows.sort(function (x, y) { return x._d - y._d; }).forEach(function (li) { list.appendChild(li); });
    }

    function init() {
        page = byId('mapPage');
        panel = byId('mapPanel');
        preview = byId('mapPreview');
        if (!page || !page.classList.contains('map-page--full')) return;
        fitHeight();
        window.addEventListener('resize', fitHeight);
        if (window.visualViewport) window.visualViewport.addEventListener('resize', fitHeight);

        if (panel) {
            head = panel.querySelector('.map-panel-head');
            list = panel.querySelector('.map-panel-list');
            head.addEventListener('click', function () { setOpen(!panel.classList.contains('is-open')); });
            // Жест: смахнуть шапку панели вверх — открыть, вниз — закрыть
            var y0 = null;
            head.addEventListener('touchstart', function (e) { y0 = e.touches[0].clientY; }, { passive: true });
            head.addEventListener('touchend', function (e) {
                if (y0 === null) return;
                var dy = e.changedTouches[0].clientY - y0; y0 = null;
                if (Math.abs(dy) > 30) { e.preventDefault(); setOpen(dy < 0); }
            });
        }
        if (preview) {
            preview.querySelector('.map-preview-x').addEventListener('click', hidePreview);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !PHONE.matches) return;
            if (preview && !preview.hidden) hidePreview();
            else if (panel && panel.classList.contains('is-open')) setOpen(false);
        });
    }

    window.RRMapM = {
        // Карта не загрузилась (нет сети до CDN): сообщение на месте карты, список открыт
        noMap: function () {
            if (!PHONE.matches || !page) return;
            page.classList.add('is-nomap');
            document.body.classList.add('m-map-nomap');
            page.style.height = '';
            setOpen(true);
            if (head) {
                head.disabled = true;
                var sub = head.querySelector('small');
                if (sub) sub.textContent = sub.textContent.replace('Ближайшие к центру карты — сверху', 'Все точки списком');
            }
        },
        preview: function (loc) { if (PHONE.matches) showPreview(loc); },
        // Отступы для fitBounds: сверху — строка поиска, снизу — шапка панели
        fitPadding: function () { return { paddingTopLeft: [24, 80], paddingBottomRight: [24, 96], maxZoom: 15 }; },
        attach: function (m) {
            map = m;
            fitHeight();
            map.on('click', hidePreview);
            map.on('movestart', function () { if (panel && panel.classList.contains('is-open')) setOpen(false); });
            map.on('moveend', sortByCenter);
            sortByCenter();
        }
    };

    // Скрипт подключён в конце страницы, после разметки карты, — инициализируемся сразу,
    // до встроенного скрипта Leaflet (он зовёт noMap/attach уже на готовой панели).
    init();
})();
