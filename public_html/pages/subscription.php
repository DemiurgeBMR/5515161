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

    if ((isset($_POST['plan']) || isset($_POST['pack'])) && $isOperator) {
        $kind = isset($_POST['plan']) ? 'plan' : 'pack';
        $itemKey = $kind === 'plan' ? $_POST['plan'] : $_POST['pack'];

        if (!rr_payment_item($kind, (string) $itemKey)) {
            $_SESSION['flash'] = $kind === 'plan' ? 'Неизвестный тариф.' : 'Неизвестный пакет.';
        } elseif (!rr_payments_enabled()) {
            $_SESSION['flash'] = 'Оплата на сайте пока недоступна. Напишите на ' . CONTACT_EMAIL . ', и мы оформим покупку вручную.';
        } elseif (!rr_check_rate_limit($pdo, 'create_payment:' . $user_id, 10, 600)) {
            $_SESSION['flash'] = 'Слишком много попыток оплаты подряд. Попробуйте через несколько минут.';
        } else {
            $confirmUrl = rr_create_payment($pdo, $user_id, $kind, (string) $itemKey);
            if ($confirmUrl) {
                header('Location: ' . $confirmUrl);
                exit;
            }
            $_SESSION['flash'] = 'Не удалось создать платёж. Попробуйте ещё раз чуть позже.';
        }
    } elseif (isset($_POST['turnkey_deal']) && $canOrderTurnkey) {
        $pdo->prepare("
            INSERT INTO service_orders (user_id, service, price)
            VALUES (?, 'turnkey_deal', ?)
        ")->execute([$user_id, $turnkeyPrice]);
        $orderId = $pdo->lastInsertId();

        // Раньше на этом всё и заканчивалось — заявка просто лежала в
        // service_orders, никто (ни админ, ни сам заказчик) об этом не
        // узнавал, кроме как заглянув в базу напрямую.
        rr_notify_admins(
            $pdo,
            'service_order_new',
            'Новая заявка «Сделка под ключ» от ' . ($_SESSION['user_name'] ?? 'пользователя') . ' — ' . number_format($turnkeyPrice, 0, ',', ' ') . ' ₽',
            '/admin/service_orders.php'
        );

        // Раньше после оформления просто оставались на этой же странице с
        // флеш-сообщением — вместо переговоров с командой заказчик видел
        // только текст-квитанцию. Теперь сразу открывается чат по заявке.
        header('Location: /pages/service_order_chat.php?order_id=' . $orderId);
        exit;
    }
    // Не-оператор, отправивший plan/pack POST'ом в обход интерфейса — тихо
    // игнорируем вместо продажи не той роли.

    header('Location: /pages/subscription.php');
    exit;
}

$creditsSummary = $isOperator ? rr_credits_summary($pdo, $user_id) : null;
$backLink = $user_id ? rr_login_redirect_url($role) : '/';

