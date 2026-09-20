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

// Одноразовая починка последствий бага в applyRevision() (admin/actions.php):
// если ревизия одновременно удаляла текущее главное фото и указывала его же
// как main_photo_id (типичный случай — владелец удалил фото, не выбрав
// другое главное), локация оставалась вовсе без главного фото, хотя другие
// фото у неё есть. Баг в коде уже исправлен — эта страница чинит то, что
// уже успело сломаться до исправления.
$affectedSql = "
    SELECT DISTINCT l.id
    FROM locations l
    JOIN location_photos lp ON lp.location_id = l.id
        AND lp.is_pending = 0
    WHERE NOT EXISTS (
        SELECT 1 FROM location_photos lp2
        WHERE lp2.location_id = l.id AND lp2.is_main = 1
    )
";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $batchResult = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    } else {
        $ids = $pdo->query($affectedSql)->fetchAll(PDO::FETCH_COLUMN);
        $fixed = 0;
        foreach ($ids as $locationId) {
            $stmt = $pdo->prepare("
                SELECT id FROM location_photos
                WHERE location_id = ? AND is_pending = 0
                ORDER BY sort_order ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$locationId]);
            $first = $stmt->fetch();
            if ($first) {
                $pdo->prepare("UPDATE location_photos SET is_main = 1 WHERE id = ?")->execute([$first['id']]);
                $fixed++;
            }
        }
        $batchResult = "Исправлено локаций: $fixed.";
    }
}

$remaining = (int) $pdo->query("SELECT COUNT(*) FROM ($affectedSql) t")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Починка главных фото — Админ-панель RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('camera'); ?> Починка главных фото</h1>

        <div class="nav-admin">
            <a href="/admin/index.php"><?php echo rr_icon('list'); ?> На модерацию</a>
            <a href="/admin/locations.php"><?php echo rr_icon('map-pin'); ?> Все локации</a>
            <a href="/admin/users.php"><?php echo rr_icon('users'); ?> Пользователи</a>
            <a href="/admin/geocode_backfill.php"><?php echo rr_icon('globe'); ?> Геокодирование</a>
            <a href="/admin/fix_main_photos.php"><?php echo rr_icon('camera'); ?> Починка фото</a>
        </div>

        <p>Находит локации, у которых есть фото, но ни одно не отмечено главным (из-за уже
           исправленного бага в одобрении ревизий) — и назначает главным первое доступное фото,
           чтобы карточка на карте и в каталоге снова показывала реальное фото вместо заглушки.</p>

        <?php if ($batchResult): ?>
            <div class="success" role="status"><?php echo htmlspecialchars($batchResult); ?></div>
        <?php endif; ?>

        <p>Локаций без главного фото: <strong><?php echo $remaining; ?></strong></p>

        <?php if ($remaining > 0): ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="run">
                <button type="submit" class="btn-submit">Исправить все ( <?php echo $remaining; ?> )</button>
            </form>
        <?php else: ?>
            <p><?php echo rr_icon('check'); ?> У всех локаций с фото есть главное.</p>
        <?php endif; ?>

        <p class="admin-link-paragraph"><a href="/admin/index.php">← В админку</a></p>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
