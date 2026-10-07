/**
 * Карточка локации на телефоне (pages/location.php, ≤ 768px): галерея со счётчиком и просмотром
 * на весь экран, «назад», «поделиться», «Показать полностью» у описания, защита от двойной
 * разблокировки. Разметка — блоки m-only в pages/location.php, стили — css/pages/m/_m-location.css.
 * На десктопе все эти элементы скрыты, обработчики ни на что не влияют.
 */
(function () {
    'use strict';

    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
    var root = document.documentElement;

    function icon(paths) {
        return '<svg class="rr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    }

    // ---------- «Назад»: в историю, если пришли с этого же сайта; иначе — по ссылке (каталог / мои места) ----------
    var back = document.querySelector('[data-m-back]');
    if (back) {
        back.addEventListener('click', function (e) {
            var sameSite = false;
            try { sameSite = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (err) {}
            if (sameSite && history.length > 1) { e.preventDefault(); history.back(); }
        });
    }

    // ---------- «Поделиться»: системное меню телефона, иначе — копируем ссылку ----------
    var share = document.querySelector('[data-m-share]');
    if (share) {
        share.addEventListener('click', function () {
            var data = { title: document.title, url: location.href };
            if (navigator.share) {
                navigator.share(data).catch(function () {});
                return;
            }
            var done = function () { if (window.showToast) window.showToast('Ссылка скопирована', 'success'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(data.url).then(done, function () { window.prompt('Ссылка на объявление', data.url); });
            } else {
                window.prompt('Ссылка на объявление', data.url);
            }
        });
    }

    // ---------- Галерея: счётчик «N / всего» ----------
    var strip = document.querySelector('[data-m-strip]');
    var idxEl = document.querySelector('[data-m-photo-idx]');
    var slides = strip ? strip.querySelectorAll('[data-m-photo]') : [];
    function currentSlide() {
        if (!strip || !slides.length) return 0;
        var w = slides[0].getBoundingClientRect().width + 2;   // + gap
        return Math.max(0, Math.min(slides.length - 1, Math.round(strip.scrollLeft / (w || 1))));
    }
    if (strip && idxEl) {
        var raf = 0;
        strip.addEventListener('scroll', function () {
            if (raf) return;
            raf = window.requestAnimationFrame(function () {
                raf = 0;
                // у правого края ленты (альбомная: видно несколько фото) — последнее
                var atEnd = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 2;
                idxEl.textContent = String(atEnd ? slides.length : currentSlide() + 1);
            });
        }, { passive: true });
    }

    // ---------- Просмотр фото на весь экран: лента со «щелчками», свайп, «Назад» закрывает ----------
    var viewer = null, vStrip = null, vCount = null, lastFocus = null;

    function buildViewer() {
        viewer = document.createElement('div');
        viewer.className = 'm-loc-viewer';
        viewer.hidden = true;
        viewer.setAttribute('role', 'dialog');
        viewer.setAttribute('aria-modal', 'true');
        viewer.setAttribute('aria-label', 'Фотографии');
        viewer.innerHTML = '<div class="m-loc-viewer-bar"><span class="m-loc-viewer-count" aria-live="polite"></span>' +
            '<button type="button" class="m-loc-viewer-x" aria-label="Закрыть">' + icon('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>') + '</button></div>' +
            '<div class="m-loc-viewer-strip"></div>';
        vStrip = viewer.querySelector('.m-loc-viewer-strip');
        vCount = viewer.querySelector('.m-loc-viewer-count');
        Array.prototype.forEach.call(slides, function (s) {
            var img = s.querySelector('img');
            var cell = document.createElement('div');
            var big = document.createElement('img');
            big.alt = img.alt;
            big.loading = 'lazy';
            big.decoding = 'async';
            big.src = img.currentSrc || img.src;
            cell.appendChild(big);
            vStrip.appendChild(cell);
        });
        document.body.appendChild(viewer);

        viewer.querySelector('.m-loc-viewer-x').addEventListener('click', closeViewer);
        var raf2 = 0;
        vStrip.addEventListener('scroll', function () {
            if (raf2) return;
            raf2 = window.requestAnimationFrame(function () { raf2 = 0; updateCount(); });
        }, { passive: true });
    }
    function viewerIndex() {
        return vStrip ? Math.round(vStrip.scrollLeft / (vStrip.clientWidth || 1)) : 0;
    }
    function updateCount() {
        if (vCount) vCount.textContent = (viewerIndex() + 1) + ' / ' + slides.length;
    }
    function isViewerOpen() { return viewer && !viewer.hidden; }

    function openViewer(i) {
        if (!slides.length) return;
        if (!viewer) buildViewer();
        lastFocus = document.activeElement;
        viewer.hidden = false;
        root.classList.add('m-lock');
        vStrip.scrollLeft = i * vStrip.clientWidth;
        updateCount();
        try { history.pushState({ rrViewer: true }, ''); } catch (e) {}
        viewer.querySelector('.m-loc-viewer-x').focus({ preventScroll: true });
    }
    // Фактическое закрытие (без операций с историей). Лента на странице встаёт на то же фото.
    function finishViewer() {
        if (!isViewerOpen()) return;
        var i = viewerIndex();
        viewer.hidden = true;
        if (!document.querySelector('.m-sheet.is-open')) root.classList.remove('m-lock');
        if (strip && slides[i]) {
            strip.scrollLeft = slides[i].offsetLeft - strip.offsetLeft;
            if (idxEl) idxEl.textContent = String(i + 1);
        }
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    }
    function closeViewer() {
        if (!isViewerOpen()) return;
        if (history.state && history.state.rrViewer) {
            history.back();                        // popstate закроет просмотр
            setTimeout(finishViewer, 400);         // запасной вариант
        } else {
            finishViewer();
        }
    }
    window.addEventListener('popstate', function () {
        if (isViewerOpen() && !(history.state && history.state.rrViewer)) finishViewer();
    });
    document.addEventListener('keydown', function (e) {
        if (!isViewerOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); closeViewer(); }
        if (e.key === 'ArrowRight') vStrip.scrollBy({ left: vStrip.clientWidth, behavior: 'smooth' });
        if (e.key === 'ArrowLeft') vStrip.scrollBy({ left: -vStrip.clientWidth, behavior: 'smooth' });
    });
    Array.prototype.forEach.call(slides, function (s) {
        s.addEventListener('click', function () { openViewer(parseInt(s.getAttribute('data-m-photo'), 10) || 0); });
    });

    // ---------- Описание: сворачиваем длинное до ~6 строк, «Показать полностью» ----------
    var desc = document.querySelector('.m-loc .detail-card .description');
    var more = document.querySelector('[data-m-desc-more]');
    var expanded = false;
    function clampDesc() {
        if (!desc || !more || expanded) return;
        if (!PHONE.matches) { desc.classList.remove('is-clamped'); more.hidden = true; return; }
        desc.classList.add('is-clamped');
        var overflows = desc.scrollHeight > desc.clientHeight + 8;
        desc.classList.toggle('is-clamped', overflows);
        more.hidden = !overflows;
    }
    if (desc && more) {
        more.addEventListener('click', function () {
            expanded = !expanded;
            desc.classList.toggle('is-clamped', !expanded);
            more.textContent = expanded ? 'Свернуть' : 'Показать полностью';
            more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (!expanded) desc.scrollIntoView({ block: 'nearest' });
        });
        clampDesc();
        var t = 0;
        window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(clampDesc, 150); });
    }

    // ---------- Мини-карта: не загрузившаяся плитка не показывает «битую картинку» ----------
    Array.prototype.forEach.call(document.querySelectorAll('.m-loc-map-tiles img'), function (img) {
        var broken = function () { img.classList.add('is-broken'); };
        if (img.complete && !img.naturalWidth && img.getAttribute('src')) broken();
        img.addEventListener('error', broken);
    });

    // ---------- Разблокировка: одно нажатие — один кредит (кнопка в шторке отправляет #unlockForm) ----------
    var unlockForm = document.getElementById('unlockForm');
    if (unlockForm) {
        unlockForm.addEventListener('submit', function (e) {
            if (unlockForm.getAttribute('data-sent')) { e.preventDefault(); return; }
            unlockForm.setAttribute('data-sent', '1');
            var btns = document.querySelectorAll('button[form="unlockForm"]');
            for (var i = 0; i < btns.length; i++) {
                btns[i].classList.add('is-disabled');
                btns[i].textContent = 'Открываем…';
            }
        });
    }
})();
