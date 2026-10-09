    </main>
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col footer-brand">
                    <div class="footer-brand-name">RR · Riveg Rent</div>
                    <p class="footer-cities">Симферополь · Краснодар · Ростов</p>
                </div>

                <div class="footer-col">
                    <h4 class="footer-title">Контакты</h4>
                    <dl class="footer-list">
                        <div><dt>Телефон</dt><dd><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^+0-9]/', '', CONTACT_PHONE)); ?>"><?php echo htmlspecialchars(CONTACT_PHONE); ?></a></dd></div>
                        <div><dt>Email</dt><dd><a href="mailto:<?php echo htmlspecialchars(CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CONTACT_EMAIL); ?></a></dd></div>
                        <div><dt>Адрес</dt><dd><?php echo htmlspecialchars(OPERATOR_ADDRESS); ?></dd></div>
                    </dl>
                </div>

                <div class="footer-col">
                    <h4 class="footer-title">Продавец</h4>
                    <dl class="footer-list">
                        <div><dt>Название</dt><dd><?php echo htmlspecialchars(OPERATOR_NAME); ?></dd></div>
                        <div><dt>ИНН</dt><dd><?php echo htmlspecialchars(OPERATOR_INN); ?></dd></div>
                        <div><dt>ОГРНИП</dt><dd><?php echo htmlspecialchars(OPERATOR_OGRNIP); ?></dd></div>
                    </dl>
                </div>

                <div class="footer-col">
                    <h4 class="footer-title">Документы</h4>
                    <ul class="footer-links">
                        <li><a href="/pages/subscription.php">Тарифы и оплата</a></li>
                        <li><a href="/pages/terms.php">Пользовательское соглашение</a></li>
                        <li><a href="/pages/privacy_policy.php">Политика обработки персональных данных</a></li>
                    </ul>
                </div>
            </div>

            <p class="footer-copy">&copy; <?php echo date('Y'); ?> RR · Riveg Rent. Все права защищены.</p>
        </div>
    </footer>
<?php include __DIR__ . '/mobile_nav.php'; // нижняя панель и шторка для телефонов (скрыты на десктопе) ?>
    <script>
    // CSRF-токен текущей сессии + автоматическая подстановка во все
    // POST-запросы к /api/*, чтобы не дублировать эту логику в каждом
    // отдельном fetch()/XHR/$.ajax по всем страницам сайта.
    window.csrfToken = <?php echo json_encode(csrf_token()); ?>;

    (function(originalFetch) {
        window.fetch = function(input, init) {
            init = init || {};
            var method = (init.method || 'GET').toUpperCase();
            if (method === 'POST') {
                if (init.body instanceof FormData) {
                    if (!init.body.has('csrf_token')) {
                        init.body.append('csrf_token', window.csrfToken);
                    }
                } else {
                    init.headers = Object.assign({}, init.headers, { 'X-CSRF-Token': window.csrfToken });
                }
            }
            return originalFetch(input, init);
        };
    })(window.fetch.bind(window));

    (function(originalSend) {
        XMLHttpRequest.prototype.send = function(body) {
            if (body instanceof FormData && !body.has('csrf_token')) {
                body.append('csrf_token', window.csrfToken);
            }
            return originalSend.call(this, body);
        };
    })(XMLHttpRequest.prototype.send);

    if (window.jQuery) {
        jQuery.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if ((settings.type || 'GET').toUpperCase() === 'POST') {
                    xhr.setRequestHeader('X-CSRF-Token', window.csrfToken);
                }
            }
        });
    }
    </script>
    <script src="/assets/js/notifications.js"></script>
    <script src="/assets/js/rr-ui.js"></script>
    <script src="/assets/js/rr-mobile.js"></script>
<?php
// Интерактивное обучение — только для собственников и операторов. Скрипт
// сам решает, что показать (приглашение, продолжение или ничего) по статусу
// прохождения; если состояние прочитать не удалось (rr_onboarding_state()
// вернул null) — обучения просто нет, страница от этого не страдает.
$rrOnboarding = (isset($_SESSION['user_id']) && rr_onboarding_applies($_SESSION['user_role'] ?? null))
    ? rr_onboarding_state(getDbConnection(), $_SESSION['user_id'])
    : null;
if ($rrOnboarding !== null):
?>
    <script>
    window.rrOnboarding = <?php echo json_encode([
        'role'    => $_SESSION['user_role'],
        'status'  => $rrOnboarding['status'],
        'chapter' => $rrOnboarding['chapter'],
        // Порог «пора обслуживать» — число в тексте обучения берётся отсюда,
        // а не дублируется в JS.
        'serviceDueDays' => SERVICE_DUE_DAYS,
    ], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>
    <script src="/assets/js/rr-tour.js"></script>
<?php endif; ?>
</body>
</html>