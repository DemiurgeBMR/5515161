<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Публичная страница тарифов — видна всем, включая гостя, ещё не выбравшего
// роль при регистрации, а не спрятана внутри личного кабинета. Покупать
// контакты/тарифы может только оператор — проверяется отдельно, ниже,
// перед обработкой каждого POST. "Сделка под ключ" — разовая услуга,
// доступна и оператору, и собственнику (обе стороны сделки).
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? null;
$isOperator = $role === 'operator';
$canOrderTurnkey = $isOperator || $role === 'owner';

$pdo = getDbConnection();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$creditPacks = rr_credit_packs();
$recurringPlans = rr_recurring_plans();
$turnkeyPrice = rr_turnkey_deal_price();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = 'Не удалось подтвердить запрос, попробуйте ещё раз.';
        header('Location: /pages/subscription.php');
        exit;
    }

    if (isset($_POST['plan']) && $isOperator) {
        $planKey = $_POST['plan'];
        $newEndDate = rr_purchase_subscription($pdo, $user_id, $planKey);
        if ($newEndDate === false) {
            $_SESSION['flash'] = 'Неизвестный тариф.';
        } else {
            $_SESSION['flash'] = 'Тариф «' . $recurringPlans[$planKey]['label'] . '» оформлен. Действует до '
                . formatDateRu($newEndDate) . '.';
        }
    } elseif (isset($_POST['pack']) && $isOperator) {
        $packKey = $_POST['pack'];
        if (!isset($creditPacks[$packKey])) {
            $_SESSION['flash'] = 'Неизвестный пакет.';
        } else {
            $pack = $creditPacks[$packKey];
            rr_grant_credits($pdo, $user_id, $packKey, $pack['credits'], $pack['price']);
            $_SESSION['flash'] = 'Пакет «' . $pack['label'] . '» оформлен — кредиты уже на балансе.';
        }
    } elseif (isset($_POST['turnkey_deal']) && $canOrderTurnkey) {
        $pdo->prepare("
            INSERT INTO service_orders (user_id, service, price)
            VALUES (?, 'turnkey_deal', ?)
        ")->execute([$user_id, $turnkeyPrice]);
        $_SESSION['flash'] = 'Заявка на «Сделку под ключ» принята — с вами свяжется наша команда.';
    }
    // Не-оператор, отправивший plan/pack POST'ом в обход интерфейса — тихо
    // игнорируем вместо продажи не той роли.

    header('Location: /pages/subscription.php');
    exit;
}

