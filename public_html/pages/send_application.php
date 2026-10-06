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
// Поля после title (город, адрес, цена, фото, имя собственника) — только для сводки «мини-карточка
// локации» в телефонной версии; адрес/имя здесь можно — дальше пускаем лишь разблокировавших (ниже).
$stmt = $pdo->prepare("
    SELECT l.id, l.owner_id, l.title, l.city, l.address, l.price_month, ow.full_name AS owner_name,
        (SELECT photo_path FROM location_photos WHERE location_id = l.id AND is_main = 1 AND is_pending = 0 LIMIT 1) AS main_photo
    FROM locations l
    LEFT JOIN users ow ON ow.id = l.owner_id
    WHERE l.id = ? AND l.is_active = 1 AND l.is_moderated = 1
");
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
// 'unassigned' исключён по той же причине: закрепление по этой заявке уже
// сняли (action=unassign переводит саму заявку в unassigned) — это закрытая
// глава, а не всё ещё действующее ограничение. 'approved' оставлен в
// исключениях как подстраховка для заявок, оставшихся approved ещё до
// введения статуса unassigned — к этой строке мы бы не дошли, если бы
// закрепление было всё ещё активно (точка уже не числится занятой, см. выше).
$stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('cancelled', 'rejected', 'approved', 'unassigned')");
$stmt->execute([$location_id, $operator_id]);
$mExistingAppId = null; // телефонная версия: вместо повторной формы — переход в чат этой заявки
if ($existingApp = $stmt->fetch()) {
    $error = 'Вы уже отправили заявку на эту локацию.';
    $mExistingAppId = (int) $existingApp['id'];
}
$mFieldError = false;   // ошибка относится к полю сообщения — на телефоне показываем её под полем

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = 'Пожалуйста, напишите сообщение собственнику.';
        $mFieldError = true;
    } else {
        // Именной лок MySQL на пару (локация, оператор) — без него узкое окно
        // между проверкой "уже подавали?" выше и INSERT ниже позволяло
        // двойному клику/повторной отправке формы создать две заявки на одну
        // и ту же локацию: partial unique index на "NOT IN (терминальные
        // статусы)" MySQL не поддерживает, а обычный UNIQUE(location_id,
        // operator_id) сломал бы законную повторную подачу после отказа.
        // Лок сериализует конкретно эту пару без изменений схемы и
        // автоматически снимается MySQL при закрытии соединения, даже если
        // до явного RELEASE_LOCK ниже дело почему-то не дойдёт.
        $lockKey = 'send_application:' . $location_id . ':' . $operator_id;
        $gotLock = (bool) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 5)')->fetchColumn();
        if (!$gotLock) {
            $error = 'Не удалось обработать запрос, попробуйте ещё раз.';
        } else {
            // Перепроверяем внутри лока — заявка могла появиться, пока мы его ждали.
            $stmt = $pdo->prepare("SELECT id FROM applications WHERE location_id = ? AND operator_id = ? AND status NOT IN ('cancelled', 'rejected', 'approved', 'unassigned')");
            $stmt->execute([$location_id, $operator_id]);
            if ($stmt->fetch()) {
                $error = 'Вы уже отправили заявку на эту локацию.';
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
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

                    // Этот INSERT — единственное место на сайте, где создаётся
                    // первое сообщение чата (все следующие идут через
                    // api/send_message.php, который сам уведомляет
                    // получателя). Раньше уведомления здесь не было вообще —
                    // собственник узнавал о новой заявке, только случайно
                    // заглянув в список заявок, а не по факту первого
                    // контакта, как для всех последующих сообщений в этом же чате.
                    $preview = mb_substr($message, 0, 80) . (mb_strlen($message) > 80 ? '…' : '');
                    notify($pdo, $owner_id, 'new_message', $preview, '/pages/application_chat.php?application_id=' . $application_id, [
                        'application_id' => $application_id,
                        'sender_name'    => $_SESSION['user_name'] ?? '',
                        'location_title' => $location['title'],
                    ]);

                    $pdo->commit();
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
                    header('Location: /pages/application_chat.php?application_id=' . $application_id);
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
                    error_log('send_application.php: ' . $e->getMessage());
                    $error = DEBUG_MODE ? ('Ошибка при отправке заявки: ' . $e->getMessage()) : 'Не удалось отправить заявку. Попробуйте ещё раз позже.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Отправить заявку — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-sendapp m-no-tabbar m-has-cta">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="register-form m-sendapp-wrap">
        <!-- Телефоны (≤768px): панель «назад», сводка локации; стили — assets/css/pages/m/_m-send-application.css -->
        <div class="m-only m-appbar">
            <a href="/pages/location.php?id=<?php echo $location_id; ?>" class="m-appbar-back" data-m-back aria-label="Назад к локации"><?php echo rr_icon('chevron-left'); ?></a>
            <h1 class="m-appbar-title">Заявка на аренду</h1>
        </div>
        <a href="/pages/location.php?id=<?php echo $location_id; ?>" class="m-only m-card m-sa-loc">
            <span class="m-sa-photo">
                <?php if (!empty($location['main_photo'])): ?>
                    <img src="/<?php echo htmlspecialchars($location['main_photo']); ?>" alt="" decoding="async">
                <?php else: ?>
                    <?php echo rr_icon('camera'); ?>
                <?php endif; ?>
            </span>
            <span class="m-sa-info">
                <b class="m-sa-price"><?php echo number_format($location['price_month'], 0, ',', ' '); ?> ₽ <small>/ мес</small></b>
                <span class="m-sa-title"><?php echo htmlspecialchars($location['title']); ?></span>
                <span class="m-sa-addr"><?php echo htmlspecialchars($location['city'] . ($location['address'] !== '' ? ', ' . $location['address'] : '')); ?></span>
            </span>
        </a>
        <?php if (!empty($location['owner_name'])): ?>
            <p class="m-only m-sa-owner">
                <span class="m-sa-ava" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($location['owner_name']), 0, 1))); ?></span>
                <span><b><?php echo htmlspecialchars($location['owner_name']); ?></b> получит заявку и ответит в чате — он появится в разделе «Заявки».</span>
            </p>
        <?php endif; ?>
        <a href="/pages/location.php?id=<?php echo $location_id; ?>" class="back-link">← Назад к локации</a>
        <h2><?php echo rr_icon('mail'); ?> Отправить заявку на аренду</h2>
        <p class="page-intro spaced-tight">
            Локация: <strong><?php echo htmlspecialchars($location['title']); ?></strong>
        </p>

        <?php if (isset($error)): ?>
            <div class="error<?php echo $mFieldError ? ' m-hide' : ''; ?>" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($mExistingAppId): ?>
            <div class="m-only m-card m-sa-sent">
                <?php echo rr_icon('message-circle'); ?>
                <span><b>Переписка уже идёт</b>Продолжайте общение с собственником в чате этой заявки.</span>
            </div>
        <?php endif; ?>

        <form method="POST" id="sendAppForm"<?php echo $mExistingAppId ? ' class="m-hide"' : ''; ?>>
            <?php echo csrf_field(); ?>
            <div class="form-group m-field<?php echo $mFieldError ? ' is-invalid' : ''; ?>">
                <label for="saMessage">Сообщение собственнику *</label>
                <textarea name="message" id="saMessage" required rows="5" placeholder="Расскажите о себе, опыте работы, предложениях по аренде..." aria-describedby="saMessageErr saMessageHint"></textarea>
                <div class="m-only m-sa-under">
                    <span class="m-sa-err" id="saMessageErr" role="alert"><?php echo $mFieldError ? 'Напишите сообщение собственнику — без него заявку не отправить.' : ''; ?></span>
                    <span class="m-sa-count" aria-hidden="true"><b data-m-count>0</b> симв.</span>
                </div>
                <p class="m-only m-hint" id="saMessageHint">Напишите, какой автомат хотите поставить, его габариты и с какого числа готовы начать, — так собственник ответит быстрее.</p>
            </div>
            <button type="submit" class="btn-submit">Отправить заявку</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
