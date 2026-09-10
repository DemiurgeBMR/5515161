<?php
session_start(); // ДОЛЖНО БЫТЬ ПЕРВОЙ СТРОКОЙ ПОСЛЕ ОТКРЫВАЮЩЕГО ТЕГА!
require_once '../config.php';

// Если пользователь уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: /pages/profile.php');
    exit;
}

$error = '';

$space_types = [
    'retail'     => 'Торговый центр / Магазин',
    'office'     => 'Бизнес-центр / Офис',
    'gym'        => 'Спортзал / Фитнес-клуб',
    'hotel'      => 'Отель / Гостиница',
    'hospital'   => 'Больница / Медицинский центр',
    'transit'    => 'Вокзал / Аэропорт',
    'coworking'  => 'Коворкинг',
    'laundromat' => 'Прачечная / Химчистка',
    'auto'       => 'Автосалон / СТО',
    'warehouse'  => 'Склад / Логистика',
    'factory'    => 'Завод / Производство',
    'education'  => 'Учебное заведение (школа, вуз)',
    'cinema'     => 'Кинотеатр / Развлекательный центр',
    'cafe'       => 'Кафе / Ресторан',
    'bank'       => 'Банк / Финансовое учреждение',
    'post'       => 'Почта / Отделение связи',
    'park'       => 'Парк / Сквер',
    'stadium'    => 'Стадион / Спорткомплекс',
    'museum'     => 'Музей / Выставочный центр',
    'other'      => 'Другое',
];
$trafficLabels = [
    1 => 'Низкая — до 200 чел/день',
    2 => 'Ниже среднего — 200–500 чел/день',
    3 => 'Средняя — 500–3 000 чел/день',
    4 => 'Высокая — 3 000–10 000 чел/день',
    5 => 'Максимальная — от 10 000 чел/день',
];

// Значения для повторного заполнения формы после ошибки — прямо на том же
// шаге, где пользователь остановился, ничего вводить заново не нужно.
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$role = $_POST['role'] ?? ((($_GET['role'] ?? '') === 'owner') ? 'owner' : 'operator');
if (!in_array($role, ['owner', 'operator'], true)) {
    $role = 'operator';
}

