<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($order_id <= 0) {
    header('Location: /pages/subscription.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];
$isAdmin = $_SESSION['user_role'] === 'admin';

$stmt = $pdo->prepare("
    SELECT so.*, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone
    FROM service_orders so
    JOIN users u ON u.id = so.user_id
    WHERE so.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

// Доступ — только сам заказчик или любой админ. У заказов нет персональной
// очереди на конкретного сотрудника, любой админ может открыть и ответить.
if (!$order || (!$isAdmin && $order['user_id'] != $user_id)) {
    header('Location: /pages/subscription.php');
    exit;
}

$serviceLabels = ['turnkey_deal' => 'Сделка под ключ'];
$statusLabels = rr_service_order_status_labels();

// Смена статуса — только админ, прямо отсюда, чтобы не переключаться между
// admin/service_orders.php и чатом туда-обратно.
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /pages/service_order_chat.php?order_id=' . $order_id);
        exit;
    }
    if (rr_update_service_order_status($pdo, $order_id, $_POST['status'] ?? '', $user_id)) {
        $order['status'] = $_POST['status'];
    }
    header('Location: /pages/service_order_chat.php?order_id=' . $order_id);
    exit;
}

$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name
    FROM service_order_messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.service_order_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$order_id]);
$messages = $stmt->fetchAll();

notify_mark_link_read($pdo, $user_id, '/pages/service_order_chat.php?order_id=' . $order_id);

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

function formatDateSeparator($dateStr) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($dateStr === $today) return 'Сегодня';
    if ($dateStr === $yesterday) return 'Вчера';
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $ts = strtotime($dateStr);
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function renderChatAttachment($msg) {
    if (empty($msg['attachment_type'])) {
        return '';
    }
    $url = '/api/download_service_order_attachment.php?message_id=' . $msg['id'];
    if ($msg['attachment_type'] === 'image') {
        return '<a href="' . htmlspecialchars($url) . '" target="_blank" class="chat-attachment chat-attachment-image">'
            . '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($msg['attachment_name']) . '" loading="lazy">'
            . '</a>';
    }
    return '<a href="' . htmlspecialchars($url) . '" class="chat-attachment chat-attachment-document">'
        . '<span class="chat-attachment-icon">' . rr_icon('file-text') . '</span>'
        . '<span class="chat-attachment-info">'
        .     '<span class="chat-attachment-name">' . htmlspecialchars($msg['attachment_name']) . '</span>'
        .     '<span class="chat-attachment-size">' . htmlspecialchars(rr_format_bytes((int) $msg['attachment_size'])) . '</span>'
        . '</span>'
        . '<span class="chat-attachment-download-icon">' . rr_icon('download') . '</span>'
        . '</a>';
}

