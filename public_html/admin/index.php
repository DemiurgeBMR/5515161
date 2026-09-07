<?php
session_start();
require_once __DIR__ . '/../config.php';

// Проверка: только админ
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();

// ★★★ НОВЫЙ ЗАПРОС – только локации с ожидающими ревизиями ★★★
$stmt = $pdo->query("
    SELECT DISTINCT l.*, u.full_name as owner_name,
           (SELECT COUNT(*) FROM location_revisions WHERE location_id = l.id AND status = 'pending') as pending_revisions
    FROM locations l
    JOIN users u ON l.owner_id = u.id
    WHERE EXISTS (SELECT 1 FROM location_revisions WHERE location_id = l.id AND status = 'pending')
    ORDER BY l.created_at DESC
");
$pending = $stmt->fetchAll();

// Всего локаций
$total_all = $pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
$total_active = $pdo->query("SELECT COUNT(*) FROM locations WHERE is_active = 1 AND is_moderated = 1")->fetchColumn();
$total_pending = $pdo->query("SELECT COUNT(DISTINCT location_id) FROM location_revisions WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="admin-container">
        <h1>👑 Админ-панель</h1>
        
        <div class="nav-admin">
            <a href="/admin/index.php">📋 На модерацию</a>
            <a href="/admin/locations.php">📍 Все локации</a>
            <a href="/admin/geocode_backfill.php">🌍 Геокодирование</a>
        </div>
        
        <div class="admin-stats">
            <div class="stat-box">
                <div class="number"><?php echo $total_all; ?></div>
                <div class="label">Всего локаций</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $total_active; ?></div>
                <div class="label">Активных</div>
            </div>
            <div class="stat-box pending">
                <div class="number"><?php echo $total_pending; ?></div>
                <div class="label">На модерации</div>
            </div>
        </div>
        
        <h2>📌 Локации, ожидающие модерации</h2>
        
        <?php if (count($pending) > 0): ?>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Город</th>
                            <th>Цена</th>
                            <th>Владелец</th>
                            <th>Правок</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $loc): ?>
                            <tr>
                                <td><?php echo $loc['id']; ?></td>
                                <td><?php echo htmlspecialchars($loc['title']); ?></td>
                                <td><?php echo htmlspecialchars($loc['city']); ?></td>
                                <td><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽</td>
                                <td><?php echo htmlspecialchars($loc['owner_name']); ?></td>
                                <td><?php echo $loc['pending_revisions']; ?></td>
                                <td class="actions">
                                    <!-- Просмотр всех ревизий -->
                                    <a href="/admin/view_revisions.php?id=<?php echo $loc['id']; ?>" class="btn-view">📋 Правки</a>
                                    <!-- Одобрить все правки (применяет последнюю) -->
                                    <a href="/admin/actions.php?action=approve_pending&id=<?php echo $loc['id']; ?>" class="btn-approve" onclick="return confirm('Одобрить все правки?')">✅ Одобрить</a>
                                    <!-- Отклонить все правки -->
                                    <a href="/admin/actions.php?action=reject_pending&id=<?php echo $loc['id']; ?>" class="btn-reject" onclick="return confirm('Отклонить все правки?')">❌ Отклонить</a>
                                    <!-- Просмотр на сайте -->
                                    <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" target="_blank" class="btn-view">👁️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-pending">
                <h3>✅ Всё чисто!</h3>
                <p>Нет локаций, ожидающих модерации.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>