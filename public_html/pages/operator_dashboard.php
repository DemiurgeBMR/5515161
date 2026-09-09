<?php
session_start();
require_once __DIR__ . '/../config.php';

// Только для операторов
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$user_name = $_SESSION['user_name'];
$user_id = $_SESSION['user_id'];


$pdo = getDbConnection();

// Количество активных заявок (не завершённых и не отменённых)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE operator_id = ? AND status NOT IN ('cancelled', 'placed')");
$stmt->execute([$user_id]);
$bookings_count = $stmt->fetchColumn();

// Количество закреплённых точек (для бейджа в меню)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM location_operators WHERE operator_id = ? AND status = 'active'");
$stmt->execute([$user_id]);
$locations_count = $stmt->fetchColumn();

// Количество размещённых вендингов (реально заведённых карточек машин, не статус чата)
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    WHERE lo.operator_id = ? AND lo.status = 'active' AND m.status != 'removed'
");
$stmt->execute([$user_id]);
$vending_count = $stmt->fetchColumn();

// Количество точек, требующих обслуживания
// (последнее обслуживание либо установка были раньше порога SERVICE_DUE_DAYS)
$cutoff = serviceDueCutoffDate();
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM location_machines m
    JOIN location_operators lo ON lo.id = m.location_operator_id
    WHERE lo.operator_id = ?
      AND lo.status = 'active'
      AND m.status != 'removed'
      AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
      AND COALESCE(m.last_service_at, m.installed_at) < ?
");
$stmt->execute([$user_id, $cutoff]);
$maintenance_due_count = $stmt->fetchColumn();


?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Кабинет арендатора — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .dashboard-wrapper {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 30px;
        }
        .dashboard-sidebar {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
            align-self: start;
        }
        .dashboard-sidebar .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e94560;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            color: white;
            margin: 0 auto 12px;
        }
        .dashboard-sidebar .user-name {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .dashboard-sidebar .user-role {
            text-align: center;
            color: var(--text-muted, #9a9aa5);
            font-size: 14px;
            margin-bottom: 20px;
        }
        .dashboard-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .dashboard-nav a {
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text, #f2f2f5);
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-nav a:hover {
            background: var(--bg-elevated-2, #1c1c24);
        }
        .dashboard-nav a.active {
            background: #e94560;
            color: white;
        }
        .dashboard-nav a i {
            font-style: normal;
            width: 24px;
            text-align: center;
        }
        .dashboard-main {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }
        .dashboard-main h2 {
            margin-top: 0;
            margin-bottom: 16px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--bg-elevated-2, #1c1c24);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: var(--text, #f2f2f5);
        }
        .stat-card .label {
            font-size: 14px;
            color: var(--text-muted, #9a9aa5);
        }
        .stat-card-warning {
            background: rgba(231, 76, 60, 0.15);
        }
        .stat-card-warning .number {
            color: #ff6b6b;
        }
        .welcome-text {
            font-size: 18px;
            margin-bottom: 20px;
        }
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .quick-actions .btn {
            padding: 12px 24px;
            border-radius: 8px;
            background: #e94560;
            color: white;
            text-decoration: none;
            font-weight: bold;
        }
        .quick-actions .btn:hover {
            background: #c73652;
        }
        .quick-actions .btn-outline {
            background: transparent;
            color: #e94560;
            border: 2px solid #e94560;
        }
        .quick-actions .btn-outline:hover {
            background: #e94560;
            color: white;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="dashboard-wrapper">
        <aside class="dashboard-sidebar">
            <div class="avatar"><?php echo mb_strtoupper(mb_substr($user_name, 0, 1, 'UTF-8')); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="user-role">🤝 Арендатор</div>

            <nav class="dashboard-nav">
                <a href="/pages/operator_dashboard.php" class="active">
                    <i>📊</i> Дашборд
                </a>
                <a href="/pages/operator_applications.php">
                    <i>📋</i> Мои заявки <span class="badge"><?php echo $bookings_count; ?></span>
                </a>
                <a href="/pages/operator_locations.php">
                    <i>📍</i> Мои точки <span class="badge"><?php echo $locations_count; ?></span>
                </a>
                <a href="/pages/events_calendar.php">
                    <i>📅</i> Выезды
                </a>
                <a href="/pages/documents.php">
                    <i>📄</i> Документы
                </a>
                <a href="/pages/edit_profile.php">
                    <i>⚙️</i> Настройки
                </a>
                <a href="/pages/logout.php" style="color: #e74c3c; margin-top: 12px;">
                    <i>🚪</i> Выйти
                </a>
            </nav>
        </aside>

        <main class="dashboard-main">
            <div class="welcome-text">
                👋 Добро пожаловать, <strong><?php echo htmlspecialchars($user_name); ?></strong>!
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?php echo $bookings_count; ?></div>
                    <div class="label">Активных заявок</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo $vending_count; ?></div>
                    <div class="label">Размещено вендингов</div>
                </div>
                <div class="stat-card<?php echo $maintenance_due_count > 0 ? ' stat-card-warning' : ''; ?>">
                    <div class="number"><?php echo $maintenance_due_count; ?></div>
                    <div class="label">⚠️ Требуют обслуживания</div>
                </div>
            </div>

            <h3>🚀 Быстрые действия</h3>
            <div class="quick-actions">
                <a href="/pages/catalog.php" class="btn">🔍 Найти локации</a>
            </div>

            <?php if ($maintenance_due_count > 0): ?>
                <hr style="margin: 30px 0; border: none; border-top: 1px solid var(--border, #2a2a33);">
                <div style="background: rgba(231, 76, 60, 0.15); color: var(--text, #f2f2f5); border-radius: 12px; padding: 16px 20px;">
                    <strong>⚠️ У вас <?php echo $maintenance_due_count; ?> <?php echo ($maintenance_due_count === 1) ? 'точка требует' : 'точек требуют'; ?> внимания.</strong>
                    <p style="margin: 6px 0 0; color: var(--text-muted, #9a9aa5);">
                        Загляните в <a href="/pages/operator_locations.php" style="color: #e94560;">Мои точки</a>, чтобы отметить обслуживание.
                    </p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>