// Собственные заказы "Сделка под ключ" — чтобы после оформления не
// казалось, что заявка ушла в никуда: видно статус и, если админ оставил
// комментарий, его текст.
$myTurnkeyOrders = [];
if ($canOrderTurnkey) {
    $stmt = $pdo->prepare("
        SELECT id, price, status, note, created_at, updated_at
        FROM service_orders
        WHERE user_id = ? AND service = 'turnkey_deal'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $myTurnkeyOrders = $stmt->fetchAll();
}

$turnkeyStatusLabels = [
    'new'         => 'Новая',
    'in_progress' => 'В обработке',
    'done'        => 'Выполнена',
    'cancelled'   => 'Отменена',
];

// ----- Телефонная версия (блоки .m-only): выгодный пакет и история операций -----
// Пакет с минимальной ценой за контакт — «Лучшая цена». Цифры считаются из тех же
// rr_credit_packs(), что и сами карточки, так что подпись не может разойтись с прайсом.
$bestPackKey = null;
$bestPerContact = null;
foreach ($creditPacks as $k => $pk) {
    $pc = $pk['price'] / max(1, $pk['credits']);
    if ($bestPerContact === null || $pc < $bestPerContact) {
        $bestPerContact = $pc;
        $bestPackKey = $k;
    }
}

// История оператора: покупки пакетов, оформленные тарифы и траты контактов на разблокировку
// адресов — одним списком, новые сверху. Только чтение; любая ошибка = пустая история.
$mHistory = [];
if ($isOperator) {
    try {
        $stmt = $pdo->prepare("SELECT source, credits_granted, price_paid, created_at FROM credit_purchases WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $r) {
            $isFree = $r['source'] === 'free_grant';
            $mHistory[] = [
                'ts'    => strtotime($r['created_at']),
                'icon'  => $isFree ? 'plus-circle' : 'card',
                'title' => $isFree ? 'Бесплатный контакт' : 'Пакет «' . ($creditPacks[$r['source']]['label'] ?? $r['credits_granted'] . ' контактов') . '»',
                'sub'   => formatDateRu($r['created_at']) . ' · +' . (int) $r['credits_granted'] . ' ' . rr_plural_ru($r['credits_granted'], 'контакт', 'контакта', 'контактов'),
                'end'   => $isFree || (float) $r['price_paid'] <= 0 ? 'Бесплатно' : number_format($r['price_paid'], 0, ',', ' ') . ' ₽',
            ];
        }

        $stmt = $pdo->prepare("SELECT plan, price_paid, start_date, end_date FROM subscriptions WHERE user_id = ? ORDER BY start_date DESC, id DESC LIMIT 20");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $r) {
            $mHistory[] = [
                'ts'    => strtotime($r['start_date']),
                'icon'  => 'calendar',
                'title' => 'Тариф «' . ($recurringPlans[$r['plan']]['label'] ?? $r['plan']) . '»',
                'sub'   => formatDateRu($r['start_date']) . ' · до ' . formatDateRu($r['end_date']),
                'end'   => number_format($r['price_paid'], 0, ',', ' ') . ' ₽',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT lu.source, lu.unlocked_at, l.title
            FROM location_unlocks lu
            JOIN locations l ON l.id = lu.location_id
            WHERE lu.operator_id = ?
            ORDER BY lu.unlocked_at DESC LIMIT 20
        ");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $r) {
            $mHistory[] = [
                'ts'    => strtotime($r['unlocked_at']),
                'icon'  => 'unlock',
                'title' => $r['title'],
                'sub'   => formatDateRu($r['unlocked_at']) . ' · адрес открыт',
                'end'   => $r['source'] === 'subscription_allowance' ? 'из квоты' : '−1 контакт',
            ];
        }
    } catch (Throwable $e) {
        $mHistory = [];
    }
    usort($mHistory, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    $mHistory = array_slice($mHistory, 0, 20);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Тарифы — RR</title>
    <meta name="description" content="Тарифы RR для операторов вендинга: бесплатный контакт при регистрации, пакеты контактов, тариф с помесячной квотой, разблокировка адреса локации.">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="subscription-container">
        <a href="<?php echo htmlspecialchars($backLink); ?>" class="back-link m-hide">← Назад</a>
        <h2><?php echo rr_icon('card'); ?> Тарифы</h2>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <?php if ($isOperator): ?>
            <!-- Телефон: акцентная карточка баланса контактов (на десктопе скрыта — там плашка ниже) -->
            <div class="m-only m-balance <?php echo $creditsSummary['total_available'] > 0 ? '' : 'is-empty'; ?>">
                <div class="m-balance-label">Доступно контактов</div>
                <div class="m-balance-num"><?php echo (int) $creditsSummary['total_available']; ?></div>
                <div class="m-balance-meta">
                    <span class="m-pill">Бессрочных: <?php echo (int) $creditsSummary['permanent_balance']; ?></span>
                    <?php if ($creditsSummary['subscription']): ?>
                        <span class="m-pill is-info">По тарифу «<?php echo htmlspecialchars($creditsSummary['plan_label']); ?>»: <?php echo (int) $creditsSummary['monthly_remaining']; ?> из <?php echo (int) $creditsSummary['monthly_allowance']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="m-balance-hint">
                    <?php if ($creditsSummary['subscription']): ?>
                        Тариф действует до <?php echo formatDateRu($creditsSummary['subscription']['end_date']); ?>. Один контакт открывает точный адрес и контакт собственника одной локации — навсегда.
                    <?php else: ?>
                        Один контакт открывает точный адрес и контакт собственника одной локации — навсегда.
                    <?php endif; ?>
                </div>
            </div>
            <div class="m-hide subscription-status-banner <?php echo $creditsSummary['total_available'] > 0 ? 'active' : 'inactive'; ?>">
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

        <?php if (!$user_id): ?>
            <!-- Телефон: состояние гостя — войти или зарегистрироваться (на десктопе скрыто) -->
            <div class="m-only m-guest-cta">
                <div class="m-guest-cta-title">Тарифы для операторов</div>
                <div class="m-guest-cta-text">Войдите или зарегистрируйтесь, чтобы купить контакты и открывать адреса локаций. Первый контакт — бесплатно при регистрации.</div>
                <div class="m-btn-row">
                    <a href="/pages/login.php" class="m-btn m-btn--ghost">Войти</a>
                    <a href="/pages/register.php?role=operator" class="m-btn">Регистрация</a>
                </div>
            </div>
        <?php endif; ?>

        <p class="subscription-description">
            Оплата — за контакт, а не за время: 1 разблокировка открывает точный адрес и контакт собственника
            ОДНОЙ конкретной локации навсегда, даже если потом кредиты закончатся. При регистрации оператор сразу
            получает 1 бесплатный контакт. Оплата — картой или через СБП на защищённой странице ЮKassa, контакты зачисляются сразу после оплаты.
        </p>

        <h3 class="subscription-section-title"><?php echo rr_icon('mail'); ?> Разовые пакеты контактов</h3>
        <p class="subscription-section-sub">Не сгорают — копятся на балансе сколько угодно.</p>
        <div class="plan-cards">
            <?php foreach ($creditPacks as $packKey => $pack): ?>
                <?php $perContact = round($pack['price'] / $pack['credits']); ?>
                <div class="plan-card<?php echo $packKey === $bestPackKey ? ' m-best' : ''; ?>">
                    <?php if ($packKey === $bestPackKey): ?>
                        <div class="plan-card-badge m-only">Лучшая цена за контакт</div>
                    <?php endif; ?>
                    <div class="plan-card-label"><?php echo htmlspecialchars($pack['label']); ?></div>
                    <div class="plan-card-price"><?php echo number_format($pack['price'], 0, ',', ' '); ?> ₽</div>
                    <div class="plan-card-per-month">≈ <?php echo number_format($perContact, 0, ',', ' '); ?> ₽/контакт</div>
                    <ul class="m-plan-list m-only">
                        <li><?php echo rr_icon('check'); ?> <?php echo (int) $pack['credits']; ?> <?php echo rr_plural_ru($pack['credits'], 'разблокировка', 'разблокировки', 'разблокировок'); ?> точного адреса и контакта</li>
                        <li><?php echo rr_icon('check'); ?> Не сгорают — копятся на балансе</li>
                    </ul>

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
                    <ul class="m-plan-list m-only">
                        <li><?php echo rr_icon('check'); ?> <?php echo (int) $plan['monthly_allowance']; ?> <?php echo rr_plural_ru($plan['monthly_allowance'], 'разблокировка', 'разблокировки', 'разблокировок'); ?> адреса в месяц</li>
                        <li><?php echo rr_icon('check'); ?> Квота обновляется каждый месяц, остаток не переносится</li>
                        <?php if ($plan['months'] > 1): ?>
                            <li><?php echo rr_icon('check'); ?> Доступ на <?php echo (int) $plan['months']; ?> мес. · ≈ <?php echo number_format(round($plan['price'] / $plan['months']), 0, ',', ' '); ?> ₽/мес</li>
                            <?php $monthlyPlan = $recurringPlans['operator_monthly'] ?? null; ?>
                            <?php if ($monthlyPlan && $monthlyPlan['monthly_allowance'] == $plan['monthly_allowance'] && $monthlyPlan['price'] * $plan['months'] > $plan['price']): ?>
                                <li><?php echo rr_icon('check'); ?> Экономия <?php echo number_format($monthlyPlan['price'] * $plan['months'] - $plan['price'], 0, ',', ' '); ?> ₽ против помесячной оплаты</li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>

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
                <ul class="m-plan-list m-only">
                    <li><?php echo rr_icon('check'); ?> Договор, акт и проверка условий сделки</li>
                    <li><?php echo rr_icon('check'); ?> Заявку ведёт менеджер, чат с командой — сразу после заказа</li>
                </ul>

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

        <?php if ($myTurnkeyOrders): ?>
            <h3 class="m-only subscription-section-title m-orders-title"><?php echo rr_icon('list'); ?> Мои заявки</h3>
            <div class="turnkey-orders-list">
                <?php foreach ($myTurnkeyOrders as $order): ?>
                    <div class="turnkey-order-row">
                        <div class="turnkey-order-main">
                            <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $order['status'])); ?>">
                                <?php echo htmlspecialchars($turnkeyStatusLabels[$order['status']] ?? $order['status']); ?>
                            </span>
                            <span class="turnkey-order-date">от <?php echo formatDateRu($order['created_at']); ?></span>
                            <span class="turnkey-order-price"><?php echo number_format($order['price'], 0, ',', ' '); ?> ₽</span>
                        </div>
                        <?php if (!empty($order['note'])): ?>
                            <div class="turnkey-order-note"><?php echo rr_icon('message-circle'); ?> <?php echo nl2br(htmlspecialchars($order['note'])); ?></div>
                        <?php endif; ?>
                        <a href="/pages/service_order_chat.php?order_id=<?php echo $order['id']; ?>" class="turnkey-order-chat-link"><?php echo rr_icon('message-circle'); ?> Открыть чат по заявке →</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isOperator && $mHistory): ?>
            <!-- Телефон: история покупок и трат контактов (на десктопе скрыта) -->
            <h3 class="m-only subscription-section-title m-history-title"><?php echo rr_icon('clock'); ?> История</h3>
            <ul class="m-only m-list m-history">
                <?php foreach ($mHistory as $h): ?>
                    <li class="m-row">
                        <span class="m-row-ic"><?php echo rr_icon($h['icon']); ?></span>
                        <span class="m-row-main">
                            <span class="m-row-title"><?php echo htmlspecialchars($h['title']); ?></span>
                            <span class="m-row-sub"><?php echo htmlspecialchars($h['sub']); ?></span>
                        </span>
                        <span class="m-row-end m-history-end"><?php echo htmlspecialchars($h['end']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <ul class="subscription-benefits subscription-benefits-footer">
            <li><?php echo rr_icon('map-pin'); ?> Интерактивная карта с точками локаций — открывается любой покупкой (без покупок видно только «город → сколько точек»)</li>
            <li><?php echo rr_icon('lock'); ?> Точный адрес и контакт собственника — за 1 разблокировку на каждую локацию</li>
            <li><?php echo rr_icon('mail'); ?> Отправка заявки собственнику — доступна сразу после разблокировки его локации</li>
        </ul>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
