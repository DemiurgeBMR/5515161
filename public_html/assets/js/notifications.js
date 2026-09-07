$(document).ready(function() {
    let lastCheck = Math.floor(Date.now() / 1000);
    let notificationsEnabled = true;
    let lastNotificationId = 0;

    const container = $('<div class="toast-container"></div>').appendTo('body');

    // === Проверка новых сообщений (чат) ===
    function checkNewMessages() {
        if (!notificationsEnabled) return;
        $.ajax({
            url: '/api/get_new_messages.php',
            data: { last_check: lastCheck },
            dataType: 'json',
            success: function(data) {
                if (data.messages && data.messages.length > 0) {
                    data.messages.forEach(function(msg) {
                        showToast(msg);
                    });
                    lastCheck = Math.floor(Date.now() / 1000);
                }
            },
            complete: function() {
                setTimeout(checkNewMessages, 15000);
            }
        });
    }

    // === Проверка новых системных уведомлений ===
    function checkNewNotifications() {
        if (!notificationsEnabled) return;
        $.ajax({
            url: '/api/get_notifications.php',
            data: {
                action: 'list',
                last_id: lastNotificationId
            },
            dataType: 'json',
            success: function(data) {
                if (data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(function(notif) {
                        showToastNotification(notif);
                        // ★ Отмечаем как прочитанное сразу после показа
                        markNotificationRead(notif.id);
                        // Обновляем lastNotificationId
                        if (notif.id > lastNotificationId) {
                            lastNotificationId = notif.id;
                        }
                    });
                }
            },
            error: function() {
                // Тихая ошибка
            }
        });
    }

    // === Функция отметки прочитанного ===
    function markNotificationRead(notificationId) {
        $.ajax({
            url: '/api/get_notifications.php',
            data: {
                action: 'mark_read',
                id: notificationId
            },
            method: 'GET',
            // Не ждём ответа, чтобы не тормозить интерфейс
        });
    }

    // === Показ тоста для сообщения (чат) ===
    function showToast(msg) {
        // Если пользователь уже смотрит именно в этот чат — тост не нужен,
        // сообщение и так подтянется в саму ленту локальным поллингом chat.php
        if (window.currentChatApplicationId &&
            Number(msg.application_id) === Number(window.currentChatApplicationId)) {
            return;
        }

        var initials = msg.sender_name.split(' ').map(function(word) {
            return word.charAt(0).toUpperCase();
        }).join('').slice(0, 2);

        var toast = $('<div class="toast-notification">' +
            '<div class="toast-header">' +
                '<div class="toast-avatar">' + initials + '</div>' +
                '<div class="toast-sender">' +
                    '<strong>' + escapeHtml(msg.sender_name) + '</strong>' +
                    '<a href="/pages/application_chat.php?application_id=' + msg.application_id + '" class="toast-location">' + escapeHtml(msg.location_title) + '</a>' +
                '</div>' +
                '<span class="toast-time">' + getTimeAgo(msg.created_at) + '</span>' +
            '</div>' +
            '<div class="toast-body">' + escapeHtml(msg.message) + '</div>' +
            '<button class="toast-close">&times;</button>' +
            '</div>');

        toast.on('click', function(e) {
            if ($(e.target).closest('.toast-close').length) return;
            window.location.href = '/pages/application_chat.php?application_id=' + msg.application_id;
        });

        toast.find('.toast-close').on('click', function(e) {
            e.stopPropagation();
            toast.remove();
        });

        container.prepend(toast);
        requestAnimationFrame(function() {
            toast.addClass('visible');
        });

        setTimeout(function() {
            toast.removeClass('visible');
            setTimeout(function() { toast.remove(); }, 400);
        }, 10000);
    }

    // === Показ тоста для системного уведомления ===
    function showToastNotification(notif) {
        var toast = $('<div class="toast-notification notification-toast">' +
            '<div class="toast-header">' +
                '<div class="toast-icon">🔔</div>' +
                '<div class="toast-sender">' +
                    '<strong>Системное уведомление</strong>' +
                '</div>' +
                '<span class="toast-time">' + getTimeAgo(notif.created_at) + '</span>' +
            '</div>' +
            '<div class="toast-body">' + escapeHtml(notif.message) + '</div>' +
            '<button class="toast-close">&times;</button>' +
            '</div>');

        if (notif.link) {
            toast.on('click', function(e) {
                if ($(e.target).closest('.toast-close').length) return;
                window.location.href = notif.link;
            });
        }

        toast.find('.toast-close').on('click', function(e) {
            e.stopPropagation();
            toast.remove();
        });

        container.prepend(toast);
        requestAnimationFrame(function() {
            toast.addClass('visible');
        });

        setTimeout(function() {
            toast.removeClass('visible');
            setTimeout(function() { toast.remove(); }, 400);
        }, 10000);
    }

    // === Вспомогательные функции ===
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getTimeAgo(timestamp) {
        var then;
        if (typeof timestamp === 'string') {
            then = new Date(timestamp);
        } else {
            then = new Date(timestamp * 1000);
        }
        var now = new Date();
        var diff = Math.floor((now - then) / 60000);
        if (diff < 1) return 'Только что';
        if (diff < 60) return diff + ' мин';
        if (diff < 1440) return Math.floor(diff / 60) + ' ч';
        return then.toLocaleDateString('ru-RU', {day: 'numeric', month: 'short'});
    }

    // === Глобальное управление уведомлениями ===
    window.toggleGlobalNotifications = function(state) {
        notificationsEnabled = state;
        if (state) {
            lastCheck = Math.floor(Date.now() / 1000);
            checkNewMessages();
            checkNewNotifications();
        }
    };

    // === Запуск polling ===
    checkNewMessages();
    checkNewNotifications();

    // Проверка уведомлений каждые 15 секунд (можно уменьшить)
    setInterval(function() {
        checkNewNotifications();
    }, 15000);
});