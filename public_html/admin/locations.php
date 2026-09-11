<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();

$filter = $_GET['filter'] ?? 'all'; // all, active, pending, hidden

// Основной запрос с подзапросом для подсчёта pending ревизий
$sql = "
    SELECT l.*, u.full_name as owner_name,
           (SELECT COUNT(*) FROM location_revisions WHERE location_id = l.id AND status = 'pending') as pending_revisions
    FROM locations l
    JOIN users u ON l.owner_id = u.id
";

$where = [];
if ($filter === 'active') {
    $where[] = "l.is_active = 1 AND l.is_moderated = 1";
} elseif ($filter === 'pending') {
    // Показываем локации, у которых есть хотя бы одна ожидающая ревизия
    $where[] = "EXISTS (SELECT 1 FROM location_revisions WHERE location_id = l.id AND status = 'pending')";
} elseif ($filter === 'hidden') {
    $where[] = "l.is_active = 0";
}
// all — без условий

if (count($where) > 0) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY l.created_at DESC";
$locations = $pdo->query($sql)->fetchAll();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Все локации — админка</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="admin-container">
        <h1>📍 Все локации</h1>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="nav-admin">
            <a href="/admin/index.php">📋 На модерацию</a>
            <a href="/admin/locations.php">📍 Все локации</a>
            <a href="/admin/geocode_backfill.php">🌍 Геокодирование</a>
        </div>
        
        <div class="filters">
            <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Все</a>
            <a href="?filter=active" class="<?php echo $filter === 'active' ? 'active' : ''; ?>">Активные</a>
            <a href="?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">На модерации</a>
            <a href="?filter=hidden" class="<?php echo $filter === 'hidden' ? 'active' : ''; ?>">Скрытые</a>
        </div>
        
        <div class="admin-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Город</th>
                        <th>Цена</th>
                        <th>Владелец</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $loc): ?>
                        <tr>
                            <td><?php echo $loc['id']; ?></td>
                            <td><?php echo htmlspecialchars($loc['title']); ?></td>
                            <td><?php echo htmlspecialchars($loc['city']); ?></td>
                            <td><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽</td>
                            <td><?php echo htmlspecialchars($loc['owner_name']); ?></td>
                            <td>
                                <?php if ($loc['pending_revisions'] > 0): ?>
                                    <span class="status pending">⏳ Ожидает правок</span>
                                <?php elseif ($loc['is_moderated'] == 0): ?>
                                    <span class="status pending">⏳ Новая (не одобрена)</span>
                                <?php elseif ($loc['is_active'] == 1): ?>
                                    <span class="status active">✅ Активна</span>
                                <?php else: ?>
                                    <span class="status hidden">🚫 Скрыта</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <?php if ($loc['pending_revisions'] > 0): ?>
                                    <!-- Есть ожидающие правки -->
                                    <a href="/admin/view_revisions.php?id=<?php echo $loc['id']; ?>" class="btn-view">📋 Правки</a>
                                    <a href="/admin/actions.php?action=approve_pending&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" onclick="return confirm('Одобрить все правки?')">✅ Одобрить</a>
                                    <a href="/admin/actions.php?action=reject_pending&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" onclick="return confirm('Отклонить все правки?')">❌ Отклонить</a>
                                <?php elseif ($loc['is_moderated'] == 0): ?>
                                    <!-- Новая локация без ревизий (редко) – можно удалить -->
                                    <a href="/admin/actions.php?action=delete&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" onclick="return confirm('Удалить локацию?')">🗑️ Удалить</a>
                                <?php else: ?>
                                    <!-- Уже опубликованная -->
                                    <?php if ($loc['is_active'] == 1): ?>
                                        <a href="/admin/actions.php?action=hide&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-hide" onclick="return confirm('Скрыть локацию?')">🔒 Скрыть</a>
                                    <?php else: ?>
                                        <a href="/admin/actions.php?action=show&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" onclick="return confirm('Показать локацию?')">🔓 Показать</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" target="_blank" class="btn-view">👁️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>