$backUrl = $isAdmin ? '/admin/service_orders.php' : '/pages/subscription.php';
$otherPartyLabel = $isAdmin ? $order['customer_name'] : 'Команда RR';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чат по заявке — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="chat-container">
    <a href="<?php echo $backUrl; ?>" class="chat-back-link">← Назад</a>

    <div class="chat-card">
        <div class="chat-main">
            <div class="chat-main-topbar service-order-topbar">
                <div class="who">
                    <div class="party-avatar"><?php echo htmlspecialchars(getInitials($otherPartyLabel)); ?></div>
                    <div class="name">
                        <?php echo htmlspecialchars($serviceLabels[$order['service']] ?? $order['service']); ?>
                        <span class="service-order-price"><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</span>
                    </div>
                </div>
                <?php if ($isAdmin): ?>
                    <form method="POST" class="service-order-status-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="change_status" value="1">
                        <select name="status" onchange="this.form.submit()">
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $order['status'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                        <?php echo htmlspecialchars($statusLabels[$order['status']] ?? $order['status']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <div class="service-order-customer-info">
                    <?php echo htmlspecialchars($order['customer_name']); ?> ·
                    <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></a>
                    <?php if (!empty($order['customer_phone'])): ?>
                        · <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="chat-messages" id="chatMessages">
                <?php if (count($messages) > 0): ?>
                    <?php $prevSenderId = null; $prevDate = null; ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php
                            $isOwn = ($msg['sender_id'] == $user_id);
                            $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                            $showDateSeparator = ($msgDate !== $prevDate);
                            $isGrouped = (!$showDateSeparator && $msg['sender_id'] == $prevSenderId && !$msg['is_system']);
                            $prevSenderId = $msg['sender_id'];
                            $prevDate = $msgDate;
                        ?>
                        <?php if ($showDateSeparator): ?>
                            <div class="date-separator"><span><?php echo formatDateSeparator($msgDate); ?></span></div>
                        <?php endif; ?>
                        <?php if ($msg['is_system']): ?>
                            <div class="date-separator"><span><?php echo htmlspecialchars($msg['message']); ?></span></div>
                        <?php else: ?>
                            <div class="message <?php echo $isOwn ? 'own' : ''; ?> <?php echo $isGrouped ? 'grouped' : ''; ?>">
                                <?php if (!$isOwn): ?>
                                    <div class="avatar<?php echo $isGrouped ? ' avatar-hidden' : ''; ?>"><?php echo htmlspecialchars(getInitials($msg['sender_name'])); ?></div>
                                <?php endif; ?>
                                <div class="message-body">
                                    <?php if (!$isGrouped): ?>
                                        <div class="sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <span class="time"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php echo renderChatAttachment($msg); ?>
                                    <?php if ($msg['message'] !== ''): ?>
                                        <div class="text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="chat-empty"><span class="chat-empty-icon"><?php echo rr_icon('message-circle'); ?></span>Сообщений пока нет. Напишите, если есть вопросы по заявке.</div>
                <?php endif; ?>
            </div>

            <div class="chat-input">
                <?php if ($order['status'] !== 'cancelled'): ?>
                    <div id="attachmentPreview" class="attachment-preview" hidden>
                        <span class="attachment-preview-icon"><?php echo rr_icon('paperclip'); ?></span>
                        <span id="attachmentPreviewName" class="attachment-preview-name"></span>
                        <button type="button" id="attachmentPreviewRemove" class="attachment-preview-remove" aria-label="Убрать файл"><?php echo rr_icon('x'); ?></button>
                    </div>
                    <form id="chatForm">
                        <label id="attachButtonLabel" class="chat-attach-btn" title="Прикрепить фото или документ">
                            <?php echo rr_icon('paperclip'); ?>
                            <input type="file" name="attachment" id="attachmentInput" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx" hidden>
                        </label>
                        <textarea name="message" placeholder="Напишите сообщение..." rows="1"></textarea>
                        <button type="submit" aria-label="Отправить">➤</button>
                    </form>
                <?php else: ?>
                    <div class="chat-closed">Заявка отменена, чат закрыт.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var orderId = <?php echo (int) $order_id; ?>;
    var userId = <?php echo (int) $user_id; ?>;
    var chatMessages = document.getElementById('chatMessages');
    var lastMessageId = <?php echo count($messages) > 0 ? (int) end($messages)['id'] : 0; ?>;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatBytes(bytes) {
        if (bytes >= 1024 * 1024) return (Math.round(bytes / 1024 / 1024 * 10) / 10) + ' МБ';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' КБ';
        return bytes + ' Б';
    }

    function attachmentHtml(msg) {
        if (!msg.attachment_url) return '';
        if (msg.attachment_type === 'image') {
            return '<a href="' + msg.attachment_url + '" target="_blank" class="chat-attachment chat-attachment-image">' +
                '<img src="' + msg.attachment_url + '" alt="' + escapeHtml(msg.attachment_name || '') + '" loading="lazy"></a>';
        }
        return '<a href="' + msg.attachment_url + '" class="chat-attachment chat-attachment-document">' +
            '<span class="chat-attachment-icon">' + PAPERCLIP_FILE_ICON + '</span>' +
            '<span class="chat-attachment-info">' +
                '<span class="chat-attachment-name">' + escapeHtml(msg.attachment_name || '') + '</span>' +
                '<span class="chat-attachment-size">' + formatBytes(msg.attachment_size || 0) + '</span>' +
            '</span>' +
            '<span class="chat-attachment-download-icon">' + DOWNLOAD_ICON + '</span></a>';
    }

    function appendMessage(msg, isOwn) {
        if (msg.is_system) {
            var sep = document.createElement('div');
            sep.className = 'date-separator';
            sep.innerHTML = '<span>' + escapeHtml(msg.message) + '</span>';
            chatMessages.appendChild(sep);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return;
        }
        var date = new Date(msg.created_at * 1000);
        var time = date.toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        var div = document.createElement('div');
        div.className = 'message' + (isOwn ? ' own' : '');
        var avatarHtml = isOwn ? '' : '<div class="avatar">' + escapeHtml(msg.sender_name.slice(0, 1).toUpperCase()) + '</div>';
        var textHtml = msg.message ? '<div class="text">' + escapeHtml(msg.message) + '</div>' : '';
        div.innerHTML = avatarHtml +
            '<div class="message-body">' +
                '<div class="sender">' + escapeHtml(msg.sender_name) + ' <span class="time">' + time + '</span></div>' +
                attachmentHtml(msg) +
                textHtml +
            '</div>';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    var PAPERCLIP_FILE_ICON = '<?php echo addslashes(rr_icon("file-text")); ?>';
    var DOWNLOAD_ICON = '<?php echo addslashes(rr_icon("download")); ?>';

    var form = document.getElementById('chatForm');
    var textarea = form ? form.querySelector('textarea[name="message"]') : null;
    var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    var attachmentInput = document.getElementById('attachmentInput');
    var attachmentPreview = document.getElementById('attachmentPreview');
    var attachmentPreviewName = document.getElementById('attachmentPreviewName');
    var attachmentPreviewRemove = document.getElementById('attachmentPreviewRemove');
    var attachButtonLabel = document.getElementById('attachButtonLabel');

    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 140) + 'px';
        });
    }

    // К сообщению можно прикрепить не больше одного файла — поэтому кнопка
    // "скрепка" и полоса "выбран файл X" никогда не показываются одновременно:
    // либо ещё нечего убирать (видна скрепка), либо уже есть что убрать
    // (видна полоса с крестиком, скрепка спрятана). Раньше обе были видны
    // сразу, что и выглядело нелогично — а сам крестик к тому же не прятал
    // полосу из-за отдельной CSS-специфичности бага.
    function clearAttachment() {
        attachmentInput.value = '';
        attachmentPreview.hidden = true;
        attachButtonLabel.hidden = false;
    }
    function setAttachment(file) {
        attachmentPreviewName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
        attachmentPreview.hidden = false;
        attachButtonLabel.hidden = true;
    }

    if (attachmentInput) {
        attachmentInput.addEventListener('change', function() {
            var file = attachmentInput.files[0];
            if (!file) {
                clearAttachment();
                return;
            }
            if (file.size > <?php echo rr_service_order_attachment_max_bytes(); ?>) {
                showToast('Файл больше <?php echo round(rr_service_order_attachment_max_bytes() / 1024 / 1024); ?> МБ', 'error');
                clearAttachment();
                return;
            }
            setAttachment(file);
        });
    }
    if (attachmentPreviewRemove) {
        attachmentPreviewRemove.addEventListener('click', clearAttachment);
    }

    if (form && textarea && submitBtn) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var message = textarea.value.trim();
            var file = attachmentInput && attachmentInput.files[0];
            if (!message && !file) {
                showToast('Введите сообщение или прикрепите файл', 'error');
                textarea.focus();
                return;
            }
            submitBtn.disabled = true;
            var formData = new FormData();
            formData.append('order_id', orderId);
            formData.append('message', message);
            formData.append('csrf_token', '<?php echo csrf_token(); ?>');
            if (file) {
                formData.append('attachment', file);
            }
            fetch('/api/send_service_order_message.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        appendMessage(data.message, true);
                        textarea.value = '';
                        textarea.style.height = 'auto';
                        clearAttachment();
                        lastMessageId = data.message.id;
                    } else {
                        showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                })
                .catch(function() { showToast('Ошибка соединения с сервером', 'error'); })
                .finally(function() { submitBtn.disabled = false; });
        });
    }

    function checkNewMessages() {
        fetch('/api/get_service_order_messages.php?order_id=' + orderId + '&last_id=' + lastMessageId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.messages && data.messages.length > 0) {
                    data.messages.forEach(function(msg) {
                        appendMessage(msg, msg.sender_id == userId);
                        lastMessageId = msg.id;
                    });
                }
            })
            .catch(function() {});
    }
    setInterval(checkNewMessages, 5000);
    setTimeout(checkNewMessages, 1000);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
