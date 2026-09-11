<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once '../config.php';

// Если пользователь уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';

// Значения для повторного заполнения формы после ошибки.
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$role = $_POST['role'] ?? ((($_GET['role'] ?? '') === 'owner') ? 'owner' : 'operator');
if (!in_array($role, ['owner', 'operator'], true)) {
    $role = 'operator';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Не удалось подтвердить запрос, обновите страницу и попробуйте ещё раз.';
    } elseif (empty($full_name) || empty($email) || empty($password)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email адрес';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } else {
        try {
            $pdo = getDbConnection();

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Этот email уже зарегистрирован';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password, full_name, phone, role)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$email, $hashed_password, $full_name, $phone, $role]);
                $user_id = $pdo->lastInsertId();

                session_regenerate_id(true); // новая сессия для только что созданного пользователя
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_role'] = $role;
                $_SESSION['has_subscription'] = 0;

                $_SESSION['flash'] = $role === 'owner'
                    ? 'Добро пожаловать! Добавьте свою первую локацию, чтобы начать получать заявки от операторов.'
                    : 'Добро пожаловать! Загляните в каталог, чтобы найти подходящую точку для размещения.';

                header('Location: /pages/profile.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="reg-page">
        <div class="reg-container">
            <div class="reg-hero">
                <span class="reg-badge">🚀 Присоединяйтесь к RR</span>
                <h1>Начните с RR</h1>
                <p class="reg-subtitle">
                    Найдите точку для вендинга или сдайте своё место в аренду — переписка и вся
                    договорённость проходят прямо на платформе.
                </p>
            </div>

            <div class="reg-features">
                <div class="reg-feature">
                    <span class="reg-feature-icon">📩</span>
                    <div class="reg-feature-title">Бесплатная регистрация</div>
                    <div class="reg-feature-text">Без скрытых платежей за создание аккаунта</div>
                </div>
                <div class="reg-feature">
                    <span class="reg-feature-icon">💬</span>
                    <div class="reg-feature-title">Общение через платформу</div>
                    <div class="reg-feature-text">Никаких звонков вслепую — всё в чате</div>
                </div>
                <div class="reg-feature">
                    <span class="reg-feature-icon">🛡️</span>
                    <div class="reg-feature-title">Модерация объявлений</div>
                    <div class="reg-feature-text">Проверяем локации перед публикацией в каталоге</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="registerForm" class="reg-form role-<?php echo $role; ?>" novalidate>
                <?php echo csrf_field(); ?>
                <div class="reg-role-picker">
                    <label class="reg-role-card<?php echo $role === 'operator' ? ' active' : ''; ?>" data-role="operator">
                        <input type="radio" name="role" value="operator" <?php echo $role === 'operator' ? 'checked' : ''; ?>>
                        <span class="reg-role-icon">🤝</span>
                        <span class="reg-role-title">Я оператор</span>
                        <span class="reg-role-text">Ищу локацию для вендинга</span>
                    </label>
                    <label class="reg-role-card<?php echo $role === 'owner' ? ' active' : ''; ?>" data-role="owner">
                        <input type="radio" name="role" value="owner" <?php echo $role === 'owner' ? 'checked' : ''; ?>>
                        <span class="reg-role-icon">🏢</span>
                        <span class="reg-role-title">Я владелец</span>
                        <span class="reg-role-text">Сдаю место под автомат</span>
                    </label>
                </div>

                <div class="reg-steps">
                    <div class="reg-step active" data-step="1">
                        <span class="reg-step-num">1</span>
                        <span class="reg-step-label">Ваши данные</span>
                    </div>
                    <div class="reg-step-line"></div>
                    <div class="reg-step" data-step="done">
                        <span class="reg-step-num">✓</span>
                        <span class="reg-step-label">Готово</span>
                    </div>
                </div>

                <!-- Шаг 1: аккаунт -->
                <div class="reg-panel active" data-panel="1">
                    <h2>Ваши данные</h2>
                    <p class="reg-panel-sub">Понадобится, чтобы создать аккаунт и присылать уведомления по заявкам.</p>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Имя *</label>
                            <input type="text" name="full_name" required placeholder="Иван Иванов" value="<?php echo htmlspecialchars($full_name); ?>">
                        </div>
                        <div class="form-group">
                            <label>Телефон (необязательно)</label>
                            <input type="tel" name="phone" placeholder="+7 900 000-00-00" value="<?php echo htmlspecialchars($phone); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" required placeholder="you@example.com" value="<?php echo htmlspecialchars($email); ?>">
                    </div>
                    <div class="form-group">
                        <label>Пароль *</label>
                        <input type="password" name="password" required minlength="6" placeholder="Минимум 6 символов">
                    </div>

                    <div class="reg-panel-actions">
                        <span></span>
                        <button type="button" class="btn-submit reg-next">Продолжить →</button>
                    </div>
                </div>

                <!-- Шаг "Готово" -->
                <div class="reg-panel" data-panel="done">
                    <h2>Готово к регистрации</h2>

                    <ul class="reg-done-list operator-only">
                        <li><span class="reg-done-icon">🔍</span> Сразу после регистрации откроется каталог — ищите точки по городу, типу помещения и проходимости.</li>
                        <li><span class="reg-done-icon">🔒</span> Чтобы написать владельцу и увидеть точный адрес, потребуется подписка — оформляется в один клик в личном кабинете.</li>
                        <li><span class="reg-done-icon">💬</span> Вся переписка и договорённости — прямо в чате на платформе.</li>
                    </ul>

                    <ul class="reg-done-list owner-only">
                        <li><span class="reg-done-icon">➕</span> Сразу после регистрации добавьте первую локацию в личном кабинете — фото, адрес, цена аренды и другие детали.</li>
                        <li><span class="reg-done-icon">🛡️</span> Перед публикацией в каталоге объявление проверит администратор.</li>
                        <li><span class="reg-done-icon">🤝</span> RR пока не принимает оплату за вас — аренда обсуждается и переводится напрямую между вами и оператором. Приём платежей через платформу мы добавим позже.</li>
                    </ul>

                    <div class="reg-panel-actions">
                        <button type="button" class="reg-btn-back reg-back">← Назад</button>
                        <button type="submit" class="btn-submit">Завершить регистрацию</button>
                    </div>
                </div>
            </form>

            <p class="reg-login-link">Уже есть аккаунт? <a href="/pages/login.php">Войдите</a></p>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('registerForm');
        var panels = Array.prototype.slice.call(form.querySelectorAll('.reg-panel'));
        var roleCards = Array.prototype.slice.call(form.querySelectorAll('.reg-role-card'));
        var stepEls = Array.prototype.slice.call(form.querySelectorAll('.reg-step'));
        var order = ['1', 'done'];

        function currentRole() {
            var checked = form.querySelector('input[name="role"]:checked');
            return checked ? checked.value : 'operator';
        }

        function applyRoleClass() {
            var role = currentRole();
            form.classList.remove('role-owner', 'role-operator');
            form.classList.add('role-' + role);
            roleCards.forEach(function(card) {
                card.classList.toggle('active', card.dataset.role === role);
            });
        }

        function showPanel(name) {
            panels.forEach(function(p) {
                p.classList.toggle('active', p.dataset.panel === name);
            });
            var currentIndex = order.indexOf(name);
            stepEls.forEach(function(s) {
                var stepIndex = order.indexOf(s.dataset.step);
                s.classList.toggle('active', stepIndex === currentIndex);
                s.classList.toggle('done', stepIndex !== -1 && stepIndex < currentIndex);
            });
        }

        function panelIsValid(panel) {
            var fields = panel.querySelectorAll('input, select, textarea');
            for (var i = 0; i < fields.length; i++) {
                if (fields[i].offsetParent !== null && !fields[i].checkValidity()) {
                    fields[i].reportValidity();
                    return false;
                }
            }
            return true;
        }

        roleCards.forEach(function(card) {
            card.addEventListener('click', function() {
                var input = card.querySelector('input[name="role"]');
                input.checked = true;
                applyRoleClass();
            });
        });

        form.querySelectorAll('.reg-next').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = btn.closest('.reg-panel');
                if (!panelIsValid(panel)) return;
                showPanel('done');
            });
        });

        form.querySelectorAll('.reg-back').forEach(function(btn) {
            btn.addEventListener('click', function() {
                showPanel('1');
            });
        });

        applyRoleClass();

        <?php if ($error): ?>
        // Если сервер вернул ошибку — не прячем ничего за шагами, показываем
        // всю форму сразу, чтобы было видно, что именно нужно исправить.
        panels.forEach(function(p) { p.classList.add('active'); });
        form.classList.add('show-all');
        <?php endif; ?>
    });
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
