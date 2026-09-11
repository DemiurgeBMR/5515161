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

// Помечаем все уведомления как прочитанные
$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$stmt->execute([$user_id]);

// Получаем все уведомления
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Уведомления — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .notifications-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .notification-item {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            color: var(--text, #f2f2f5);
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            border-left: 4px solid #e94560;
        }
        .notification-item.read { border-left-color: var(--border-strong, #3a3a45); opacity: 0.7; }
        .notification-item .time { color: var(--text-faint, #6f6f7a); font-size: 12px; }
        .notification-item .message { font-size: 15px; }
        .notification-item a { color: #e94560; text-decoration: none; }
        .empty { text-align: center; color: var(--text-muted, #9a9aa5); padding: 40px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="notifications-container">
    <h2>🔔 Уведомления</h2>
    <?php if (count($notifications) > 0): ?>
        <?php foreach ($notifications as $n): ?>
            <div class="notification-item <?php echo $n['is_read'] ? 'read' : ''; ?>">
                <div class="message">
                    <?php echo nl2br(htmlspecialchars($n['message'])); ?>
                    <?php if ($n['link']): ?>
                        <a href="<?php echo htmlspecialchars($n['link']); ?>">→ Перейти</a>
                    <?php endif; ?>
                </div>
                <div class="time"><?php echo date('d.m.Y H:i', strtotime($n['created_at'])); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">У вас нет уведомлений.</div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>