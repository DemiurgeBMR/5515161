<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

$category = $_GET['category'] ?? '';
if (!isset(NOTIFICATION_CATEGORIES[$category])) {
    $category = '';
}

if (isset($_GET['mark_all']) && csrf_verify($_GET['csrf'] ?? '')) {
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")->execute([$user_id]);
    $redirect = '/pages/notifications.php' . ($category !== '' ? '?category=' . urlencode($category) : '');
    header('Location: ' . $redirect);
    exit;
}

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = 'user_id = ?';
$params = [$user_id];
if ($category !== '') {
    $where .= ' AND category = ?';
    $params[] = $category;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Группировка по дням ("Сегодня" / "Вчера" / дата) для заголовков в списке
function notifDayLabel($dateStr) {
    $date = date('Y-m-d', strtotime($dateStr));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today) return 'Сегодня';
    if ($date === $yesterday) return 'Вчера';
    return formatDateRu($dateStr);
}

$unreadTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
$unreadTotalStmt->execute([$user_id]);
$unreadTotal = (int) $unreadTotalStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Уведомления — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="notifications-page">
    <div class="notif-page-head">
        <h2><?php echo rr_icon('bell'); ?> Уведомления</h2>
        <?php if ($unreadTotal > 0): ?>
            <a href="?mark_all=1&csrf=<?php echo urlencode(csrf_token()); ?><?php echo $category !== '' ? '&category=' . urlencode($category) : ''; ?>" class="notif-mark-all"><span class="m-only notif-m-ic"><?php echo rr_icon('check'); ?></span>Прочитать всё<span class="m-hide"> (<?php echo $unreadTotal; ?>)</span></a>
        <?php endif; ?>
    </div>
    <p class="notif-m-sub m-only"><?php echo $unreadTotal > 0
        ? $unreadTotal . ' ' . rr_plural_ru($unreadTotal, 'непрочитанное', 'непрочитанных', 'непрочитанных')
        : 'Всё прочитано'; ?></p>

    <div class="notif-tabs">
        <a href="/pages/notifications.php" class="<?php echo $category === '' ? 'active' : ''; ?>">Все</a>
        <?php foreach (NOTIFICATION_CATEGORIES as $catKey => $catMeta): ?>
            <a href="?category=<?php echo urlencode($catKey); ?>" class="<?php echo $category === $catKey ? 'active' : ''; ?>">
                <?php echo rr_icon($catMeta['icon']); ?> <?php echo htmlspecialchars($catMeta['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($notifications) === 0): ?>
        <div class="notif-empty m-empty">
            <span class="m-only notif-empty-ic"><?php echo rr_icon('bell'); ?></span>
            <b class="m-only"><?php echo $category !== '' ? 'Здесь пусто' : 'Уведомлений пока нет'; ?></b>
            У вас пока нет уведомлений<?php echo $category !== '' ? ' в этой категории' : ''; ?>.
            <?php if ($category !== ''): ?>
                <a href="/pages/notifications.php" class="m-btn m-btn--soft m-only">Все уведомления</a>
            <?php else: ?>
                <a href="/pages/edit_profile.php" class="m-btn m-btn--ghost m-only">Настроить уведомления</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="notif-list" id="notifList">
            <?php $lastDay = null; foreach ($notifications as $n): ?>
                <?php $day = notifDayLabel($n['created_at']); ?>
                <?php if ($day !== $lastDay): $lastDay = $day; ?>
                    <div class="notif-day-divider"><?php echo htmlspecialchars($day); ?></div>
                <?php endif; ?>
                <?php
                    $meta = NOTIFICATION_META[$n['type']] ?? ['icon' => 'info-circle'];
                    $isUnread = $n['read_at'] === null;
                ?>
                <div class="notif-item<?php echo $isUnread ? ' unread' : ''; ?> notif-cat-<?php echo htmlspecialchars($n['category'] ?? ''); ?> notif-type-<?php echo htmlspecialchars($n['type']); ?>"
                     data-id="<?php echo $n['id']; ?>"
                     data-link="<?php echo htmlspecialchars($n['link'] ?? ''); ?>">
                    <span class="notif-item-icon"><?php echo rr_icon($meta['icon']); ?></span>
                    <div class="notif-item-body">
                        <div class="notif-item-message"><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                        <div class="notif-item-time"><span class="m-hide"><?php echo date('d.m.Y H:i', strtotime($n['created_at'])); ?></span><span class="m-only"><?php echo date('H:i', strtotime($n['created_at'])); ?></span></div>
                    </div>
                    <?php if ($isUnread): ?><span class="notif-item-dot"></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="notif-pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?php echo $p; ?><?php echo $category !== '' ? '&category=' . urlencode($category) : ''; ?>"
                       class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.getElementById('notifList')?.querySelectorAll('.notif-item').forEach(function (el) {
    el.addEventListener('click', function () {
        var id = this.dataset.id;
        var link = this.dataset.link;
        if (this.classList.contains('unread')) {
            // keepalive: запрос «прочитано» не обрывается переходом по ссылке сразу после него
            fetch('/api/get_notifications.php?action=mark_read&id=' + id + '&csrf=' + encodeURIComponent(window.csrfToken), { keepalive: true });
            this.classList.remove('unread');
        }
        if (link) {
            window.location.href = link;
        }
    });
});
// Телефон: активная категория в ленте чипов — в зоне видимости (лента прокручивается по горизонтали)
(function () {
    var tabs = document.querySelector('.notif-tabs');
    var on = tabs && tabs.querySelector('a.active');
    if (!on || !window.matchMedia || !window.matchMedia('(max-width: 768px)').matches) return;
    var x = on.getBoundingClientRect().left - tabs.getBoundingClientRect().left;
    if (x + on.offsetWidth > tabs.clientWidth) tabs.scrollLeft = x - 12;
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
