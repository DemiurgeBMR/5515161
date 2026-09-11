<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$user_id = $_SESSION['user_id'];

// ★★★ Изменённый запрос: добавлены поля operator_tag, owner_tag, status, cancelled_by ★★★
$stmt = $pdo->prepare("
    SELECT a.*, l.title as location_title, l.city, u.full_name as operator_name,
           a.operator_tag, a.owner_tag, a.status, a.cancelled_by
    FROM applications a
    JOIN locations l ON a.location_id = l.id
    JOIN users u ON a.operator_id = u.id
    WHERE a.owner_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$user_id]);
$applications = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$statusLabels = [
    'pending'    => '⏳ Ожидает',
    'negotiating' => '🤝 В переговорах',
    'agreed'     => '✅ Договорённость',
    'placed'     => '📍 Размещено',
    'cancelled'  => '❌ Отменена',
    'approved'   => '✅ Закрепление подтверждено',
    'rejected'   => '❌ Закрепление отклонено',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заявки на мои локации — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div style="max-width: 1000px; margin: 40px auto; padding: 0 20px;">
        <a href="/pages/profile.php" class="back-link">← Назад</a>
        <h2>📩 Заявки на мои локации</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if (count($applications) > 0): ?>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Локация</th>
                            <th>Город</th>
                            <th>Оператор</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            // ★★★ Определяем, какой статус показывать этому владельцу ★★★
                            // status хранит и финальные статусы запроса на закрепление
                            // (approved/rejected из api/operator_assign.php), которые
                            // нужно показывать напрямую — иначе такие заявки выглядели
                            // бы вечно "ожидающими".
                            if (in_array($app['status'], ['cancelled', 'approved', 'rejected'], true)) {
                                $displayStatus = $app['status'];
                            } else {
                                // Используем личный тег владельца, если есть, иначе 'pending'
                                $displayStatus = $app['owner_tag'] ? $app['owner_tag'] : 'pending';
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
                                <td><?php echo htmlspecialchars($app['operator_name']); ?></td>
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
            <p style="color: #888;">Пока нет заявок на ваши локации.</p>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>