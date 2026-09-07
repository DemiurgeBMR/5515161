<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (mb_strlen($query, 'UTF-8') < 2) {
    echo json_encode([]);
    exit;
}

// Кеш на 10 минут
$cache_key = 'city_' . md5($query);
if (isset($_SESSION[$cache_key]) && time() - $_SESSION[$cache_key . '_time'] < 600) {
    echo json_encode($_SESSION[$cache_key]);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT title_ru as name, region_ru as region
        FROM _cities
        WHERE title_ru LIKE ?
        ORDER BY title_ru
        LIMIT 10
    ");
    $stmt->execute([$query . '%']); // ← ищем только начало слова
    $cities = $stmt->fetchAll();
    
    $result = [];
    foreach ($cities as $city) {
        $label = $city['name'];
        if (!empty($city['region'])) {
            $label .= ', ' . $city['region'];
        }
        $result[] = [
            'label' => $label,
            'value' => $city['name']
        ];
    }
    
    // Сохраняем в кеш
    $_SESSION[$cache_key] = $result;
    $_SESSION[$cache_key . '_time'] = time();
    
    echo json_encode($result);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}