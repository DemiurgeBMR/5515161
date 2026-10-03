/**
 * Интерактивное обучение для новых пользователей (собственник / оператор).
 *
 * Как это работает
 * ----------------
 * Обучение — это несколько «глав», каждая привязана к странице сайта, а глава
 * — к последовательности шагов: подсветить элемент и объяснить, что это и что
 * с ним делать. Между главами сайт сам переходит на нужную страницу.
 *
 * Состояние (статус и номер главы) лежит в БД — users.onboarding_status /
 * onboarding_chapter, см. api/onboarding.php: «новый пользователь» — свойство
 * аккаунта, а не браузера. Подключается из includes/footer.php только для
 * собственников и операторов вместе с window.rrOnboarding = {role, status, chapter}.
 *
 *   pending     → на любой странице предлагаем начать (окно-приглашение);
 *                 «Пропустить»/Esc — это skipped, повторно не навязываемся
 *   in_progress → на странице текущей главы продолжаем, на другой — плашка
 *                 «Продолжить обучение» (сами никуда не перекидываем)
 *   completed / skipped → ничего не показываем; заново — по кнопке
 *                 [data-rr-tour-start] (меню аккаунта → «Обучение», страница
 *                 «Как это работает»)
 *
 * Если элемента шага нет на странице (например, пустой каталог или скрытый
 * на узком экране блок) — шаг показывается по центру без подсветки, а не
 * пропадает: текст важнее подсветки.
 *
 * Тексты и шаги — в TOURS ниже. Селекторы привязаны к вёрстке страниц
 * (pages/operator_dashboard.php, pages/profile.php и т.д.): меняя вёрстку,
 * проверьте и обучение.
 */
