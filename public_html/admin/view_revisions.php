<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($location_id <= 0) {
    header('Location: /admin/index.php');
    exit;
}

$pdo = getDbConnection();

// Проверяем, существует ли локация
$stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
$stmt->execute([$location_id]);
$location = $stmt->fetch();
if (!$location) {
    header('Location: /admin/index.php');
    exit;
}

// Получаем все ревизии для этой локации, отсортированные по дате (сначала новые)
$stmt = $pdo->prepare("SELECT * FROM location_revisions WHERE location_id = ? ORDER BY created_at DESC");
$stmt->execute([$location_id]);
$revisions = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ревизии локации — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="revisions-container">
        <a href="/admin/index.php" class="btn-back">← Назад в админ-панель</a>
        
        <div class="revisions-card">
            <h2>📋 Ревизии объявления #<?php echo $location['id']; ?></h2>
            <p style="color: #888; margin-bottom: 20px;">
                Объявление: <strong><?php echo htmlspecialchars($location['title']); ?></strong>
            </p>
            
            <?php if (count($revisions) > 0): ?>
                <table class="revisions-table">
                    <thead>
                        <tr>
                            <th>ID ревизии</th>
                            <th>Дата создания</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revisions as $rev): ?>
                            <tr>
                                <td>#<?php echo $rev['id']; ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($rev['created_at'])); ?></td>
                                <td>
                                    <?php if ($rev['status'] === 'pending'): ?>
                                        <span class="status-badge status-pending">⏳ Ожидает</span>
                                    <?php elseif ($rev['status'] === 'approved'): ?>
                                        <span class="status-badge status-approved">✅ Одобрена</span>
                                    <?php else: ?>
                                        <span class="status-badge status-rejected">❌ Отклонена</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rev['status'] === 'pending'): ?>
                                        <a href="/admin/preview_revision.php?revision_id=<?php echo $rev['id']; ?>" class="btn-view">👁️ Просмотр</a>
                                    <?php else: ?>
                                        <span style="color:#888;">Просмотр</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">
                    <p>Нет ревизий для этого объявления.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>