<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run' && !csrf_verify($_POST['csrf_token'] ?? '')) {
    $batchResult = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
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
        // lat === null значит, что найденная улица не похожа на введённую
        // (geocodeAddress тогда не доверяет совпадению) — это тот же случай,
        // что и полностью неудавшийся геокодинг, а не "готово".
        if ($geo && $geo['lat'] !== null) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Геокодирование локаций — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container adm-tool">
        <h1><?php echo rr_icon('globe'); ?> Геокодирование локаций</h1>

        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

        <p>Эта страница проставляет координаты локациям, добавленным до появления карты
           (новые локации получают координаты автоматически при добавлении/редактировании).</p>

        <?php if ($batchResult): ?>
            <div class="success" role="status"><?php echo htmlspecialchars($batchResult); ?></div>
        <?php endif; ?>

        <p class="adm-count">Локаций без координат: <strong><?php echo $remaining; ?></strong></p>

        <?php if ($remaining > 0): ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="run">
                <button type="submit" class="btn-submit">
                    Геокодировать следующие 20 (займёт ~<?php echo min($remaining, 20); ?> сек)
                </button>
            </form>
        <?php else: ?>
            <p class="adm-done"><?php echo rr_icon('check'); ?> Все локации с адресом уже имеют координаты.</p>
        <?php endif; ?>

        <p class="admin-link-paragraph"><a href="/admin/index.php">← В админку</a></p>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