(function () {
    'use strict';

    var state = window.rrOnboarding;
    if (!state || !state.role) return;

    /* ===== СОДЕРЖАНИЕ ОБУЧЕНИЯ =====
       Шаг:
         target    — CSS-селектор (или массив: берётся первый видимый элемент)
         closest   — подняться от найденного элемента к ближайшему предку по селектору
         also      — второй селектор: подсвечиваем объединение двух блоков
         placement — предпочтительная сторона карточки: bottom | top | right | left
         menu      — 'account': на этом шаге раскрыть меню аккаунта
         title, text (абзацы через пустую строку), items (список: строка или
         [жирное начало, продолжение]), ordered (нумерованный список)
       Без target шаг показывается по центру экрана. */
    var TOURS = {
        operator: [
            {
                page: '/pages/operator_dashboard.php',
                nextLabel: 'Открыть каталог →',
                steps: [
                    {
                        target: '.dashboard-nav', placement: 'right',
                        title: 'Меню кабинета',
                        text: 'Слева — разделы, которыми вы будете пользоваться каждый день:',
                        items: [
                            ['Мои заявки', 'переписка с собственниками по каждой локации'],
                            ['Мои точки', 'локации, закреплённые за вами, и ваши автоматы'],
                            ['Выезды', 'календарь установки и обслуживания'],
                            ['Документы', 'шаблон договора размещения'],
                            ['Подписка', 'пополнение контактов'],
                            ['Настройки', 'профиль, пароль, уведомления']
                        ]
                    },
                    {
                        target: '.credits-card', placement: 'bottom',
                        title: 'Ваши контакты',
                        text: 'Контакт открывает точный адрес локации и возможность написать её собственнику. При регистрации вам уже подарили один бесплатный.\n\n' +
                              'Контакт тратится один раз на локацию — дальше она остаётся открытой для вас навсегда. Когда контакты закончатся, пополнить их можно по ссылке справа.'
                    },
                    {
                        target: '.dash-stats-grid', placement: 'bottom',
                        title: 'Ваши показатели',
                        text: 'Активные точки, арендная плата в месяц, число заявок и размещённых автоматов. Плитки кликабельны — нажмите на любую, чтобы увидеть подробный список.'
                    },
                    {
                        target: '.attention-heading', also: '.attention-heading + *', placement: 'top',
                        title: 'Требует внимания',
                        text: 'Всё срочное собирается здесь: новые сообщения, визиты, которые нужно подтвердить, и точки, где пора провести обслуживание. Если блок пуст — всё под контролем.'
                    },
                    {
                        target: '#notificationBellBtn', placement: 'bottom',
                        title: 'Уведомления',
                        text: 'Колокольчик загорится, когда придёт сообщение от собственника, ответ по заявке или изменится время визита. Какие уведомления получать — настраивается в разделе «Настройки».'
                    },
                    {
                        target: '.quick-actions .btn', placement: 'bottom',
                        title: 'Начните с поиска',
                        text: 'Первое, что нужно сделать, — найти подходящую точку. Откроем каталог и разберём, как в нём искать.'
                    }
                ]
            },
            {
                page: '/pages/catalog.php',
                steps: [
                    {
                        target: '.catalog-filters .search-box', placement: 'bottom',
                        title: 'Поиск',
                        text: 'Введите город, тип помещения, район или номер объявления (например, RR-00007) — каталог покажет подходящие локации.'
                    },
                    {
                        target: '.filters-grid', placement: 'bottom',
                        title: 'Фильтры',
                        text: 'Сузьте выдачу по городу, типу помещения, проходимости, цене и площади. Ниже можно отметить нужные удобства — электричество, Wi-Fi, воду.\n\nПосле изменений нажмите «Показать предложения».'
                    },
                    {
                        target: '.catalog-card', placement: 'right',
                        title: 'Карточка локации',
                        text: 'Каждая локация — отдельная карточка: фото, город, цена аренды и проходимость. Точный адрес и контакты скрыты, пока вы не потратите контакт. Нажмите на карточку, чтобы открыть подробности.'
                    },
                    {
                        target: '.catalog-card .favorite-btn', placement: 'bottom', pad: 6,
                        title: 'Избранное',
                        text: 'Сердечко сохраняет локацию, чтобы вернуться к ней позже. Все сохранённые — в меню аккаунта → «Избранное».'
                    },
                    {
                        title: 'Как связаться с собственником',
                        ordered: true,
                        items: [
                            'Откройте карточку нужной локации.',
                            'Нажмите «Разблокировать контакт» — спишется один контакт, откроются точный адрес и имя собственника.',
                            'Нажмите «Отправить заявку на аренду» — откроется чат с собственником.',
                            'Договоритесь об условиях. Когда всё решено, нажмите в чате «Запросить закрепление» — собственник подтвердит его.',
                            'Точка появится в «Моих точках», а выезды на установку и обслуживание вы согласуете в календаре.'
                        ]
                    },
                    {
                        target: '#rrTourRestart', menu: 'account', placement: 'left',
                        title: 'Это всё!',
                        text: 'Если что-то забудете — вернуться к обучению можно в любой момент: меню аккаунта → «Обучение». Подробное описание всех шагов есть и на странице «Как это работает».'
                    }
                ]
            }
        ],

        owner: [
            {
                page: '/pages/profile.php',
                nextLabel: 'Открыть форму →',
                steps: [
                    {
                        target: '.profile-actions .btn-action.primary', placement: 'right',
                        title: 'Добавить локацию',
                        text: 'Главная кнопка собственника. Опишите место — адрес, фотографии, цену аренды, — и объявление уйдёт на проверку. Именно с него всё начинается.'
                    },
                    {
                        target: '.stats-grid', placement: 'right',
                        title: 'Статус объявлений',
                        text: 'Сколько у вас локаций всего, сколько из них активны и сколько ждут проверки. Новое объявление сначала проверяет администратор, и только потом оно появляется в каталоге и становится видно операторам.'
                    },
                    {
                        target: '.profile-actions', placement: 'right',
                        title: 'Разделы кабинета',
                        text: 'Остальные кнопки ведут в рабочие разделы:',
                        items: [
                            ['Заявки', 'сообщения от операторов, чат по каждой локации'],
                            ['Мои операторы', 'кто закреплён за вашими точками; здесь же оператора можно открепить'],
                            ['Выезды', 'календарь установки и обслуживания автоматов'],
                            ['Документы', 'шаблон договора размещения'],
                            ['Редактировать профиль', 'имя, пароль, уведомления']
                        ]
                    },
                    {
                        target: '#attentionToggle', also: '#attentionContent', placement: 'bottom',
                        title: 'Требует внимания',
                        text: 'Здесь появляется всё срочное: новые сообщения операторов, визиты, которые нужно подтвердить или завершить, сломанные автоматы и те, что требуют ремонта или обслуживания. Если блок пуст — всё под контролем. Его можно свернуть.'
                    },
                    {
                        target: '.profile-main .tabs', placement: 'bottom',
                        title: 'Ваши локации',
                        text: 'Ниже — ваши объявления. Вкладки «Все», «Активные» и «На модерации» помогают быстро найти нужное. У каждой карточки есть кнопки: редактировать, скрыть из каталога, удалить.'
                    },
                    {
                        target: '#notificationBellBtn', placement: 'bottom',
                        title: 'Уведомления',
                        text: 'Колокольчик загорится, когда оператор напишет вам, попросит закрепить его за локацией или предложит время визита. Какие уведомления получать — настраивается в «Редактировать профиль».'
                    },
                    {
                        title: 'Добавим первую локацию',
                        text: 'Откроем форму добавления и коротко разберём, что и куда вводить, — так объявление пройдёт проверку с первого раза и быстрее привлечёт операторов.'
                    }
                ]
            },
            {
                page: '/pages/add_location.php',
                steps: [
                    {
                        target: '#cityInput', closest: '.form-group', placement: 'right',
                        title: 'Где находится место',
                        text: 'Начните вводить город и выберите его из подсказки, затем укажите адрес. Точный адрес операторы увидят только после того, как откроют контакт.'
                    },
                    {
                        target: 'input[name="price_month"]', closest: '.form-row', placement: 'right',
                        title: 'Цена и часы доступа',
                        text: 'Укажите стоимость аренды в месяц и когда оператор сможет попадать на точку (например, круглосуточно или по договорённости).'
                    },
                    {
                        target: '.traffic-rating-label', closest: '.form-group', placement: 'right',
                        title: 'Проходимость',
                        text: 'Оцените, сколько людей проходит мимо места: от одной до пяти звёзд. Вопросительный знак рядом открывает подсказку, что значит каждая звезда. По проходимости операторы фильтруют каталог.'
                    },
                    {
                        target: '.checkbox-group', closest: '.form-group', placement: 'right',
                        title: 'Что есть на месте',
                        text: 'Отметьте электричество, Wi-Fi и воду — по этим параметрам операторы тоже ищут точку.'
                    },
                    {
                        target: '.file-upload', closest: '.form-group', placement: 'right',
                        title: 'Фотографии',
                        text: 'Добавьте до пяти фото (JPG, PNG или WEBP, до 5 МБ каждое): хорошие снимки заметно повышают шансы на заявку. Отметьте «Главное» — это фото станет обложкой карточки.'
                    },
                    {
                        target: '#locationForm .btn-submit', placement: 'top',
                        title: 'Отправьте на проверку',
                        text: 'Остальные поля заполняйте по желанию, затем нажмите «Опубликовать локацию». Объявление уйдёт на модерацию, а в каталоге появится после проверки администратором. Статус виден в вашем профиле.'
                    },
                    {
                        title: 'Что будет дальше',
                        ordered: true,
                        items: [
                            'Администратор проверяет объявление, и оно появляется в каталоге.',
                            'Оператор находит вашу локацию, открывает контакт и пишет вам — придёт уведомление, а переписка будет в разделе «Заявки».',
                            'Договоритесь об условиях. Когда договорённость достигнута, оператор запросит закрепление — подтвердите его в чате.',
                            'Установку и обслуживание автомата согласуйте в календаре «Выезды».'
                        ]
                    },
                    {
                        target: '#rrTourRestart', menu: 'account', placement: 'left',
                        title: 'Это всё!',
                        text: 'Если что-то забудете — вернуться к обучению можно в любой момент: меню аккаунта → «Обучение». Подробное описание всех шагов есть и на странице «Как это работает».'
                    }
                ]
            }
        ]
    };

    var chapters = TOURS[state.role];
    if (!chapters) return;

    var totalSteps = chapters.reduce(function (sum, ch) { return sum + ch.steps.length; }, 0);
    var currentPath = window.location.pathname;

    /* ===== ВСПОМОГАТЕЛЬНОЕ ===== */

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function isMobile() {
        return window.matchMedia('(max-width: 600px)').matches;
    }

    function isVisible(node) {
        if (!node) return false;
        var rect = node.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && window.getComputedStyle(node).visibility !== 'hidden';
    }

    function toast(message, type) {
        if (typeof window.showToast === 'function') window.showToast(message, type);
    }

    function firstVisible(selector) {
        var nodes = document.querySelectorAll(selector);
        for (var i = 0; i < nodes.length; i++) {
            if (isVisible(nodes[i])) return nodes[i];
        }
        return null;
    }

    // Запрос к api/onboarding.php. CSRF-токен подставляет обёртка fetch из
    // includes/footer.php. Ответ с ошибкой — отклонённый промис.
    function api(action, chapter) {
        var body = new FormData();
        body.append('action', action);
        if (chapter != null) body.append('chapter', String(chapter));
        return fetch('/api/onboarding.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || data.error) {
                        throw new Error(data.error || ('HTTP ' + response.status));
                    }
                    return data;
                });
            });
    }

    function chapterIndexForPath(path) {
        for (var i = 0; i < chapters.length; i++) {
            if (chapters[i].page === path) return i;
        }
        return -1;
    }

    // Меню аккаунта в шапке: открываем на шаге «Это всё!», чтобы показать, где
    // живёт пункт «Обучение». Разметка и классы — includes/header.php.
    function setAccountMenu(open) {
        var dropdown = document.getElementById('accountMenuDropdown');
        var btn = document.getElementById('accountMenuBtn');
        if (!dropdown || !btn) return;
        dropdown.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // Окно/карточка с «ловушкой» фокуса и закрытием по Esc. Одновременно
    // открыто не больше одного — активное хранится в activeDialog.
    var activeDialog = null;
    var previousFocus = null;

    function onKeydown(e) {
        if (!activeDialog) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            activeDialog.onEscape();
            return;
        }
        if (activeDialog.onKey && activeDialog.onKey(e)) return;

        if (e.key === 'Tab') {
            var focusable = Array.prototype.filter.call(
                activeDialog.pop.querySelectorAll('button'),
                function (b) { return !b.disabled && !b.hidden && isVisible(b); }
            );
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (!activeDialog.pop.contains(document.activeElement)) {
                e.preventDefault();
                first.focus();
            } else if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function openDialog(dialog) {
        if (!activeDialog) {
            previousFocus = document.activeElement;
            document.addEventListener('keydown', onKeydown, true);
        }
        activeDialog = dialog;
    }

    function closeDialog() {
        if (!activeDialog) return;
        activeDialog = null;
        document.removeEventListener('keydown', onKeydown, true);
        if (previousFocus && document.body.contains(previousFocus) && typeof previousFocus.focus === 'function') {
            try { previousFocus.focus({ preventScroll: true }); } catch (e) { /* не критично */ }
        }
        previousFocus = null;
    }

    // Слой гасит клики: они не должны доходить до страницы (в том числе до
    // «закрыть меню по клику снаружи» в шапке) — обучение управляется
    // только своими кнопками.
    function swallowClicks(layer) {
        ['click', 'mousedown', 'mouseup', 'touchstart'].forEach(function (type) {
            layer.addEventListener(type, function (e) { e.stopPropagation(); });
        });
    }

    function appendParagraphs(body, text) {
        text.split('\n\n').forEach(function (para) {
            if (para) body.appendChild(el('p', null, para));
        });
    }

    function appendList(body, items, ordered) {
        var list = el(ordered ? 'ol' : 'ul');
        items.forEach(function (item) {
            var li = el('li');
            if (Array.isArray(item)) {
                li.appendChild(el('strong', null, item[0]));
                li.appendChild(document.createTextNode(' — ' + item[1]));
            } else {
                li.textContent = item;
            }
            list.appendChild(li);
        });
        body.appendChild(list);
    }

    /* ===== ГЛАВА: ПОДСВЕТКА ШАГ ЗА ШАГОМ ===== */

    var run = null; // { ci, si, layer, spot, pop, ..., target, busy, raf, timers }

    function currentStep() {
        return chapters[run.ci].steps[run.si];
    }

    function globalIndex() {
        var n = 0;
        for (var i = 0; i < run.ci; i++) n += chapters[i].steps.length;
        return n + run.si;
    }

    function buildRun(ci) {
        var layer = el('div', 'rr-tour-layer');
        layer.setAttribute('data-rr-tour-layer', '');
        var spot = el('div', 'rr-tour-spot');
        var pop = el('div', 'rr-tour-pop');
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-modal', 'true');
        pop.setAttribute('aria-labelledby', 'rrTourTitle');
        pop.setAttribute('aria-describedby', 'rrTourBody');
        pop.tabIndex = -1;

        var closeBtn = el('button', 'rr-tour-x', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Закрыть обучение');

        var progress = el('div', 'rr-tour-progress');
        var count = el('span');
        var bar = el('div', 'rr-tour-bar');
        var barFill = el('i');
        bar.appendChild(barFill);
        progress.appendChild(count);
        progress.appendChild(bar);

        var title = el('h3', 'rr-tour-title');
        title.id = 'rrTourTitle';
        var body = el('div', 'rr-tour-body');
        body.id = 'rrTourBody';

        var actions = el('div', 'rr-tour-actions');
        var skipBtn = el('button', 'rr-tour-skip', 'Пропустить');
        skipBtn.type = 'button';
        skipBtn.title = 'Пропустить обучение';
        skipBtn.setAttribute('aria-label', 'Пропустить обучение');
        var prevBtn = el('button', 'rr-tour-btn', '← Назад');
        prevBtn.type = 'button';
        var nextBtn = el('button', 'rr-tour-btn rr-tour-btn-primary', 'Далее →');
        nextBtn.type = 'button';
        actions.appendChild(skipBtn);
        actions.appendChild(prevBtn);
        actions.appendChild(nextBtn);

        pop.appendChild(closeBtn);
        pop.appendChild(progress);
        pop.appendChild(title);
        pop.appendChild(body);
        pop.appendChild(actions);
        layer.appendChild(spot);
        layer.appendChild(pop);
        swallowClicks(layer);

        var r = {
            ci: ci, si: 0, layer: layer, spot: spot, pop: pop,
            count: count, barFill: barFill, title: title, body: body,
            skipBtn: skipBtn, prevBtn: prevBtn, nextBtn: nextBtn,
            target: null, busy: false, raf: 0, timers: [], menuOpen: false
        };

        closeBtn.addEventListener('click', skipTour);
        skipBtn.addEventListener('click', skipTour);
        prevBtn.addEventListener('click', prevStep);
        nextBtn.addEventListener('click', nextStep);
        return r;
    }

    function startChapter(ci) {
        removeWelcome();
        removePill();
        if (!run) {
            run = buildRun(ci);
            document.body.appendChild(run.layer);
            window.addEventListener('resize', schedulePlace);
            window.addEventListener('scroll', schedulePlace, true);
            window.addEventListener('load', schedulePlace);
            openDialog({
                pop: run.pop,
                onEscape: skipTour,
                onKey: function (e) {
                    if (e.key === 'ArrowRight') { e.preventDefault(); nextStep(); return true; }
                    if (e.key === 'ArrowLeft') { e.preventDefault(); prevStep(); return true; }
                    return false;
                }
            });
        }
        run.ci = ci;
        run.busy = false;
        showStep(0);
    }

    function findTarget(step) {
        if (!step.target) return null;
        var selectors = Array.isArray(step.target) ? step.target : [step.target];
        for (var i = 0; i < selectors.length; i++) {
            var found = firstVisible(selectors[i]);
            if (found) {
                if (step.closest) found = found.closest(step.closest) || found;
                return found;
            }
        }
        return null;
    }

    // Прямоугольник цели; с `also` — объединение двух блоков (заголовок + его содержимое).
    function targetRect(step, node) {
        var rect = node.getBoundingClientRect();
        var left = rect.left, top = rect.top, right = rect.right, bottom = rect.bottom;
        if (step.also) {
            var extra = firstVisible(step.also);
            if (extra) {
                var er = extra.getBoundingClientRect();
                left = Math.min(left, er.left);
                top = Math.min(top, er.top);
                right = Math.max(right, er.right);
                bottom = Math.max(bottom, er.bottom);
            }
        }
        return { left: left, top: top, right: right, bottom: bottom, width: right - left, height: bottom - top };
    }

    function showStep(si) {
        run.si = si;
        var step = currentStep();
        var ch = chapters[run.ci];
        var isLastOfChapter = si === ch.steps.length - 1;
        var isLastOfTour = isLastOfChapter && run.ci === chapters.length - 1;

        // Меню аккаунта — только пока идёт «его» шаг.
        var wantMenu = step.menu === 'account';
        if (wantMenu !== run.menuOpen) {
            setAccountMenu(wantMenu);
            run.menuOpen = wantMenu;
        }

        // Содержимое
        var done = globalIndex() + 1;
        run.count.textContent = 'Шаг ' + done + ' из ' + totalSteps;
        run.barFill.style.width = Math.round(done / totalSteps * 100) + '%';
        run.title.textContent = step.title;
        run.body.textContent = '';
        if (step.text) appendParagraphs(run.body, step.text);
        if (step.items) appendList(run.body, step.items, !!step.ordered);

        run.prevBtn.disabled = si === 0;
        run.nextBtn.textContent = isLastOfTour ? 'Завершить' : (isLastOfChapter ? (ch.nextLabel || 'Далее →') : 'Далее →');

        // Цель и позиция
        run.target = findTarget(step);
        if (run.target) ensureVisible(run.target, step);
        placeNow();
        // Картинки и шрифты могут сдвинуть вёрстку уже после показа шага.
        clearTimers();
        [120, 400].forEach(function (ms) { run.timers.push(setTimeout(placeNow, ms)); });

        try { run.nextBtn.focus({ preventScroll: true }); } catch (e) { /* не критично */ }
    }

    function clearTimers() {
        if (!run) return;
        run.timers.forEach(clearTimeout);
        run.timers = [];
    }

    // Прокручиваем страницу так, чтобы цель была на виду целиком и не пряталась
    // под карточкой (на телефоне карточка — «шторка» внизу экрана).
    function ensureVisible(node, step) {
        var rect = targetRect(step, node);
        var vh = window.innerHeight;
        var margin = 16;
        var sheet = isMobile() ? Math.min(run.pop.offsetHeight, vh * 0.55) + 24 : 0;
        var visibleBottom = vh - sheet - margin;
        if (rect.top >= margin && rect.bottom <= visibleBottom) return;

        var avail = visibleBottom - margin;
        var wantedTop = rect.height >= avail ? margin : margin + (avail - rect.height) / 2;
        var delta = rect.top - wantedTop;
        try {
            window.scrollBy({ top: delta, left: 0, behavior: 'instant' });
        } catch (e) {
            window.scrollBy(0, delta);
        }
    }

    function schedulePlace() {
        if (!run || run.raf) return;
        run.raf = window.requestAnimationFrame(function () {
            if (run) run.raf = 0;
            placeNow();
        });
    }

    function placeNow() {
        if (!run) return;
        var step = currentStep();
        var pop = run.pop, spot = run.spot;
        var node = run.target && isVisible(run.target) ? run.target : null;
        var rect = node ? targetRect(step, node) : null;
        var mobile = isMobile();

        run.layer.classList.toggle('rr-tour-dim', !rect);
        spot.style.opacity = rect ? '1' : '0';
        pop.classList.toggle('is-center', !rect);
        pop.classList.toggle('is-sheet', !!rect && mobile);

        if (!rect || mobile) {
            pop.style.left = '';
            pop.style.top = '';
            if (!rect) return;
        }

        var pad = step.pad != null ? step.pad : 8;
        spot.style.left = (rect.left - pad) + 'px';
        spot.style.top = (rect.top - pad) + 'px';
        spot.style.width = (rect.width + pad * 2) + 'px';
        spot.style.height = (rect.height + pad * 2) + 'px';
        if (mobile) return;

        var vw = window.innerWidth, vh = window.innerHeight;
        var gap = 14, margin = 12;
        var pw = pop.offsetWidth, ph = pop.offsetHeight;
        var space = {
            bottom: vh - rect.bottom - pad,
            top: rect.top - pad,
            right: vw - rect.right - pad,
            left: rect.left - pad
        };
        var order = ['bottom', 'top', 'right', 'left'];
        if (step.placement) order.unshift(step.placement);

        var side = null;
        for (var i = 0; i < order.length; i++) {
            var need = (order[i] === 'bottom' || order[i] === 'top') ? ph : pw;
            if (space[order[i]] >= need + gap + margin) { side = order[i]; break; }
        }
        if (!side) {
            // Нигде не помещается целиком — берём сторону с наибольшим запасом.
            side = order.reduce(function (best, s) { return space[s] > space[best] ? s : best; }, order[0]);
        }

        var left, top;
        if (side === 'bottom') {
            top = rect.bottom + pad + gap;
            left = rect.left + rect.width / 2 - pw / 2;
        } else if (side === 'top') {
            top = rect.top - pad - gap - ph;
            left = rect.left + rect.width / 2 - pw / 2;
        } else if (side === 'right') {
            left = rect.right + pad + gap;
            top = rect.top + rect.height / 2 - ph / 2;
        } else {
            left = rect.left - pad - gap - pw;
            top = rect.top + rect.height / 2 - ph / 2;
        }
        left = Math.max(margin, Math.min(left, vw - pw - margin));
        top = Math.max(margin, Math.min(top, vh - ph - margin));
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
    }

    function nextStep() {
        if (!run || run.busy) return;
        var ch = chapters[run.ci];
        if (run.si < ch.steps.length - 1) {
            showStep(run.si + 1);
        } else if (run.ci < chapters.length - 1) {
            goToChapter(run.ci + 1);
        } else {
            finishTour();
        }
    }

    function prevStep() {
        if (!run || run.busy || run.si === 0) return;
        showStep(run.si - 1);
    }

    // Переход к следующей главе: сначала сохраняем прогресс, потом уходим на
    // её страницу — иначе после перехода обучение не знало бы, где продолжать.
    function goToChapter(ci) {
        run.busy = true;
        run.nextBtn.disabled = true;
        api('progress', ci).then(function (data) {
            if (data.status !== 'in_progress') {
                // Обучение успели остановить в другой вкладке.
                state.status = data.status;
                endRun();
                return;
            }
            state.status = 'in_progress';
            state.chapter = ci;
            if (chapters[ci].page === currentPath) {
                startChapter(ci);
            } else {
                window.location.href = chapters[ci].page;
            }
        }).catch(function () {
            if (!run) return;
            run.busy = false;
            run.nextBtn.disabled = false;
            toast('Не удалось сохранить прогресс. Проверьте соединение и попробуйте ещё раз.', 'error');
        });
    }

    function finishTour() {
        endRun();
        state.status = 'completed';
        api('complete').then(function () {
            toast('Обучение пройдено! Вернуться к нему можно в меню аккаунта → «Обучение».', 'success');
        }).catch(function () {
            toast('Не удалось сохранить прохождение обучения — оно может показаться снова.', 'error');
        });
    }

    function skipTour() {
        endRun();
        removeWelcome();
        removePill();
        state.status = 'skipped';
        api('skip').then(function () {
            toast('Обучение пропущено. Пройти его можно в любой момент: меню аккаунта → «Обучение».');
        }).catch(function () {
            toast('Не удалось сохранить выбор — обучение может показаться снова.', 'error');
        });
    }

    function endRun() {
        if (!run) return;
        clearTimers();
        if (run.raf) window.cancelAnimationFrame(run.raf);
        window.removeEventListener('resize', schedulePlace);
        window.removeEventListener('scroll', schedulePlace, true);
        window.removeEventListener('load', schedulePlace);
        if (run.menuOpen) setAccountMenu(false);
        if (run.layer.parentNode) run.layer.parentNode.removeChild(run.layer);
        run = null;
        closeDialog();
    }

    /* ===== ПРИГЛАШЕНИЕ (status = pending) ===== */

    var welcome = null;

    function showWelcome() {
        var layer = el('div', 'rr-tour-layer rr-tour-dim');
        var pop = el('div', 'rr-tour-pop rr-tour-welcome is-center');
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-modal', 'true');
        pop.setAttribute('aria-labelledby', 'rrTourWelcomeTitle');
        pop.setAttribute('aria-describedby', 'rrTourWelcomeBody');
        pop.tabIndex = -1;

        var closeBtn = el('button', 'rr-tour-x', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Пропустить обучение');

        var title = el('h3', 'rr-tour-title', 'Добро пожаловать в RR!');
        title.id = 'rrTourWelcomeTitle';

        var body = el('div', 'rr-tour-body');
        body.id = 'rrTourWelcomeBody';
        body.appendChild(el('p', null, state.role === 'owner'
            ? 'Вы зарегистрировались как собственник помещения. Покажем за пару минут, как добавить локацию и начать получать заявки от операторов.'
            : 'Вы зарегистрировались как оператор вендинга. Покажем за пару минут, как найти точку, открыть контакт собственника и договориться об аренде.'));
        body.appendChild(el('p', null, 'Обучение можно пропустить — оно всегда доступно в меню аккаунта → «Обучение».'));

        var actions = el('div', 'rr-tour-actions');
        var skipBtn = el('button', 'rr-tour-btn', 'Пропустить');
        skipBtn.type = 'button';
        var startBtn = el('button', 'rr-tour-btn rr-tour-btn-primary', 'Начать обучение →');
        startBtn.type = 'button';
        actions.appendChild(skipBtn);
        actions.appendChild(startBtn);

        pop.appendChild(closeBtn);
        pop.appendChild(title);
        pop.appendChild(body);
        pop.appendChild(actions);
        layer.appendChild(pop);
        swallowClicks(layer);
        document.body.appendChild(layer);

        welcome = { layer: layer, pop: pop };
        openDialog({ pop: pop, onEscape: skipTour });

        closeBtn.addEventListener('click', skipTour);
        skipBtn.addEventListener('click', skipTour);
        startBtn.addEventListener('click', function () { startTour(startBtn); });
        try { startBtn.focus({ preventScroll: true }); } catch (e) { /* не критично */ }
    }

    function removeWelcome() {
        if (!welcome) return;
        if (welcome.layer.parentNode) welcome.layer.parentNode.removeChild(welcome.layer);
        welcome = null;
        if (!run) closeDialog();
    }

    /* ===== ПЛАШКА «ПРОДОЛЖИТЬ» (status = in_progress, но мы не на странице главы) ===== */

    var pill = null;

    function showPill(ci) {
        var node = el('div', 'rr-tour-pill');
        node.setAttribute('role', 'region');
        node.setAttribute('aria-label', 'Обучение');
        node.appendChild(el('span', 'rr-tour-pill-text', 'Вы не закончили обучение'));
        var goBtn = el('button', 'rr-tour-btn rr-tour-btn-primary', 'Продолжить');
        goBtn.type = 'button';
        var stopBtn = el('button', 'rr-tour-btn', 'Закрыть');
        stopBtn.type = 'button';
        node.appendChild(goBtn);
        node.appendChild(stopBtn);
        document.body.appendChild(node);
        pill = node;

        goBtn.addEventListener('click', function () { window.location.href = chapters[ci].page; });
        stopBtn.addEventListener('click', skipTour);
    }

    function removePill() {
        if (!pill) return;
        if (pill.parentNode) pill.parentNode.removeChild(pill);
        pill = null;
    }

    /* ===== ЗАПУСК И ПЕРЕЗАПУСК ===== */

    // «Начать»/«Пройти заново»: прогресс обнуляется на сервере, затем — на
    // страницу первой главы (или сразу к шагам, если мы уже на ней).
    var starting = false;

    function startTour(button) {
        if (starting) return;
        starting = true;
        if (button) button.disabled = true;
        api('start').then(function () {
            state.status = 'in_progress';
            state.chapter = 0;
            removeWelcome();
            if (run) endRun();
            if (chapters[0].page === currentPath) {
                starting = false;
                startChapter(0);
            } else {
                window.location.href = chapters[0].page;
            }
        }).catch(function () {
            starting = false;
            if (button) button.disabled = false;
            toast('Не удалось запустить обучение. Попробуйте ещё раз.', 'error');
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-rr-tour-start]');
        if (!trigger) return;
        e.preventDefault();
        setAccountMenu(false);
        startTour(null);
    });

    window.rrTour = { start: function () { startTour(null); } };

    function init() {
        if (state.status === 'pending') {
            showWelcome();
        } else if (state.status === 'in_progress') {
            var ci = Math.max(0, Math.min(chapters.length - 1, parseInt(state.chapter, 10) || 0));
            if (chapters[ci].page === currentPath) {
                startChapter(ci);
            } else {
                showPill(ci);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