$creditsSummary = $isOperator ? rr_credits_summary($pdo, $user_id) : null;
$backLink = $user_id ? rr_login_redirect_url($role) : '/';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тарифы — RR</title>
    <meta name="description" content="Тарифы RR для операторов вендинга: бесплатный контакт при регистрации, пакеты контактов, тариф с помесячной квотой, разблокировка адреса локации.">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <a href="<?php echo htmlspecialchars($backLink); ?>" class="back-link">← Назад</a>
        <h2><?php echo rr_icon('card'); ?> Тарифы</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($isOperator): ?>
            <div class="subscription-status-banner <?php echo $creditsSummary['total_available'] > 0 ? 'active' : 'inactive'; ?>">
                <?php echo rr_icon('card'); ?>
                Доступно контактов: <strong><?php echo $creditsSummary['total_available']; ?></strong>
                <?php if ($creditsSummary['subscription']): ?>
                    (из них <?php echo $creditsSummary['monthly_remaining']; ?> из тарифа «<?php echo htmlspecialchars($creditsSummary['plan_label']); ?>» до <?php echo formatDateRu($creditsSummary['subscription']['end_date']); ?>)
                <?php endif; ?>
            </div>
        <?php elseif ($role === 'owner'): ?>
            <div class="subscription-status-banner active">
                <?php echo rr_icon('check'); ?> Как собственнику, платные контакты вам не нужны — карта и точный адрес по вашим
                собственным локациям уже доступны без них.
            </div>
        <?php elseif ($role === 'admin'): ?>
            <div class="subscription-status-banner active">
                <?php echo rr_icon('check'); ?> У администратора уже полный доступ ко всем функциям сайта.
            </div>
        <?php endif; ?>

        <p class="subscription-description">
            Оплата — за контакт, а не за время: 1 разблокировка открывает точный адрес и контакт собственника
            ОДНОЙ конкретной локации навсегда, даже если потом кредиты закончатся. При регистрации оператор сразу
            получает 1 бесплатный контакт. Пока без реальной оплаты — оформление сразу зачисляет кредиты/тариф.
        </p>

        <h3 class="subscription-section-title"><?php echo rr_icon('mail'); ?> Разовые пакеты контактов</h3>
        <p class="subscription-section-sub">Не сгорают — копятся на балансе сколько угодно.</p>
        <div class="plan-cards">
            <?php foreach ($creditPacks as $packKey => $pack): ?>
                <?php $perContact = round($pack['price'] / $pack['credits']); ?>
                <div class="plan-card">
                    <div class="plan-card-label"><?php echo htmlspecialchars($pack['label']); ?></div>
                    <div class="plan-card-price"><?php echo number_format($pack['price'], 0, ',', ' '); ?> ₽</div>
                    <div class="plan-card-per-month">≈ <?php echo number_format($perContact, 0, ',', ' '); ?> ₽/контакт</div>

                    <?php if ($isOperator): ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="pack" value="<?php echo htmlspecialchars($packKey); ?>">
                            <button type="submit" class="btn-submit">Купить</button>
                        </form>
                    <?php elseif (!$user_id): ?>
                        <a href="/pages/login.php" class="btn-submit">Войти, чтобы купить</a>
                    <?php else: ?>
                        <div class="plan-card-unavailable">Доступно только оператору</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="subscription-section-title"><?php echo rr_icon('card'); ?> Тарифы с помесячной квотой</h3>
        <p class="subscription-section-sub">Неиспользованные разблокировки в конце месяца сгорают — квота не переносится.</p>
        <div class="plan-cards">
            <?php foreach ($recurringPlans as $planKey => $plan): ?>
                <?php $isBestValue = $planKey === 'operator_yearly'; ?>
                <div class="plan-card<?php echo $isBestValue ? ' best-value' : ''; ?>">
                    <?php if ($isBestValue): ?>
                        <div class="plan-card-badge">Выгоднее всего</div>
                    <?php endif; ?>
                    <div class="plan-card-label"><?php echo htmlspecialchars($plan['label']); ?></div>
                    <div class="plan-card-price"><?php echo number_format($plan['price'], 0, ',', ' '); ?> ₽<?php echo $plan['months'] > 1 ? '/год' : '/мес'; ?></div>
                    <div class="plan-card-per-month"><?php echo $plan['monthly_allowance']; ?> разблокировок в месяц</div>

                    <?php if ($isOperator): ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="plan" value="<?php echo htmlspecialchars($planKey); ?>">
                            <button type="submit" class="btn-submit">
                                <?php echo $creditsSummary['subscription'] ? 'Продлить' : 'Оформить'; ?>
                            </button>
                        </form>
                    <?php elseif (!$user_id): ?>
                        <a href="/pages/login.php" class="btn-submit">Войти, чтобы оформить</a>
                    <?php else: ?>
                        <div class="plan-card-unavailable">Доступно только оператору</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="subscription-section-title"><?php echo rr_icon('file-text'); ?> Сделка под ключ</h3>
        <p class="subscription-section-sub">Разовая услуга нашей команды: договор, акт, проверка условий сделки — не автоматическая функция сайта, заявку обрабатывает менеджер.</p>
        <div class="plan-cards plan-cards-single">
            <div class="plan-card">
                <div class="plan-card-label">Сделка под ключ</div>
                <div class="plan-card-price"><?php echo number_format($turnkeyPrice, 0, ',', ' '); ?> ₽</div>
                <div class="plan-card-per-month">разово</div>

                <?php if ($canOrderTurnkey): ?>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="turnkey_deal" value="1">
                        <button type="submit" class="btn-submit">Заказать</button>
                    </form>
                <?php elseif (!$user_id): ?>
                    <a href="/pages/login.php" class="btn-submit">Войти, чтобы заказать</a>
                <?php else: ?>
                    <div class="plan-card-unavailable">Недоступно для этой роли</div>
                <?php endif; ?>
            </div>
        </div>

        <ul class="subscription-benefits subscription-benefits-footer">
            <li><?php echo rr_icon('map-pin'); ?> Интерактивная карта с точками локаций — открывается любой покупкой (без покупок видно только «город → сколько точек»)</li>
            <li><?php echo rr_icon('lock'); ?> Точный адрес и контакт собственника — за 1 разблокировку на каждую локацию</li>
            <li><?php echo rr_icon('mail'); ?> Отправка заявки собственнику — доступна сразу после разблокировки его локации</li>
        </ul>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
