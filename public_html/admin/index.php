<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
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

// Превью главного фото для карточек на телефоне (на десктопе колонка скрыта).
$thumbs = [];
if ($pending) {
    $thumbIds = array_column($pending, 'id');
    $thumbStmt = $pdo->prepare("
        SELECT location_id, photo_path FROM location_photos
        WHERE location_id IN (" . implode(',', array_fill(0, count($thumbIds), '?')) . ") AND is_pending = 0
        ORDER BY location_id, is_main DESC, sort_order ASC, id ASC
    ");
    $thumbStmt->execute($thumbIds);
    foreach ($thumbStmt->fetchAll() as $t) {
        if (!isset($thumbs[$t['location_id']])) $thumbs[$t['location_id']] = $t['photo_path'];
    }
}

// Всего локаций
$total_all = $pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
$total_active = $pdo->query("SELECT COUNT(*) FROM locations WHERE is_active = 1 AND is_moderated = 1")->fetchColumn();
$total_pending = $pdo->query("SELECT COUNT(DISTINCT location_id) FROM location_revisions WHERE status = 'pending'")->fetchColumn();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Админ-панель — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    
    <div class="admin-container">
        <h1><?php echo rr_icon('shield'); ?> <span class="m-hide">Админ-панель</span><span class="m-only">Модерация</span></h1>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>
        
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
        
        <h2><?php echo rr_icon('clock'); ?> Локации, ожидающие модерации</h2>
        
        <?php if (count($pending) > 0): ?>
            <div class="admin-table">
                <table class="adm-cards adm-cards-loc">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Город</th>
                            <th>Цена</th>
                            <th>Собственник</th>
                            <th>Правок</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $loc): ?>
                            <tr>
                                <td class="c-photo m-only"><?php if (!empty($thumbs[$loc['id']])): ?><img src="/<?php echo htmlspecialchars($thumbs[$loc['id']]); ?>" alt="" loading="lazy"><?php else: ?><span class="c-thumb-empty"><?php echo rr_icon('camera'); ?></span><?php endif; ?></td>
                                <td class="c-id" data-label="ID"><?php echo $loc['id']; ?></td>
                                <td class="c-title" data-label="Название"><?php echo htmlspecialchars($loc['title']); ?></td>
                                <td class="c-city" data-label="Город"><?php echo htmlspecialchars($loc['city']); ?></td>
                                <td class="c-price" data-label="Цена"><?php echo number_format($loc['price_month'], 0, ',', ' '); ?> ₽</td>
                                <td class="c-owner" data-label="Собственник"><?php echo htmlspecialchars($loc['owner_name']); ?></td>
                                <td class="c-badge" data-label="Правок"><?php echo $loc['pending_revisions']; ?></td>
                                <td class="actions c-act" data-label="Действия">
                                    <!-- Просмотр всех ревизий -->
                                    <a href="/admin/view_revisions.php?id=<?php echo $loc['id']; ?>" class="btn-view act-main"><?php echo rr_icon('list'); ?> Правки</a>
                                    <!-- Одобрить все правки (применяет последнюю) -->
                                    <a href="/admin/actions.php?action=approve_pending&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" data-rr-confirm="Одобрить все правки?" data-rr-confirm-ok="Одобрить"><?php echo rr_icon('check'); ?> <span>Одобрить<span class="m-only">&nbsp;все</span></span></a>
                                    <!-- Отклонить все правки -->
                                    <a href="/admin/actions.php?action=reject_pending&id=<?php echo $loc['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" data-rr-confirm="Отклонить все правки?" data-rr-confirm-ok="Отклонить"><?php echo rr_icon('x'); ?> <span>Отклонить<span class="m-only">&nbsp;все</span></span></a>
                                    <!-- Просмотр на сайте -->
                                    <a href="/pages/location.php?id=<?php echo $loc['id']; ?>" target="_blank" class="btn-view act-eye" aria-label="Открыть на сайте"><?php echo rr_icon('eye'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-pending">
                <h3><?php echo rr_icon('check'); ?> Всё чисто!</h3>
                <p>Нет локаций, ожидающих модерации.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>