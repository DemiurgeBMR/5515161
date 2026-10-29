<?php
/**
 * Вход в админку. Пока в базе нет ни одного сотрудника — здесь же
 * создаётся владелец: с этого компьютера сразу, на хостинге — по ссылке
 * с ключом из config/secrets.php (setup_key).
 */

require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';

if (!db_ready()) {
    http_response_code(503);
    echo 'База данных недоступна. Запустите php tools/migrate.php и проверьте config/secrets.php.';
    exit;
}

session_boot();
$next = get('next', post('next', '/admin/'));
if (!preg_match('#^/admin/[\w./?=&%-]*$#', $next)) {
    $next = '/admin/'; // только внутренние адреса админки — без перехода на чужие сайты
}
if (current_staff()) {
    redirect($next);
}

$noStaff = !q_val('SELECT id FROM staff LIMIT 1');
$setupKey = (string) hotel_secrets()['setup_key'];
$setupAllowed = $noStaff && (is_local_request() || ($setupKey !== '' && hash_equals($setupKey, get('setup', post('setup')))));
$error = null;
$email = post('email');

if (is_post()) {
    csrf_check();
    if (post('action') === 'setup' && $setupAllowed) {
        if (post('password') !== post('password2')) {
            $error = 'Пароли не совпадают';
        } else {
            try {
                staff_create(post('email'), post('name'), 'owner', post('password'));
                $r = staff_login(post('email'), post('password'));
                if (is_array($r)) {
                    flash('ok', 'Добро пожаловать! Начните с раздела «Номера и фото»: заведите настоящие комнаты и загрузите фотографии.');
                    redirect('/admin/');
                }
                $error = $r;
            } catch (BookingError $e) {
                $error = $e->getMessage();
            }
        }
    } elseif (post('action') === 'login') {
        $r = staff_login($email, post('password'));
        if (is_array($r)) {
            redirect($next);
        }
        $error = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — админка <?= e(mail_hotel_name()) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="adm">
<?php readfile(HOTEL_ROOT . '/src/partials/icons.svg'); ?>
<main class="adm-login">
    <div class="adm-login__card">
<?php if ($noStaff && $setupAllowed): ?>
        <h1>Создайте владельца</h1>
        <p class="hint">Это первый вход: в базе ещё нет сотрудников. Владелец видит всё и может добавлять администраторов.</p>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setup">
            <input type="hidden" name="setup" value="<?= e(get('setup', post('setup'))) ?>">
            <div class="field span-all"><label for="s-name">Ваше имя</label><input id="s-name" name="name" required value="<?= e(post('name')) ?>" autocomplete="name"></div>
            <div class="field span-all"><label for="s-email">Почта — она же логин</label><input id="s-email" type="email" name="email" required value="<?= e($email) ?>" autocomplete="username"></div>
            <?= password_field('s-pass', 'password', 'Пароль (не короче 8 символов)', 'new-password', true, 8) ?>
            <?= password_field('s-pass2', 'password2', 'Пароль ещё раз', 'new-password', true, 8) ?>
            <button class="btn btn--primary btn--block span-all" type="submit">Создать и войти</button>
        </form>
<?php elseif ($noStaff): ?>
        <h1>Админка ещё не настроена</h1>
        <p>В базе нет ни одного сотрудника. Создайте владельца одним из способов:</p>
        <ul class="hint">
            <li>в консоли сервера: <code>php tools/admin.php add почта "Имя"</code>;</li>
            <li>или впишите в <code>config/secrets.php</code> ключ <code>setup_key</code> и откройте <code>/admin/login.php?setup=ВАШ_КЛЮЧ</code>.</li>
        </ul>
<?php else: ?>
        <h1>Вход</h1>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="field span-all"><label for="l-email">Почта</label><input id="l-email" type="email" name="email" required value="<?= e($email) ?>" autocomplete="username" autofocus></div>
            <?= password_field('l-pass', 'password', 'Пароль', 'current-password') ?>
            <button class="btn btn--primary btn--block span-all" type="submit">Войти</button>
        </form>
        <p class="hint">Забыли пароль? Попросите владельца сбросить его в разделе «Сотрудники» или выполните в консоли сервера <code>php tools/admin.php password ваша@почта</code>.</p>
<?php endif; ?>
        <p class="hint"><a href="/">← На сайт</a></p>
    </div>
</main>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body>
</html>
