/**
 * Формы локации на телефоне (≤ 768px): pages/add_location.php и pages/edit_location.php.
 * Стили — assets/css/pages/m/_m-add-location.css и _m-edit-location.css.
 *
 * На десктопе скрипт ничего не меняет: всё, что влияет на поведение формы, выполняется
 * только при совпадении PHONE (ширина ≤ 768px) в момент действия. Встроенные скрипты
 * страниц (превью фото, подсказки городов, звёзды, памятка) остаются как были — здесь
 * только телефонные надстройки над ними:
 *   - проверка полей: ошибки под полями, сводка над кнопкой, прокрутка к первой ошибке;
 *   - отправка: состояние загрузки и защита от двойного нажатия;
 *   - фото: выбор копится (галерея + камера), «убрать», «главное», лимиты 5 шт и 5 МБ;
 *   - проходимость: подпись выбранного уровня; памятка закрывается кнопкой «Назад»;
 *   - город: список подсказок не уезжает под экранную клавиатуру;
 *   - предупреждение при уходе со страницы с несохранёнными изменениями.
 */
(function () {
    'use strict';

    var form = document.querySelector('form.lf-form');
    if (!form) return;

    var mq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
    function phone() { return !!(mq && mq.matches); }
    var isEdit = form.classList.contains('lf-form--edit');

    // ---------- Проверка полей ----------
    var FIELDS = [
        { name: 'title',        label: 'Название', req: 'Укажите название места' },
        { name: 'city_display', label: 'Город',    req: 'Укажите город' },
        { name: 'city',         label: 'Город',    req: 'Укажите город', visibleOnly: true },
        { name: 'address',      label: 'Адрес',    req: 'Укажите адрес' },
        { name: 'price_month',  label: 'Цена',     req: 'Укажите цену аренды в месяц', price: true },
        { name: 'width',        label: 'Ширина',   num: true },
        { name: 'height',       label: 'Высота',   num: true },
        { name: 'depth',        label: 'Глубина',  num: true }
    ];
    var ctaErr = form.querySelector('.lf-cta-err');
    var submitBtn = form.querySelector('button[type="submit"].btn-submit');
    var submitting = false;
    var dirty = false;

    function fieldEl(f) {
        var el = form.querySelector('[name="' + f.name + '"]');
        if (!el || el.type === 'hidden') return null;
        return el;
    }

    function check(f, el) {
        var v = el.validity || {};
        var val = (el.value || '').trim();
        if (v.badInput) return f.price ? 'Введите цену цифрами' : 'Введите число, например 1.2';
        if (f.req && el.required && val === '') return f.req;
        if (f.price && val !== '' && !(parseFloat(val) > 0)) return 'Цена должна быть больше нуля';
        if ((f.price || f.num) && val !== '' && parseFloat(val) < 0) return 'Значение не может быть отрицательным';
        if (v.stepMismatch) return f.price ? 'Цена — целым числом рублей' : 'Не больше одного знака после точки';
        if (v.valid === false) return el.validationMessage || 'Проверьте значение';
        return '';
    }

    function errBox(el) {
        var group = el.closest('.form-group') || el.parentNode;
        var id = 'lfErr-' + el.name;
        var box = document.getElementById(id);
        if (!box) {
            box = document.createElement('div');
            box.className = 'lf-err';
            box.id = id;
            group.appendChild(box);
        }
        return box;
    }

    function setError(el, msg) {
        var id = 'lfErr-' + el.name;
        var described = (el.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== id; });
        if (msg) {
            errBox(el).textContent = msg;
            el.classList.add('lf-invalid');
            el.setAttribute('aria-invalid', 'true');
            described.push(id);
        } else {
            var box = document.getElementById(id);
            if (box) box.parentNode.removeChild(box);
            el.classList.remove('lf-invalid');
            el.removeAttribute('aria-invalid');
        }
        if (described.length) el.setAttribute('aria-describedby', described.join(' '));
        else el.removeAttribute('aria-describedby');
    }

    function validate() {
        var bad = [];
        FIELDS.forEach(function (f) {
            var el = fieldEl(f);
            if (!el) return;
            var msg = check(f, el);
            setError(el, msg);
            if (msg) bad.push({ el: el, label: f.label });
        });
        return bad;
    }

    function showSummary(bad) {
        if (!ctaErr) return;
        if (!bad.length) {
            ctaErr.hidden = true;
            ctaErr.textContent = '';
            document.body.classList.remove('lf-err-on');
            return;
        }
        var names = bad.map(function (b) { return b.label; });
        ctaErr.textContent = bad.length === 1
            ? 'Проверьте поле «' + names[0] + '»'
            : 'Проверьте поля: ' + names.join(', ');
        ctaErr.hidden = false;
        document.body.classList.add('lf-err-on');
    }

    function scrollToGroup(el) {
        var group = el.closest('.form-group') || el;
        var header = document.querySelector('.header');
        var off = 12;
        if (header) {
            var cs = window.getComputedStyle(header);
            if (cs.position === 'sticky' || cs.position === 'fixed') off += header.getBoundingClientRect().height;
        }
        var top = group.getBoundingClientRect().top + window.pageYOffset - off;
        try { window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' }); } catch (e) { window.scrollTo(0, Math.max(0, top)); }
    }

    // Исправили поле — убираем его ошибку и обновляем сводку (только если ошибки уже показывались)
    form.addEventListener('input', function (e) {
        if (e.isTrusted) dirty = true;
        var el = e.target;
        if (!el.classList || !el.classList.contains('lf-invalid')) return;
        FIELDS.forEach(function (f) {
            if (f.name === el.name) setError(el, check(f, el));
        });
        if (ctaErr && !ctaErr.hidden) {
            var left = [];
            FIELDS.forEach(function (f) {
                var x = fieldEl(f);
                if (x && x.classList.contains('lf-invalid')) left.push({ el: x, label: f.label });
            });
            showSummary(left);
        }
    });
    form.addEventListener('change', function (e) { if (e.isTrusted) dirty = true; });

    // На телефоне — своя проверка вместо всплывающих подсказок браузера
    function syncNoValidate() { form.noValidate = phone(); }
    syncNoValidate();
    if (mq) {
        if (mq.addEventListener) mq.addEventListener('change', syncNoValidate);
        else if (mq.addListener) mq.addListener(syncNoValidate);
    }

    // ---------- Отправка: проверка, загрузка, защита от двойного нажатия ----------
    function setLoading(on) {
        if (!submitBtn) return;
        if (on) {
            if (submitBtn.getAttribute('data-lf-html') === null) submitBtn.setAttribute('data-lf-html', submitBtn.innerHTML);
            submitBtn.classList.add('is-loading');
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.innerHTML = '<span class="lf-spin" aria-hidden="true"></span><span>' + (isEdit ? 'Сохраняем…' : 'Публикуем…') + '</span>';
            // disabled — после события: иначе часть браузеров отменяет начавшуюся отправку
            setTimeout(function () { if (submitting) submitBtn.disabled = true; }, 0);
        } else {
            var html = submitBtn.getAttribute('data-lf-html');
            if (html !== null) submitBtn.innerHTML = html;
            submitBtn.removeAttribute('data-lf-html');
            submitBtn.classList.remove('is-loading');
            submitBtn.removeAttribute('aria-busy');
            submitBtn.disabled = false;
        }
    }

    form.addEventListener('submit', function (e) {
        if (!phone()) return;
        if (submitting) { e.preventDefault(); return; }
        var bad = validate();
        if (bad.length) {
            e.preventDefault();
            showSummary(bad);
            try { bad[0].el.focus({ preventScroll: true }); } catch (err) { bad[0].el.focus(); }
            scrollToGroup(bad[0].el);
            return;
        }
        showSummary([]);
        submitting = true;
        dirty = false;
        setLoading(true);
    });

    // Вернулись на страницу «Назад» из кэша — кнопка снова рабочая
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && submitting) { submitting = false; setLoading(false); }
    });

    // Несохранённые изменения: на телефоне легко уйти жестом «назад» и потерять длинную форму
    window.addEventListener('beforeunload', function (e) {
        if (!dirty || submitting || !phone()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ---------- Коммуникации-«пилюли»: класс для браузеров без :has() ----------
    function syncChips() {
        var boxes = form.querySelectorAll('.checkbox-group input[type="checkbox"]');
        for (var i = 0; i < boxes.length; i++) {
            var lab = boxes[i].closest('label');
            if (lab) lab.classList.toggle('is-on', boxes[i].checked);
        }
    }
    form.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('.checkbox-group input[type="checkbox"]')) syncChips();
    });
    syncChips();

    // ---------- Проходимость: подпись выбранного уровня ----------
    var LEVELS = ['', 'низкая', 'ниже среднего', 'средняя', 'высокая', 'максимальная'];
    var starVal = document.getElementById('lfStarVal');
    var ratingInput = document.getElementById('traffic_rating');
    function syncStars() {
        if (!starVal || !ratingInput) return;
        var n = parseInt(ratingInput.value, 10) || 0;
        starVal.textContent = n > 0 ? n + ' из 5 — ' + LEVELS[n] : '';
    }
    form.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.star-rating span')) {
            dirty = true;
            setTimeout(syncStars, 0);
        }
    });
    syncStars();

    // ---------- Памятка «Как оценить»: на телефоне — шторка, «Назад» закрывает её, а не уходит со страницы ----------
    var helpModal = document.getElementById('trafficHelpModal');
    if (helpModal && typeof window.openTrafficHelp === 'function' && typeof window.closeTrafficHelp === 'function') {
        var origOpen = window.openTrafficHelp;
        var origClose = window.closeTrafficHelp;
        var helpOpen = function () { return helpModal.classList.contains('active'); };
        window.openTrafficHelp = function () {
            origOpen();
            if (!phone()) return;
            document.documentElement.classList.add('m-lock');
            try { history.pushState({ lfHelp: 1 }, ''); } catch (e) {}
            var box = helpModal.querySelector('.modal-box');
            if (box) box.scrollTop = 0;
        };
        window.closeTrafficHelp = function () {
            if (helpOpen() && history.state && history.state.lfHelp) {
                history.back();                       // popstate ниже закроет памятку
                setTimeout(function () { if (helpOpen()) { origClose(); document.documentElement.classList.remove('m-lock'); } }, 400);
                return;
            }
            origClose();
            document.documentElement.classList.remove('m-lock');
        };
        window.addEventListener('popstate', function () {
            if (helpOpen() && !(history.state && history.state.lfHelp)) {
                origClose();
                document.documentElement.classList.remove('m-lock');
            }
        });
    }

    // ---------- Город: подсказки не уезжают под клавиатуру ----------
    var sugg = document.getElementById('citySuggestions');
    var cityInput = document.getElementById('cityInput');
    if (sugg && cityInput && window.MutationObserver) {
        new MutationObserver(function () {
            if (!phone() || sugg.style.display !== 'block') return;
            var vv = window.visualViewport;
            var visibleBottom = vv ? vv.height + vv.offsetTop : window.innerHeight;
            var r = sugg.getBoundingClientRect();
            if (r.bottom > visibleBottom - 8) {
                var inputTop = cityInput.getBoundingClientRect().top;
                var shift = Math.min(r.bottom - visibleBottom + 16, inputTop - 60);
                if (shift > 0) window.scrollBy(0, shift);
            }
        }).observe(sugg, { attributes: true, attributeFilter: ['style'], childList: true });
    }

    // ---------- Фото ----------
    var fileInput = document.getElementById('photoInput');
    var preview = document.getElementById('photoPreview');
    var photoGroup = form.querySelector('.lf-o-photos');
    var camInput = form.querySelector('.lf-cam-input');
    var phMsg = form.querySelector('.lf-ph-msg');
    var phCount = form.querySelector('.lf-ph-count');
    var phCountDefault = phCount ? phCount.textContent : '';
    var MAX_FILES = 5;
    var MAX_SIZE = 5 * 1024 * 1024;
    var canSetFiles = (function () { try { return !!new DataTransfer().files; } catch (e) { return false; } })();
    var picked = [];          // файлы, которые сейчас лежат в поле photos[]
    var mainRef = null;       // выбранное главное: { existing: 'existing_7' } или { file: File }
    var internal = false;     // событие change, которое создали мы сами (убрать фото)

    function sameFile(a, b) { return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified; }
    function setFiles(list) {
        var dt = new DataTransfer();
        list.forEach(function (f) { dt.items.add(f); });
        fileInput.files = dt.files;
    }
    function say(lines) {
        if (!phMsg) return;
        phMsg.textContent = lines.join(' ');
        phMsg.hidden = !lines.length;
    }
    function radios() { return form.querySelectorAll('input[type="radio"][name="main_photo"]'); }

    // Какая радиокнопка должна быть отмечена после перерисовки превью
    function wantedValue() {
        if (!mainRef) return null;
        if (mainRef.existing) return mainRef.existing;
        var i = picked.indexOf(mainRef.file);
        return i >= 0 ? 'new_' + i : null;
    }
    function applyMain() {
        var want = wantedValue();
        if (want) {
            var r = form.querySelector('input[type="radio"][name="main_photo"][value="' + want + '"]');
            if (r && !r.checked) r.checked = true;
        }
        syncTiles();
    }
    function rememberMain() {
        var rs = radios();
        for (var i = 0; i < rs.length; i++) {
            if (!rs[i].checked) continue;
            if (rs[i].value.indexOf('existing_') === 0) mainRef = { existing: rs[i].value };
            else {
                var idx = parseInt(rs[i].value.slice(4), 10);
                mainRef = picked[idx] ? { file: picked[idx] } : null;
            }
            return;
        }
    }
    function syncTiles() {
        var rs = radios();
        for (var i = 0; i < rs.length; i++) {
            var tile = rs[i].closest('.photo-preview-item, .photo-item');
            if (tile) tile.classList.toggle('is-main', rs[i].checked);
        }
        var dels = form.querySelectorAll('input[type="checkbox"][name="delete_photos[]"]');
        for (var j = 0; j < dels.length; j++) {
            var t = dels[j].closest('.photo-item');
            if (t) t.classList.toggle('is-del', dels[j].checked);
            var lab = dels[j].closest('label');
            if (lab) lab.setAttribute('title', dels[j].checked ? 'Не удалять' : 'Удалить');
        }
        if (preview) {
            var items = preview.querySelectorAll('.photo-preview-item');
            for (var k = 0; k < items.length; k++) {
                var r = items[k].querySelector('input[type="radio"]');
                var n = r ? parseInt(r.value.slice(4), 10) : k;
                items[k].style.order = String(1 + (isNaN(n) ? k : n));
            }
        }
        if (photoGroup) photoGroup.classList.toggle('is-full', picked.length >= MAX_FILES);
        if (phCount) phCount.textContent = picked.length ? 'Выбрано ' + picked.length + ' из ' + MAX_FILES : phCountDefault;
    }

    if (fileInput && preview) {
        rememberMain();

        // Захват на document срабатывает раньше обработчика страницы на самом поле:
        // подмешиваем к новому выбору уже выбранные фото — страница рисует превью по полному списку.
        document.addEventListener('change', function (e) {
            if (e.target !== fileInput || internal || !phone() || !canSetFiles) return;
            var incoming = Array.prototype.slice.call(fileInput.files || []);
            var next = picked.slice();
            var big = [], extra = 0;
            incoming.forEach(function (f) {
                if (f.size > MAX_SIZE) { big.push(f.name); return; }
                if (next.some(function (x) { return sameFile(x, f); })) return;
                if (next.length >= MAX_FILES) { extra++; return; }
                next.push(f);
            });
            rememberMain();
            picked = next;
            setFiles(next);
            var msg = [];
            if (big.length) msg.push((big.length === 1 ? 'Фото «' + big[0] + '» больше 5 МБ' : big.length + ' фото больше 5 МБ') + ' — не добавлено.');
            if (extra) msg.push('За раз можно добавить не больше ' + MAX_FILES + ' фото, лишние не добавлены.');
            say(msg);
        }, true);

        // Превью страница добавляет асинхронно (по мере чтения файлов) — после каждого
        // добавления возвращаем выбранное «главное» и подсветку плиток.
        if (window.MutationObserver) new MutationObserver(function () { if (phone()) applyMain(); }).observe(preview, { childList: true });

        form.addEventListener('change', function (e) {
            var t = e.target;
            if (t.name === 'main_photo') { rememberMain(); syncTiles(); }
            if (t.name === 'delete_photos[]') syncTiles();
        });

        // «Убрать» новое фото: пересобираем список файлов поля и просим страницу перерисовать превью
        form.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.lf-ph-del');
            if (!btn || !canSetFiles) return;
            e.preventDefault();
            var i = parseInt(btn.getAttribute('data-i'), 10);
            if (isNaN(i)) return;
            rememberMain();
            if (mainRef && mainRef.file && mainRef.file === picked[i]) mainRef = null;
            picked = picked.filter(function (_, k) { return k !== i; });
            setFiles(picked);
            say([]);
            dirty = true;
            internal = true;
            try { fileInput.dispatchEvent(new Event('change', { bubbles: true })); } finally { internal = false; }
            applyMain();
        });

        // Камера: снимок добавляется к уже выбранным фото
        if (camInput) {
            camInput.addEventListener('change', function () {
                var files = Array.prototype.slice.call(camInput.files || []);
                camInput.value = '';
                if (!files.length || !canSetFiles) return;
                setFiles(files);
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
        syncTiles();
    }
})();
