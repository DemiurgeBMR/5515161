<?php
/**
 * Возвращает объединённую историю обслуживания:
 * постфактум-отметки (machine_service_log) + завершённые визиты (installation_events).
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string $role 'operator' | 'owner'
 * @param array $filters ['date_from' => 'YYYY-MM-DD', 'date_to' => 'YYYY-MM-DD',
 *                         'location_id' => int, 'event_type' => string, 'source' => 'log'|'event'|'']
 * @return array
 */
function getServiceHistory($pdo, $user_id, $role, $filters = []) {
    $roleField = ($role === 'owner') ? 'lo.owner_id' : 'lo.operator_id';

    $whereLog = ["$roleField = ?"];
    $whereEvent = ["$roleField = ?", "ie.status = 'completed'"];
    $paramsLog = [$user_id];
    $paramsEvent = [$user_id];

    if (!empty($filters['date_from'])) {
        $whereLog[] = "ml.performed_at >= ?";
        $whereEvent[] = "COALESCE(ie.confirmed_datetime, ie.proposed_datetime) >= ?";
        $paramsLog[] = $filters['date_from'] . ' 00:00:00';
        $paramsEvent[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $whereLog[] = "ml.performed_at <= ?";
        $whereEvent[] = "COALESCE(ie.confirmed_datetime, ie.proposed_datetime) <= ?";
        $paramsLog[] = $filters['date_to'] . ' 23:59:59';
        $paramsEvent[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['location_id'])) {
        $whereLog[] = "l.id = ?";
        $whereEvent[] = "l.id = ?";
        $paramsLog[] = $filters['location_id'];
        $paramsEvent[] = $filters['location_id'];
    }
    if (!empty($filters['event_type'])) {
        $whereLog[] = "ml.event_type = ?";
        $whereEvent[] = "ie.event_type = ?";
        $paramsLog[] = $filters['event_type'];
        $paramsEvent[] = $filters['event_type'];
    }

    $rows = [];

    if (empty($filters['source']) || $filters['source'] === 'log') {
        $sql = "
            SELECT 'log' as source_type, ml.id, ml.event_type,
                   ml.performed_at as event_date, ml.comment, 0 as is_emergency,
                   l.title as location_title, l.city, l.id as location_id,
                   uo.full_name as operator_name, uw.full_name as owner_name
            FROM machine_service_log ml
            JOIN location_machines lm ON lm.id = ml.machine_id
            JOIN location_operators lo ON lo.id = lm.location_operator_id
            JOIN locations l ON l.id = lo.location_id
            JOIN users uo ON uo.id = lo.operator_id
            JOIN users uw ON uw.id = lo.owner_id
            WHERE " . implode(' AND ', $whereLog) . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsLog);
        $rows = array_merge($rows, $stmt->fetchAll());
    }

    if (empty($filters['source']) || $filters['source'] === 'event') {
        $sql = "
            SELECT 'event' as source_type, ie.id, ie.event_type,
                   COALESCE(ie.confirmed_datetime, ie.proposed_datetime) as event_date,
                   ie.emergency_comment as comment, ie.is_emergency,
                   l.title as location_title, l.city, l.id as location_id,
                   uo.full_name as operator_name, uw.full_name as owner_name
            FROM installation_events ie
            JOIN location_operators lo ON lo.id = ie.location_operator_id
            JOIN locations l ON l.id = lo.location_id
            JOIN users uo ON uo.id = lo.operator_id
            JOIN users uw ON uw.id = lo.owner_id
            WHERE " . implode(' AND ', $whereEvent) . "
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsEvent);
        $rows = array_merge($rows, $stmt->fetchAll());
    }

    usort($rows, function($a, $b) {
        return strtotime($b['event_date']) - strtotime($a['event_date']);
    });

    attachHistoryPhotos($pdo, $rows);

    return $rows;
}

// Подтягивает фото для смешанного набора строк (log + event) одним запросом на каждый тип
function attachHistoryPhotos($pdo, &$rows) {
    if (empty($rows)) {
        return;
    }
    $idsByType = ['log' => [], 'event' => []];
    foreach ($rows as $row) {
        $idsByType[$row['source_type']][] = $row['id'];
    }

    $photosByKey = [];
    foreach ($idsByType as $type => $ids) {
        if (empty($ids)) continue;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT source_id, photo_path FROM service_photos
            WHERE source_type = ? AND source_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $stmt->execute(array_merge([$type], $ids));
        foreach ($stmt->fetchAll() as $p) {
            $photosByKey[$type . '_' . $p['source_id']][] = $p['photo_path'];
        }
    }

    foreach ($rows as &$row) {
        $key = $row['source_type'] . '_' . $row['id'];
        $row['photos'] = $photosByKey[$key] ?? [];
    }
    unset($row);
}

function serviceEventTypeLabel($type) {
    $labels = [
        'installation' => 'Установка',
        'maintenance'  => 'Плановое обслуживание',
        'restock'      => 'Пополнение товара',
        'repair'       => 'Ремонт',
        'removal'      => 'Демонтаж',
    ];
    return $labels[$type] ?? $type;
}