<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();
$defaults = rr_catalog_defaults();

// key => [название, цена по умолчанию]
$items = [];
foreach ($defaults['packs'] as $key => $pack) {
    $items[$key] = ['Пакет «' . $pack['label'] . '»', $pack['price']];
}
foreach ($defaults['plans'] as $key => $plan) {
    $items[$key] = ['Тариф «' . $plan['label'] . '»', $plan['price']];
}
$items['turnkey_deal'] = ['Сделка под ключ', $defaults['turnkey_deal']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
    } else {
        $errors = [];
        $new = [];
        foreach ($items as $key => [$label, $default]) {
            $raw = trim($_POST['price'][$key] ?? '');
            if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 1000000) {
                $errors[] = $label . ': цена должна быть целым числом от 1 до 1 000 000.';
            } else {
                $new[$key] = (int) $raw;
            }
        }

        if ($errors) {
            $_SESSION['flash'] = implode(' ', $errors);
        } else {
            foreach ($new as $key => $price) {
                if ($price === $items[$key][1]) {
                    $pdo->prepare("DELETE FROM price_overrides WHERE item_key = ?")->execute([$key]);
                } else {
                    $pdo->prepare("
                        INSERT INTO price_overrides (item_key, price) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE price = VALUES(price)
                    ")->execute([$key, $price]);
                }
            }
            $_SESSION['flash'] = 'Цены сохранены. Они уже действуют на странице тарифов.';
        }
    }
    header('Location: /admin/prices.php');
    exit;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$overrides = rr_price_overrides();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Цены — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('settings'); ?> Цены</h1>
        <?php include __DIR__ . '/../includes/admin_nav.php'; ?>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <p>Меняются только цены. Количество контактов в пакетах и квоты в тарифах задаются в коде.
        Уже совершённые покупки сохраняют ту цену, по которой были оплачены.</p>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="admin-table">
                <table class="adm-cards">
                    <thead><tr><th>Товар</th><th>Цена, ₽</th><th>По умолчанию</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $key => [$label, $default]): ?>
                            <tr>
                                <td class="c-title" data-label="Товар"><?php echo htmlspecialchars($label); ?></td>
                                <td data-label="Цена, ₽">
                                    <input type="number" name="price[<?php echo htmlspecialchars($key); ?>]" min="1" max="1000000" step="1" required
                                           value="<?php echo (int) ($overrides[$key] ?? $default); ?>">
                                </td>
                                <td data-label="По умолчанию"><?php echo number_format($default, 0, ',', ' '); ?> ₽<?php echo isset($overrides[$key]) ? ' (изменено)' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn-submit"><?php echo rr_icon('save'); ?> Сохранить цены</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
