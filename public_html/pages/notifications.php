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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Уведомления — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="notifications-page">
    <div class="notif-page-head">
        <h2>🔔 Уведомления</h2>
        <?php if ($unreadTotal > 0): ?>
            <a href="?mark_all=1&csrf=<?php echo urlencode(csrf_token()); ?><?php echo $category !== '' ? '&category=' . urlencode($category) : ''; ?>" class="notif-mark-all">Прочитать всё (<?php echo $unreadTotal; ?>)</a>
        <?php endif; ?>
    </div>

    <div class="notif-tabs">
        <a href="/pages/notifications.php" class="<?php echo $category === '' ? 'active' : ''; ?>">Все</a>
        <?php foreach (NOTIFICATION_CATEGORIES as $catKey => $catMeta): ?>
            <a href="?category=<?php echo urlencode($catKey); ?>" class="<?php echo $category === $catKey ? 'active' : ''; ?>">
                <?php echo $catMeta['icon']; ?> <?php echo htmlspecialchars($catMeta['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($notifications) === 0): ?>
        <div class="notif-empty">У вас пока нет уведомлений<?php echo $category !== '' ? ' в этой категории' : ''; ?>.</div>
    <?php else: ?>
        <div class="notif-list" id="notifList">
            <?php $lastDay = null; foreach ($notifications as $n): ?>
                <?php $day = notifDayLabel($n['created_at']); ?>
                <?php if ($day !== $lastDay): $lastDay = $day; ?>
                    <div class="notif-day-divider"><?php echo htmlspecialchars($day); ?></div>
                <?php endif; ?>
                <?php
                    $meta = NOTIFICATION_META[$n['type']] ?? ['icon' => 'ℹ️'];
                    $isUnread = $n['read_at'] === null;
                ?>
                <div class="notif-item<?php echo $isUnread ? ' unread' : ''; ?>"
                     data-id="<?php echo $n['id']; ?>"
                     data-link="<?php echo htmlspecialchars($n['link'] ?? ''); ?>">
                    <span class="notif-item-icon"><?php echo $meta['icon']; ?></span>
                    <div class="notif-item-body">
                        <div class="notif-item-message"><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                        <div class="notif-item-time"><?php echo date('d.m.Y H:i', strtotime($n['created_at'])); ?></div>
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
            fetch('/api/get_notifications.php?action=mark_read&id=' + id);
            this.classList.remove('unread');
        }
        if (link) {
            window.location.href = link;
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
