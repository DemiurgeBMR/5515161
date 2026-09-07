<?php
require_once __DIR__ . '/../config.php';

$query = trim($_GET['q'] ?? '');

if (empty($query)) {
    header('Location: /pages/catalog.php');
    exit;
}

// Проверяем, не является ли запрос ID формата RR-12345
if (preg_match('/^RR-(\d+)$/i', $query, $matches)) {
    $id = (int)$matches[1];
    // Проверяем, существует ли такая локация
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id FROM locations WHERE id = ? AND is_active = 1 AND is_moderated = 1");
    $stmt->execute([$id]);
    if ($stmt->fetch()) {
        header('Location: /pages/location.php?id=' . $id);
        exit;
    } else {
        // Если ID не найден — перенаправляем в каталог с ошибкой
        header('Location: /pages/catalog.php?error=not_found');
        exit;
    }
}

// Если это не ID — ищем по городам и названиям
// Редиректим на каталог с GET-параметром q
header('Location: /pages/catalog.php?q=' . urlencode($query));
exit;