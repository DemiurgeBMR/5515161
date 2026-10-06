<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
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

// ===== Телефон (≤768px): лента карточек, фильтр по типу — чипами, остальное — в шторке =====
$mTypeChips = [
    ''              => 'Все',
    'maintenance'   => 'Плановое ТО',
    'restock'       => 'Пополнение',
    'repair'        => 'Ремонт',
    'broken'        => 'Поломка',
    'needs_service' => 'Требует ремонта',
    'installation'  => 'Установка',
    'removal'       => 'Демонтаж',
];
$mTypeTone = [
    'installation'  => '',
    'maintenance'   => '',
    'restock'       => 'is-info',
    'repair'        => 'is-info',
    'needs_service' => 'is-warning',
    'broken'        => 'is-danger',
    'removal'       => 'is-muted',
];
$mTypeIcon = [
    'installation'  => 'plus-circle',
    'maintenance'   => 'check',
    'restock'       => 'bag',
    'repair'        => 'tool',
    'needs_service' => 'wrench',
    'broken'        => 'warning',
    'removal'       => 'x',
];
$mExtraFilters = count(array_filter([$filters['date_from'], $filters['date_to'], $filters['location_id'], $filters['source']]));
$mAnyFilter = $mExtraFilters > 0 || $filters['event_type'] !== '';
$mChipHref = function ($type) use ($filters) {
    $q = http_build_query(array_filter(array_merge($filters, ['event_type' => $type])));
    return '/pages/service_history.php' . ($q !== '' ? '?' . $q : '');
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>История обслуживания — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-pg-svc-hist">
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="history-container">
    <a href="<?php echo $backLink; ?>" class="back-link">← Назад</a>
    <h2><?php echo rr_icon('file-text'); ?> История обслуживания</h2>

    <div class="m-only m-appbar sh-appbar">
        <a class="m-appbar-back" href="<?php echo $backLink; ?>" onclick="if (history.length > 1) { history.back(); return false; }" aria-label="Назад"><?php echo rr_icon('chevron-left'); ?></a>
        <h1 class="m-appbar-title">История обслуживания</h1>
        <button type="button" class="sh-export-open" data-m-sheet-open="shExport" aria-haspopup="dialog" aria-controls="shExport" aria-label="Экспорт"><?php echo rr_icon('download'); ?> <span class="sh-export-lb">Экспорт</span></button>
    </div>

    <div class="m-only m-chips sh-chips" role="toolbar" aria-label="Фильтры истории">
        <button type="button" class="m-chip sh-chip-filters<?php echo $mExtraFilters ? ' is-on' : ''; ?>" data-m-sheet-open="shFilters" aria-haspopup="dialog" aria-controls="shFilters"><?php echo rr_icon('sliders'); ?> Фильтры<?php if ($mExtraFilters): ?> <span class="sh-chip-n"><?php echo $mExtraFilters; ?></span><?php endif; ?></button>
        <?php foreach ($mTypeChips as $type => $label): $on = ($filters['event_type'] === $type); ?>
            <a class="m-chip<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo htmlspecialchars($mChipHref($type)); ?>"<?php echo $on ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </div>

    <form class="filters-bar m-sheet" method="GET" id="shFilters" aria-label="Фильтры">
        <div class="m-only m-sheet-handle" aria-hidden="true"></div>
        <div class="m-only m-sheet-head">
            <b class="m-sheet-title">Фильтры</b>
            <a class="m-sheet-link" href="/pages/service_history.php">Сбросить</a>
            <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
        </div>
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
                <option value="broken" <?php echo $filters['event_type'] === 'broken' ? 'selected' : ''; ?>>Поломка</option>
                <option value="needs_service" <?php echo $filters['event_type'] === 'needs_service' ? 'selected' : ''; ?>>Требует ремонта</option>
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
        <button type="submit" class="sh-btn-filter">Применить</button>
    </form>

    <div class="export-bar m-sheet" id="shExport" aria-label="Экспорт истории">
        <div class="m-only m-sheet-handle" aria-hidden="true"></div>
        <div class="m-only m-sheet-head">
            <b class="m-sheet-title">Экспорт</b>
            <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
        </div>
        <p class="m-only sh-export-hint"><?php echo $mAnyFilter ? 'В файл попадут записи с текущими фильтрами.' : 'В файл попадёт вся история обслуживания.'; ?></p>
        <a class="btn-export" href="/pages/export_history.php?format=csv&<?php echo $exportQuery; ?>"><?php echo rr_icon('download'); ?> Экспорт CSV (Excel)</a>
        <a class="btn-export" href="/pages/export_history.php?format=pdf&<?php echo $exportQuery; ?>"><?php echo rr_icon('download'); ?> Экспорт PDF</a>
    </div>

    <p class="summary-line">Найдено записей: <?php echo count($rows); ?></p>

    <?php if (count($rows) > 0): ?>
        <ul class="m-only sh-feed">
            <?php foreach ($rows as $row):
                $type = $row['event_type'];
                $ts = strtotime($row['event_date']);
            ?>
                <li class="sh-item">
                    <span class="sh-item-ic <?php echo $mTypeTone[$type] ?? 'is-muted'; ?>"><?php echo rr_icon($mTypeIcon[$type] ?? 'file-text'); ?></span>
                    <div class="sh-item-main">
                        <div class="sh-item-top">
                            <span class="m-pill <?php echo $mTypeTone[$type] ?? 'is-muted'; ?>"><?php echo htmlspecialchars($mTypeChips[$type] ?? serviceEventTypeLabel($type)); ?></span>
                            <?php if ($row['is_emergency']): ?><span class="m-pill is-danger"><?php echo rr_icon('warning'); ?> срочно</span><?php endif; ?>
                            <time class="sh-item-date" datetime="<?php echo date('c', $ts); ?>"><?php echo date('d.m.Y', $ts); ?><span>, <?php echo date('H:i', $ts); ?></span></time>
                        </div>
                        <b class="sh-item-title"><?php echo htmlspecialchars($row['location_title']); ?></b>
                        <span class="sh-item-city"><?php echo rr_icon('map-pin'); ?> <?php echo htmlspecialchars($row['city']); ?></span>
                        <?php if (trim((string) ($row['comment'] ?? '')) !== ''): ?>
                            <p class="sh-item-comment"><?php echo nl2br(htmlspecialchars($row['comment'])); ?></p>
                        <?php endif; ?>
                        <div class="sh-item-meta">
                            <span><?php echo rr_icon('user'); ?> <?php echo htmlspecialchars($row['operator_name']); ?></span>
                            <span><?php echo $row['source_type'] === 'log' ? rr_icon('edit') . ' постфактум' : rr_icon('check') . ' согласовано'; ?></span>
                        </div>
                        <?php if (!empty($row['photos'])): ?>
                            <div class="sh-item-photos">
                                <?php foreach ($row['photos'] as $photo): ?>
                                    <a href="/<?php echo htmlspecialchars($photo); ?>" target="_blank" rel="noopener"><img src="/<?php echo htmlspecialchars($photo); ?>" alt="Фото" loading="lazy" width="64" height="64"></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="history-table m-hide">
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
                                <?php if ($row['is_emergency']): ?><span class="emergency-tag"> <?php echo rr_icon('warning'); ?> срочно</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['source_type'] === 'log'): ?>
                                    <span class="source-badge source-log"><?php echo rr_icon('edit'); ?> постфактум</span>
                                <?php else: ?>
                                    <span class="source-badge source-event"><?php echo rr_icon('check'); ?> согласовано</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($role === 'owner'): ?><td><?php echo htmlspecialchars($row['operator_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($row['comment'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($row['photos'])): ?>
                                    <?php foreach ($row['photos'] as $photo): ?>
                                        <a href="/<?php echo htmlspecialchars($photo); ?>" target="_blank">
                                            <img src="/<?php echo htmlspecialchars($photo); ?>" class="history-photo-thumb" alt="Фото">
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="no-photo-dash">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty">
            <span class="m-only sh-empty-ic"><?php echo rr_icon('file-text'); ?></span>
            <p>По этим фильтрам записей не найдено.</p>
            <?php if ($mAnyFilter): ?>
                <a class="m-only m-btn m-btn--ghost" href="/pages/service_history.php">Сбросить фильтры</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>