$loc_title = trim($_POST['loc_title'] ?? '');
$loc_city = trim($_POST['loc_city'] ?? '');
$loc_address = trim($_POST['loc_address'] ?? '');
$loc_space_type = $_POST['loc_space_type'] ?? '';
$loc_traffic = (int)($_POST['loc_traffic'] ?? 0);
$loc_price = $_POST['loc_price'] ?? '';
$loc_description = trim($_POST['loc_description'] ?? '');
$isResubmit = $_SERVER['REQUEST_METHOD'] === 'POST';
$loc_electricity = $isResubmit ? isset($_POST['loc_electricity']) : true;
$loc_wifi = $isResubmit ? isset($_POST['loc_wifi']) : false;
$loc_water = $isResubmit ? isset($_POST['loc_water']) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if (empty($full_name) || empty($email) || empty($password)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email адрес';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } elseif ($role === 'owner' && (empty($loc_title) || empty($loc_city) || empty($loc_address) || (float)$loc_price <= 0)) {
        $error = 'Заполните обязательные поля о локации: название, город, адрес и цена';
    } else {
        try {
            $pdo = getDbConnection();

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Этот email уже зарегистрирован';
            } else {
                $pdo->beginTransaction();

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password, full_name, phone, role)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$email, $hashed_password, $full_name, $phone, $role]);
                $user_id = $pdo->lastInsertId();

                if ($role === 'owner') {
                    $price_month = (float)$loc_price;
                    $traffic_rating = ($loc_traffic >= 1 && $loc_traffic <= 5) ? $loc_traffic : 0;
                    $space_type_val = $loc_space_type !== '' && isset($space_types[$loc_space_type]) ? $loc_space_type : null;

                    // Заводим локацию сразу неактивной/непромодерированной — точно
                    // так же, как это делает pages/add_location.php, чтобы админ
                    // видел и одобрял её через тот же механизм ревизий.
                    $stmt = $pdo->prepare("
                        INSERT INTO locations
                        (owner_id, title, address, city, description, price_month, width, height, depth,
                         has_electricity, has_wifi, has_water, access_hours, traffic_rating, space_type, is_moderated, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, '24/7', ?, ?, 0, 0)
                    ");
                    $stmt->execute([
                        $user_id, $loc_title, $loc_address, $loc_city, $loc_description, $price_month,
                        $loc_electricity ? 1 : 0, $loc_wifi ? 1 : 0, $loc_water ? 1 : 0,
                        $traffic_rating, $space_type_val,
                    ]);
                    $location_id = $pdo->lastInsertId();

                    $revisionData = [
                        'title' => $loc_title,
                        'address' => $loc_address,
                        'city' => $loc_city,
                        'description' => $loc_description,
                        'price_month' => $price_month,
                        'width' => 0,
                        'height' => 0,
                        'depth' => 0,
                        'has_electricity' => $loc_electricity ? 1 : 0,
                        'has_wifi' => $loc_wifi ? 1 : 0,
                        'has_water' => $loc_water ? 1 : 0,
                        'access_hours' => '24/7',
                        'traffic_rating' => $traffic_rating,
                        'space_type' => $space_type_val,
                    ];
                    $geo = geocodeAddress($loc_address, $loc_city);
                    if ($geo) {
                        $revisionData['latitude'] = $geo['lat'];
                        $revisionData['longitude'] = $geo['lng'];
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO location_revisions (location_id, data, status)
                        VALUES (?, ?, 'pending')
                    ");
                    $stmt->execute([$location_id, json_encode($revisionData)]);
                }

                $pdo->commit();

                session_regenerate_id(true); // новая сессия для только что созданного пользователя
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_role'] = $role;
                $_SESSION['has_subscription'] = 0;

                $_SESSION['flash'] = $role === 'owner'
                    ? 'Добро пожаловать! Локация отправлена на модерацию — как только её одобрят, она появится в каталоге. Фото и точные размеры можно добавить в любой момент в разделе «Мои локации».'
                    : 'Добро пожаловать! Загляните в каталог, чтобы найти подходящую точку для размещения.';

                header('Location: /pages/profile.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
                    <div class="reg-step-line owner-only"></div>
                    <div class="reg-step owner-only" data-step="2">
                        <span class="reg-step-num">2</span>
                        <span class="reg-step-label">О локации</span>
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

                <!-- Шаг 2: локация (только владелец) -->
                <div class="reg-panel owner-only" data-panel="2">
                    <h2>О локации</h2>
                    <p class="reg-panel-sub">
                        Коротко опишите место — фото, точные размеры и другие детали можно добавить
                        позже в личном кабинете.
                    </p>

                    <div class="form-group">
                        <label>Название локации *</label>
                        <input type="text" name="loc_title" required placeholder="Например: ТЦ Мега, 1 этаж" value="<?php echo htmlspecialchars($loc_title); ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Город *</label>
                            <input type="text" name="loc_city" required placeholder="Симферополь" value="<?php echo htmlspecialchars($loc_city); ?>">
                        </div>
                        <div class="form-group">
                            <label>Адрес *</label>
                            <input type="text" name="loc_address" required placeholder="ул. Пушкина, 1" value="<?php echo htmlspecialchars($loc_address); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Тип помещения</label>
                            <select name="loc_space_type">
                                <option value="">Не выбран</option>
                                <?php foreach ($space_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $loc_space_type === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Проходимость</label>
                            <select name="loc_traffic">
                                <option value="0">Не оценивал(а)</option>
                                <?php foreach ($trafficLabels as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo $loc_traffic === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Цена аренды в месяц (₽) *</label>
                        <input type="number" name="loc_price" required min="1" step="1" placeholder="5000" value="<?php echo htmlspecialchars($loc_price); ?>">
                    </div>
                    <div class="form-group">
                        <label>Что есть на месте</label>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="loc_electricity" <?php echo $loc_electricity ? 'checked' : ''; ?>> ⚡ Электричество
                            </label>
                            <label>
                                <input type="checkbox" name="loc_wifi" <?php echo $loc_wifi ? 'checked' : ''; ?>> 📶 Wi-Fi
                            </label>
                            <label>
                                <input type="checkbox" name="loc_water" <?php echo $loc_water ? 'checked' : ''; ?>> 🚰 Вода
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Описание (необязательно)</label>
                        <textarea name="loc_description" placeholder="Проходимость, соседи, особенности места..."><?php echo htmlspecialchars($loc_description); ?></textarea>
                    </div>

                    <div class="reg-panel-actions">
                        <button type="button" class="reg-btn-back reg-back">← Назад</button>
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
                        <li><span class="reg-done-icon">🛡️</span> Локация отправится на модерацию — администратор проверит её перед публикацией в каталоге.</li>
                        <li><span class="reg-done-icon">📩</span> Заинтересованные операторы будут писать вам прямо в чате на платформе.</li>
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
            var order = currentRole() === 'owner' ? ['1', '2', 'done'] : ['1', 'done'];
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
                showPanel('1');
            });
        });

        form.querySelectorAll('.reg-next').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = btn.closest('.reg-panel');
                if (!panelIsValid(panel)) return;
                var next = (panel.dataset.panel === '1' && currentRole() === 'owner') ? '2' : 'done';
                showPanel(next);
            });
        });

        form.querySelectorAll('.reg-back').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = btn.closest('.reg-panel');
                var prev = (panel.dataset.panel === 'done' && currentRole() === 'owner') ? '2' : '1';
                showPanel(prev);
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
