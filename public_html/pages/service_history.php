<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/history_data.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['operator', 'owner'], true)) {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$pdo = getDbConnection();

// Точки для фильтра (все закрепления, не только активные — история должна быть видна и после снятия закрепления)
$roleField = ($role === 'owner') ? 'lo.owner_id' : 'lo.operator_id';
$stmt = $pdo->prepare("
    SELECT DISTINCT l.id, l.title, l.city
    FROM location_operators lo
    JOIN locations l ON l.id = lo.location_id
    WHERE $roleField = ?
    ORDER BY l.city, l.title
");
$stmt->execute([$user_id]);
$myLocations = $stmt->fetchAll();

$filters = [
    'date_from'   => $_GET['date_from'] ?? '',
    'date_to'     => $_GET['date_to'] ?? '',
    'location_id' => $_GET['location_id'] ?? '',
    'event_type'  => $_GET['event_type'] ?? '',
    'source'      => $_GET['source'] ?? '',
];

$rows = getServiceHistory($pdo, $user_id, $role, $filters);
$backLink = ($role === 'operator') ? '/pages/operator_dashboard.php' : '/pages/profile.php';

// Строка запроса для ссылок экспорта (те же фильтры)
$exportQuery = http_build_query(array_filter($filters));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>История обслуживания — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .history-container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: var(--text-muted, #9a9aa5); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .filters-bar {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: var(--text-muted, #9a9aa5); font-weight: 600; }
        .filter-group input, .filter-group select {
            padding: 8px 10px;
            background: var(--bg-input, #0f0f14);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text, #f2f2f5);
        }
        .btn-filter {
            background: #e94560;
            color: white;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            height: 36px;
        }
        .btn-filter:hover { background: #c73652; }

        .export-bar { display: flex; gap: 10px; margin-bottom: 18px; }
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            padding: 9px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text, #f2f2f5);
            font-weight: 600;
            font-size: 13px;
        }
        .btn-export:hover { border-color: #e94560; color: #e94560; }

        .history-table {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            color: var(--text, #f2f2f5);
        }
        .history-table table { width: 100%; border-collapse: collapse; }
        .history-table th {
            background: var(--bg-elevated-2, #1c1c24);
            text-align: left;
            padding: 12px 15px;
            font-weight: 600;
            font-size: 13px;
        }
        .history-table td {
            padding: 12px 15px;
            border-top: 1px solid var(--border, #2a2a33);
            font-size: 14px;
        }
        .source-badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .source-log { background: var(--bg-elevated-2, #1c1c24); color: var(--text-muted, #9a9aa5); }
        .source-event { background: #d4edda; color: #155724; }
        .emergency-tag { color: var(--danger, #e74c3c); font-weight: 700; }
        .empty { text-align: center; padding: 60px 20px; color: var(--text-muted, #9a9aa5); }
        .summary-line { color: var(--text-muted, #9a9aa5); font-size: 13px; margin-bottom: 14px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="history-container">
    <a href="<?php echo $backLink; ?>" class="back-link">← Назад</a>
    <h2>📜 История обслуживания</h2>

    <form class="filters-bar" method="GET">
        <div class="filter-group">
            <label>С даты</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
        </div>
        <div class="filter-group">
            <label>По дату</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
        </div>
        <div class="filter-group">
            <label>Точка</label>
            <select name="location_id">
                <option value="">Все точки</option>
                <?php foreach ($myLocations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo ($filters['location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['title'] . ' (' . $loc['city'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Тип</label>
            <select name="event_type">
                <option value="">Все типы</option>
                <option value="installation" <?php echo $filters['event_type'] === 'installation' ? 'selected' : ''; ?>>Установка</option>
                <option value="maintenance" <?php echo $filters['event_type'] === 'maintenance' ? 'selected' : ''; ?>>Плановое ТО</option>
                <option value="restock" <?php echo $filters['event_type'] === 'restock' ? 'selected' : ''; ?>>Пополнение</option>
                <option value="repair" <?php echo $filters['event_type'] === 'repair' ? 'selected' : ''; ?>>Ремонт</option>
                <option value="removal" <?php echo $filters['event_type'] === 'removal' ? 'selected' : ''; ?>>Демонтаж</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Источник</label>
            <select name="source">
                <option value="">Всё</option>
                <option value="event" <?php echo $filters['source'] === 'event' ? 'selected' : ''; ?>>Согласованные визиты</option>
                <option value="log" <?php echo $filters['source'] === 'log' ? 'selected' : ''; ?>>Постфактум-отметки</option>
            </select>
        </div>
        <button type="submit" class="btn-filter">Применить</button>
    </form>

    <div class="export-bar">
        <a class="btn-export" href="/pages/export_history.php?format=csv&<?php echo $exportQuery; ?>">⬇️ Экспорт CSV (Excel)</a>
        <a class="btn-export" href="/pages/export_history.php?format=pdf&<?php echo $exportQuery; ?>">⬇️ Экспорт PDF</a>
    </div>

    <p class="summary-line">Найдено записей: <?php echo count($rows); ?></p>

    <?php if (count($rows) > 0): ?>
        <div class="history-table">
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Точка</th>
                        <th>Тип</th>
                        <th>Источник</th>
                        <?php if ($role === 'owner'): ?><th>Оператор</th><?php endif; ?>
                        <th>Комментарий</th>
                        <th>Фото</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i', strtotime($row['event_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['location_title'] . ' (' . $row['city'] . ')'); ?></td>
                            <td>
                                <?php echo htmlspecialchars(serviceEventTypeLabel($row['event_type'])); ?>
                                <?php if ($row['is_emergency']): ?><span class="emergency-tag"> 🚨 срочно</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['source_type'] === 'log'): ?>
                                    <span class="source-badge source-log">📝 постфактум</span>
                                <?php else: ?>
                                    <span class="source-badge source-event">✅ согласовано</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($role === 'owner'): ?><td><?php echo htmlspecialchars($row['operator_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($row['comment'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($row['photos'])): ?>
                                    <?php foreach ($row['photos'] as $photo): ?>
                                        <a href="/<?php echo htmlspecialchars($photo); ?>" target="_blank">
                                            <img src="/<?php echo htmlspecialchars($photo); ?>" style="width:36px; height:36px; object-fit:cover; border-radius:5px; margin-right:3px;" alt="Фото">
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty">
            <p>По этим фильтрам записей не найдено.</p>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>