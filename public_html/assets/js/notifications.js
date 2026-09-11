$(document).ready(function () {
    if (!window.userLoggedIn) return;

    var lastNotificationId = 0;
    var notificationsEnabled = true;

    var container = $('<div class="toast-container"></div>').appendTo('body');
    var badge = document.getElementById('notificationBadge');
    var dropdown = document.getElementById('notificationDropdown');
    var dropdownList = document.getElementById('notificationDropdownList');
    var bellBtn = document.getElementById('notificationBellBtn');

    var CATEGORY_COLORS = {
        chat: '#e94560',
        visits: '#3498db',
        assignment: '#6c5ce7',
        maintenance: '#e67e22',
        moderation: '#2ecc71',
        system: '#9a9aa5'
    };

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function getTimeAgo(dateStr) {
        var then = new Date(dateStr.replace(' ', 'T'));
        var diff = Math.floor((Date.now() - then.getTime()) / 60000);
        if (diff < 1) return 'Только что';
        if (diff < 60) return diff + ' мин';
        if (diff < 1440) return Math.floor(diff / 60) + ' ч';
        return then.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    }

    function updateBadge(count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    function markRead(id) {
        $.get('/api/get_notifications.php', { action: 'mark_read', id: id });
    }

    // === Тост: единый рендер и для чата, и для системных уведомлений ===
    function showToast(notif) {
        // Пользователь уже смотрит именно в этот чат — тост не нужен, сообщение
        // и так подтянется в саму ленту локальным поллингом application_chat.php
        if (notif.category === 'chat' && notif.data && window.currentChatApplicationId &&
            Number(notif.data.application_id) === Number(window.currentChatApplicationId)) {
            markRead(notif.id);
            return;
        }

        var color = CATEGORY_COLORS[notif.category] || CATEGORY_COLORS.system;
        var avatarContent = notif.icon;
        var titleText = 'Уведомление';

        if (notif.category === 'chat' && notif.data && notif.data.sender_name) {
            titleText = escapeHtml(notif.data.sender_name);
            avatarContent = notif.data.sender_name.split(' ').map(function (w) {
                return w.charAt(0).toUpperCase();
            }).join('').slice(0, 2);
        }

        var toast = $(
            '<div class="toast-notification">' +
                '<div class="toast-header">' +
                    '<div class="toast-avatar" style="background:' + color + '">' + avatarContent + '</div>' +
                    '<div class="toast-sender">' +
                        '<strong>' + titleText + '</strong>' +
                        (notif.category === 'chat' && notif.data && notif.data.location_title
                            ? '<span class="toast-location">' + escapeHtml(notif.data.location_title) + '</span>'
                            : '') +
                    '</div>' +
                    '<span class="toast-time">' + getTimeAgo(notif.created_at) + '</span>' +
                '</div>' +
                '<div class="toast-body">' + escapeHtml(notif.message) + '</div>' +
                '<button class="toast-close">&times;</button>' +
            '</div>'
        );

        if (notif.link) {
            toast.on('click', function (e) {
                if ($(e.target).closest('.toast-close').length) return;
                markRead(notif.id);
                window.location.href = notif.link;
            });
        }

        toast.find('.toast-close').on('click', function (e) {
            e.stopPropagation();
            toast.remove();
        });

        container.prepend(toast);
        requestAnimationFrame(function () { toast.addClass('visible'); });

        setTimeout(function () {
            toast.removeClass('visible');
            setTimeout(function () { toast.remove(); }, 400);
        }, 10000);
    }

    // === Единый поллер: бейдж + тосты (заменяет три прежних независимых) ===
    function poll() {
        if (!notificationsEnabled) return;
        $.ajax({
            url: '/api/get_notifications.php',
            data: { action: 'list', last_id: lastNotificationId },
            dataType: 'json',
            success: function (data) {
                if (data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(function (notif) {
                        showToast(notif);
                        if (notif.id > lastNotificationId) {
                            lastNotificationId = notif.id;
                        }
                    });
                }
                if (typeof data.unread_count === 'number') {
                    updateBadge(data.unread_count);
                }
            },
            complete: function () {
                setTimeout(poll, 20000);
            }
        });
    }

    // === Выпадающая панель колокольчика ===
    function renderDropdown(items) {
        if (!dropdownList) return;
        if (!items.length) {
            dropdownList.innerHTML = '<div class="notif-dd-empty">Пока нет уведомлений</div>';
            return;
        }
        dropdownList.innerHTML = items.map(function (n) {
            var color = CATEGORY_COLORS[n.category] || CATEGORY_COLORS.system;
            return '<a href="' + escapeHtml(n.link || '#') + '" class="notif-dd-item' + (n.is_unread ? ' unread' : '') + '" data-id="' + n.id + '">' +
                '<span class="notif-dd-icon" style="background:' + color + '">' + n.icon + '</span>' +
                '<span class="notif-dd-body">' +
                    '<span class="notif-dd-text">' + escapeHtml(n.message) + '</span>' +
                    '<span class="notif-dd-time">' + getTimeAgo(n.created_at) + '</span>' +
                '</span>' +
            '</a>';
        }).join('');

        dropdownList.querySelectorAll('.notif-dd-item').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var id = this.dataset.id;
                if (this.classList.contains('unread')) {
                    markRead(id);
                    this.classList.remove('unread');
                    if (badge && badge.style.display !== 'none') {
                        var current = parseInt(badge.textContent, 10) || 0;
                        updateBadge(Math.max(0, current - 1));
                    }
                }
                if (!this.getAttribute('href') || this.getAttribute('href') === '#') {
                    e.preventDefault();
                }
            });
        });
    }

    function loadDropdown() {
        $.getJSON('/api/get_notifications.php', { action: 'recent', limit: 8 }, function (data) {
            renderDropdown(data.notifications || []);
        });
    }

    if (bellBtn && dropdown) {
        bellBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = dropdown.classList.toggle('open');
            if (isOpen) {
                loadDropdown();
            }
        });
        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target) && e.target !== bellBtn) {
                dropdown.classList.remove('open');
            }
        });
        var markAllBtn = document.getElementById('notifDdMarkAll');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function (e) {
                e.preventDefault();
                $.get('/api/get_notifications.php', { action: 'mark_read' }, function () {
                    updateBadge(0);
                    loadDropdown();
                });
            });
        }
    }

    // === Глобальное управление (мьют текущей вкладки в целом, не заявки) ===
    window.toggleGlobalNotifications = function (state) {
        notificationsEnabled = state;
        if (state) {
            poll();
        }
    };

    poll();
});
