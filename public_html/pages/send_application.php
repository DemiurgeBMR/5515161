<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

// Отправка первой заявки владельцу — часть того же платного доступа, что и
// точный адрес/имя владельца на карточке локации (см. pages/location.php).
// Проверяем и здесь, а не только скрываем кнопку в шаблоне, иначе доступ
// обходился бы прямой ссылкой на эту страницу.
if (!currentUserHasSubscription()) {
    $_SESSION['flash'] = 'Чтобы отправить заявку владельцу, оформите подписку.';
    header('Location: /pages/subscription.php');
    exit;
}

$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
if ($location_id <= 0) {
    header('Location: /pages/catalog.php');
    exit;
}

$pdo = getDbConnection();

// Проверяем, что локация существует и активна
$stmt = $pdo->prepare("SELECT id, owner_id, title FROM locations WHERE id = ? AND is_active = 1 AND is_moderated = 1");
$stmt->execute([$location_id]);
$location = $stmt->fetch();
if (!$location) {
    header('Location: /pages/catalog.php');
    exit;
}

$operator_id = $_SESSION['user_id'];
$owner_id = $location['owner_id'];

// Локация уже занята — за ней активно закреплён оператор, новую заявку
// подавать некуда (см. ту же логику скрытия в pages/catalog.php). Страница
// локации сама покажет статус "занято" — отдельное flash-сообщение здесь
// не нужно.
$stmt = $pdo->prepare("SELECT 1 FROM location_operators WHERE location_id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$location_id]);
if ($stmt->fetch()) {
    header('Location: /pages/location.php?id=' . $location_id);
    exit;
}

// Проверяем, не отправлял ли оператор уже заявку на эту локацию.
// 'rejected' исключён из блокирующих статусов так же, как и в
// api/operator_assign.php ('request') — иначе одна отклонённая заявка
// навсегда закрывала бы эту локацию для повторной подачи.
$stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('cancelled', 'placed', 'rejected')");
$stmt->execute([$location_id, $operator_id]);
if ($stmt->fetch()) {
    $error = 'Вы уже отправили заявку на эту локацию.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = 'Пожалуйста, напишите сообщение владельцу.';
    } else {
        try {
            $pdo->beginTransaction();

            // Создаём заявку
            $stmt = $pdo->prepare("
                INSERT INTO applications (location_id, operator_id, owner_id, initial_message, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$location_id, $operator_id, $owner_id, $message]);
            $application_id = $pdo->lastInsertId();

            // Создаём первое сообщение (сообщение оператора)
            $stmt = $pdo->prepare("
                INSERT INTO messages (application_id, sender_id, receiver_id, message)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$application_id, $operator_id, $owner_id, $message]);

            $pdo->commit();
            header('Location: /pages/application_chat.php?application_id=' . $application_id);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Ошибка при отправке заявки: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отправить заявку — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <a href="/pages/location.php?id=<?php echo $location_id; ?>" class="back-link">← Назад к локации</a>
        <h2>📩 Отправить заявку на аренду</h2>
        <p style="color: #555; margin-bottom: 15px;">
            Локация: <strong><?php echo htmlspecialchars($location['title']); ?></strong>
        </p>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Сообщение владельцу *</label>
                <textarea name="message" required rows="5" placeholder="Расскажите о себе, опыте работы, предложениях по аренде..."></textarea>
            </div>
            <button type="submit" class="btn-submit">Отправить заявку</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>