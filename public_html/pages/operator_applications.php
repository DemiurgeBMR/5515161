<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

// ★★★ Изменённый запрос: добавлены поля operator_tag, owner_tag, status, cancelled_by ★★★
$stmt = $pdo->prepare("
    SELECT a.*, l.title as location_title, l.city, u.full_name as owner_name,
           a.operator_tag, a.owner_tag, a.status, a.cancelled_by
    FROM applications a
    JOIN locations l ON a.location_id = l.id
    JOIN users u ON a.owner_id = u.id
    WHERE a.operator_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll();

$statusLabels = [
    'pending'    => '⏳ Ожидает',
    'negotiating' => '🤝 В переговорах',
    'agreed'     => '✅ Договорённость',
    'placed'     => '📍 Размещено',
    'cancelled'  => '❌ Отменена'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мои заявки — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div style="max-width: 1000px; margin: 40px auto; padding: 0 20px;">
        <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
        <h2>📋 Мои заявки на аренду</h2>

        <?php if (count($applications) > 0): ?>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Локация</th>
                            <th>Город</th>
                            <th>Владелец</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): 
                            // ★★★ Определяем, какой статус показывать этому оператору ★★★
                            if ($app['status'] === 'cancelled') {
                                $displayStatus = 'cancelled';
                            } else {
                                // Используем личный тег оператора, если есть, иначе 'pending'
                                $displayStatus = $app['operator_tag'] ? $app['operator_tag'] : 'pending';
                            }
                        ?>
                            <tr>
                                <td>#<?php echo $app['id']; ?></td>
                                <td>
                                    <a href="/pages/location.php?id=<?php echo $app['location_id']; ?>" style="color: #e94560; text-decoration: none;">
                                        <?php echo htmlspecialchars($app['location_title']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($app['city']); ?></td>
                                <td><?php echo htmlspecialchars($app['owner_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $displayStatus; ?>">
                                        <?php echo $statusLabels[$displayStatus] ?? $displayStatus; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($app['created_at'])); ?></td>
                                <td>
                                    <a href="/pages/application_chat.php?application_id=<?php echo $app['id']; ?>" class="btn-view">💬 Чат</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: #888;">Вы ещё не отправляли заявки. <a href="/pages/catalog.php" style="color:#e94560;">Найдите локации</a></p>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>