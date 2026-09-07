    </main>
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 RR - Riveg Rent. Все права защищены.</p>
            <p>Симферополь | Краснодар | Ростов</p>
        </div>
    </footer>
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
    <script src="/assets/js/main.js"></script>
    <script src="/assets/js/notifications.js"></script>
</body>
</html>