<div class="m-only m-sticky-cta m-sa-cta">
    <?php if ($mExistingAppId): ?>
        <a href="/pages/application_chat.php?application_id=<?php echo $mExistingAppId; ?>" class="m-btn m-btn--block"><?php echo rr_icon('message-circle'); ?> Открыть чат</a>
    <?php else: ?>
        <button type="submit" form="sendAppForm" class="m-btn m-btn--block" data-m-submit><?php echo rr_icon('send'); ?> Отправить заявку</button>
    <?php endif; ?>
</div>
<script>
// Телефоны: счётчик символов, ошибка под полем вместо системной подсказки, защита от двойной отправки
(function () {
    var PHONE = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
    var form = document.getElementById('sendAppForm');
    var ta = document.getElementById('saMessage');
    if (!form || !ta) return;
    var field = ta.closest('.m-field');
    var err = document.getElementById('saMessageErr');
    var count = form.querySelector('[data-m-count]');
    function setErr(text) {
        if (err) err.textContent = text;
        if (field) field.classList.toggle('is-invalid', !!text);
    }
    function upd() {
        if (count) count.textContent = String(ta.value.length);
        if (ta.value.trim()) setErr('');
    }
    ta.addEventListener('input', upd);
    upd();
    ta.addEventListener('invalid', function (e) {
        if (!PHONE.matches) return;               // десктоп — как раньше, системная подсказка
        e.preventDefault();
        setErr('Напишите сообщение собственнику — без него заявку не отправить.');
        ta.focus();
    });
    form.addEventListener('submit', function (e) {
        if (!ta.value.trim()) {
            if (!PHONE.matches) return;
            e.preventDefault();
            setErr('Напишите сообщение собственнику — без него заявку не отправить.');
            ta.focus();
            return;
        }
        if (form.getAttribute('data-sent')) { e.preventDefault(); return; }
        form.setAttribute('data-sent', '1');
        var b = document.querySelector('[data-m-submit]');
        if (b) { b.classList.add('is-disabled'); b.lastChild.textContent = ' Отправляем…'; }
    });
    var back = document.querySelector('[data-m-back]');
    if (back) back.addEventListener('click', function (e) {
        var same = false;
        try { same = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (x) {}
        if (same && history.length > 1) { e.preventDefault(); history.back(); }
    });
})();
</script>
</body>
</html>