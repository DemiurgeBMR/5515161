<?php
/**
 * «Мои брони»: гость входит по коду из письма — без пароля и регистрации —
 * и видит все брони, оставленные на эту почту.
 */

require __DIR__ . '/../src/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');

if (!db_ready()) {
    require __DIR__ . '/../src/partials/maintenance.php';
    exit;
}

session_boot();
$h = hotel();
$error = null;
$step = 'email';
$email = mb_strtolower(post('email'));

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'send') {
        $error = guest_send_code($email);
        if ($error === null) {
            $_SESSION['code_email'] = $email;
            $step = 'code';
        }
    } elseif ($action === 'verify') {
        $email = (string) ($_SESSION['code_email'] ?? '');
        $error = $email !== '' ? guest_verify_code($email, post('code')) : 'Начните заново — укажите почту.';
        $step = $error === null ? 'list' : 'code';
        if ($error === null) {
            redirect('/account.php');
        }
    } elseif ($action === 'logout') {
        guest_logout();
        redirect('/account.php');
    } elseif ($action === 'restart') {
        unset($_SESSION['code_email']);
    }
}

$guestEmail = current_guest_email();
$bookings = [];
if ($guestEmail) {
    $step = 'list';
    $bookings = array_map('booking_hydrate', q_all(booking_select_sql() . ' WHERE g.email = ? ORDER BY b.check_in DESC', [$guestEmail]));
} elseif ($step === 'email' && !empty($_SESSION['code_email']) && !is_post()) {
    $step = 'code';
}
$codeEmail = (string) ($_SESSION['code_email'] ?? '');

$page = [
    'title'       => 'Мои брони — ' . $h['name'],
    'description' => 'Ваши брони в гостинице ' . $h['name'] . ': статус, даты, связь с администратором.',
    'path'        => '/account.php',
    'noindex'     => true,
    'analytics'   => false,
];
require __DIR__ . '/../src/partials/head.php';
require __DIR__ . '/../src/partials/header.php';
?>
<main id="main" class="doc">
    <div class="container doc__inner">
        <a class="doc__back" href="/"><?= icon('arrow-right') ?>На главную</a>
        <h1 class="display">Мои брони</h1>

<?php if ($step === 'list'): ?>
        <p class="doc__meta">Вы вошли как <?= e($guestEmail) ?>.</p>
<?php if (!$bookings): ?>
        <p class="lead">На эту почту броней нет. Если бронировали по телефону — администратор может добавить почту в вашу бронь.</p>
<?php endif; ?>
        <ul class="acc-list">
<?php foreach ($bookings as $b): ?>
            <li class="acc-item card">
                <a href="/booking.php?t=<?= e($b['token']) ?>">
                    <span class="acc-item__dates"><?= e(ru_date_range($b['check_in'], $b['check_out'])) ?></span>
                    <span class="acc-item__meta"><?= e($b['type_name']) ?> · <?= e(nights_label($b['nights'])) ?> · Бронь <?= e(booking_number($b)) ?></span>
                </a>
                <span class="status status--<?= e($b['status']) ?>"><?= e($b['status_label']) ?></span>
            </li>
<?php endforeach; ?>
        </ul>
        <p><a class="btn btn--primary" href="/#booking">Новая бронь</a></p>
        <form method="post" class="acc-logout">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="logout">
            <button class="btn btn--outline btn--sm" type="submit">Выйти</button>
        </form>

<?php elseif ($step === 'code'): ?>
        <p class="lead">Если на <?= e($codeEmail) ?> есть брони, мы отправили туда код из 6 цифр. Письмо может прийти через минуту-две — проверьте и папку «Спам».</p>
<?php if ($error): ?>
        <div class="form-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
        <form method="post" class="acc-form card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <div class="field">
                <label for="a-code">Код из письма</label>
                <input id="a-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Войти</button>
        </form>
        <form method="post" class="acc-restart">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restart">
            <button class="link-button" type="submit">Указать другую почту</button>
        </form>

<?php else: ?>
        <p class="lead">Здесь — все ваши брони, оставленные с указанием почты: статус, даты и связь с администратором. Пароль не нужен — пришлём код на почту.</p>
<?php if ($error): ?>
        <div class="form-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
        <form method="post" class="acc-form card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="field">
                <label for="a-email">Почта, которую вы указали в заявке</label>
                <input id="a-email" type="email" name="email" autocomplete="email" inputmode="email" required value="<?= e($email) ?>" autofocus>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Получить код</button>
        </form>
        <p class="muted">Бронировали по телефону и почту не указывали? Ссылку на бронь может прислать администратор: <a href="<?= e(tel_href()) ?>"><?= e($h['phone']) ?></a>.</p>
<?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
