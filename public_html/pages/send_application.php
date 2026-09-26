<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
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

// Отправка заявки — часть того же платного доступа, что и точный адрес/имя
// собственника на карточке локации (см. pages/location.php): нужно сначала
// разблокировать именно эту локацию за кредит. Проверяем и здесь, а не
// только скрываем кнопку в шаблоне, иначе доступ обходился бы прямой
// ссылкой на эту страницу.
if (!rr_location_unlocked($pdo, $operator_id, $location_id)) {
    $_SESSION['flash'] = 'Сначала разблокируйте контакт собственника на странице локации.';
    header('Location: /pages/location.php?id=' . $location_id);
    exit;
}

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
// 'approved' исключён по той же причине: если закрепление уже снято
// (см. action=unassign выше — иначе мы не дошли бы до этой строки, точка
// уже не числится занятой), старая одобренная заявка — это закрытая
// глава, а не всё ещё действующее ограничение. Без этого исключения
// операторы и владельцы, once закрепление снято, не могли ни подать
// новую заявку сюда же, ни (см. application_chat.php) запросить
// закрепление повторно в старом чате — единственным рабочим путём
// оставалось прямое закрепление владельцем из "Мои операторы".
$stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('cancelled', 'placed', 'rejected', 'approved')");
$stmt->execute([$location_id, $operator_id]);
if ($stmt->fetch()) {
    $error = 'Вы уже отправили заявку на эту локацию.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = 'Пожалуйста, напишите сообщение собственнику.';
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

            // Этот INSERT — единственное место на сайте, где создаётся первое
            // сообщение чата (все следующие идут через api/send_message.php,
            // который сам уведомляет получателя). Раньше уведомления здесь не
            // было вообще — собственник узнавал о новой заявке, только
            // случайно заглянув в список заявок, а не по факту первого
            // контакта, как для всех последующих сообщений в этом же чате.
            $preview = mb_substr($message, 0, 80) . (mb_strlen($message) > 80 ? '…' : '');
            notify($pdo, $owner_id, 'new_message', $preview, '/pages/application_chat.php?application_id=' . $application_id, [
                'application_id' => $application_id,
                'sender_name'    => $_SESSION['user_name'] ?? '',
                'location_title' => $location['title'],
            ]);

            $pdo->commit();
            header('Location: /pages/application_chat.php?application_id=' . $application_id);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('send_application.php: ' . $e->getMessage());
            $error = DEBUG_MODE ? ('Ошибка при отправке заявки: ' . $e->getMessage()) : 'Не удалось отправить заявку. Попробуйте ещё раз позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отправить заявку — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form">
        <a href="/pages/location.php?id=<?php echo $location_id; ?>" class="back-link">← Назад к локации</a>
        <h2><?php echo rr_icon('mail'); ?> Отправить заявку на аренду</h2>
        <p class="page-intro spaced-tight">
            Локация: <strong><?php echo htmlspecialchars($location['title']); ?></strong>
        </p>

        <?php if (isset($error)): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Сообщение собственнику *</label>
                <textarea name="message" required rows="5" placeholder="Расскажите о себе, опыте работы, предложениях по аренде..."></textarea>
            </div>
            <button type="submit" class="btn-submit">Отправить заявку</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>