<?php
session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$batchResult = null;

// Локации, добавленные ДО появления геокодирования, координат не имеют —
// эта страница проставляет их пачками, уважая лимит Nominatim в 1 запрос/сек.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $stmt = $pdo->prepare("
        SELECT id, city, address FROM locations
        WHERE (latitude IS NULL OR longitude IS NULL)
          AND city != '' AND address != ''
        LIMIT 20
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $done = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $geo = geocodeAddress($row['address'], $row['city']);
        if ($geo) {
            $upd = $pdo->prepare("UPDATE locations SET latitude = ?, longitude = ? WHERE id = ?");
            $upd->execute([$geo['lat'], $geo['lng'], $row['id']]);
            $done++;
        } else {
            $failed++;
        }
    }
    $batchResult = "Обработано локаций: $done, не удалось найти адрес: $failed.";
}

$remaining = (int) $pdo->query("
    SELECT COUNT(*) FROM locations
    WHERE (latitude IS NULL OR longitude IS NULL) AND city != '' AND address != ''
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Геокодирование локаций — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1>🌍 Геокодирование локаций</h1>

        <div class="nav-admin">
            <a href="/admin/index.php">📋 На модерацию</a>
            <a href="/admin/locations.php">📍 Все локации</a>
            <a href="/admin/geocode_backfill.php">🌍 Геокодирование</a>
        </div>

        <p>Эта страница проставляет координаты локациям, добавленным до появления карты
           (новые локации получают координаты автоматически при добавлении/редактировании).</p>

        <?php if ($batchResult): ?>
            <div class="success"><?php echo htmlspecialchars($batchResult); ?></div>
        <?php endif; ?>

        <p>Локаций без координат: <strong><?php echo $remaining; ?></strong></p>

        <?php if ($remaining > 0): ?>
            <form method="POST">
                <input type="hidden" name="action" value="run">
                <button type="submit" class="btn-submit">
                    Геокодировать следующие 20 (займёт ~<?php echo min($remaining, 20); ?> сек)
                </button>
            </form>
        <?php else: ?>
            <p>🎉 Все локации с адресом уже имеют координаты.</p>
        <?php endif; ?>

        <p style="margin-top: 20px;"><a href="/admin/index.php">← В админку</a></p>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
