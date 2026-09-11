<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (mb_strlen($query, 'UTF-8') < 2) {
    echo json_encode([]);
    exit;
}

// Кеш на 10 минут — файловый (getCached/setCache из config.php), а не в
// сессии: сессионный кеш рос бы бессрочно с каждым новым поисковым запросом
// и никогда не освобождался, пока жива сессия пользователя.
$cache_key = 'city_' . md5($query);
$cached = getCached($cache_key, 600);
if ($cached !== null) {
    echo json_encode($cached);
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
    setCache($cache_key, $result);

    echo json_encode($result);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось выполнить поиск городов']);
}