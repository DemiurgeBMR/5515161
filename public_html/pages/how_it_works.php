<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

$role = $_SESSION['user_role'] ?? null;
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Как это работает — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="hiw-page">
        <div class="hiw-container">
            <a href="/" class="back-link">← На главную</a>

            <h1>Как работает RR</h1>
            <p class="hiw-intro">
                RR — площадка, которая соединяет владельцев помещений, готовых сдать место под вендинговый
                автомат, с операторами, которые ищут точки для размещения. Поиск локации, переписка с
                собеседником и вся договорённость проходят прямо на платформе — звонить вслепую или искать
                контакты самостоятельно не нужно.
            </p>

            <div class="hiw-columns">
                <div class="hiw-column<?php echo $role === 'owner' ? ' hiw-column-active' : ''; ?>">
                    <div class="hiw-column-header">
                        <span class="hiw-column-icon">🏢</span>
                        <div>
                            <h2>Владельцам площадей</h2>
                            <p>Сдайте свободное место под вендинг и получайте доход с аренды</p>
                        </div>
                    </div>
                    <ol class="hiw-steps">
                        <li>
                            <strong>Зарегистрируйтесь как владелец.</strong>
                            При регистрации выберите роль «Собственник».
                        </li>
                        <li>
                            <strong>Добавьте локацию.</strong>
                            Укажите фото, город и адрес, тип помещения, площадь, проходимость и доступные
                            удобства — электричество, Wi-Fi, воду — а также цену аренды в месяц.
                        </li>
                        <li>
                            <strong>Дождитесь модерации.</strong>
                            Администратор проверяет объявление, после чего оно появляется в каталоге и
                            становится видно операторам.
                        </li>
                        <li>
                            <strong>Получайте заявки и общайтесь в чате.</strong>
                            Заинтересованные операторы пишут вам прямо на платформе — вся переписка по
                            конкретной точке хранится в одном чате.
                        </li>
                        <li>
                            <strong>Договоритесь об условиях аренды.</strong>
                            Обсудите цену и детали размещения в переписке.
                        </li>
                        <li>
                            <strong>Одобрите закрепление оператора.</strong>
                            Когда договорились, подтвердите запрос на закрепление в чате — точка официально
                            переходит под управление этого оператора.
                        </li>
                        <li>
                            <strong>Следите за точкой.</strong>
                            В разделе «Операторы на моих точках» видно, кто закреплён за каждой локацией;
                            при необходимости оператора можно открепить. Даты установки и обслуживания
                            согласуются в общем календаре визитов.
                        </li>
                    </ol>
                    <?php if (!$isLoggedIn): ?>
                        <a href="/pages/register.php?role=owner" class="btn-contact btn-block">Стать владельцем</a>
                    <?php elseif ($role === 'owner'): ?>
                        <a href="/pages/add_location.php" class="btn-contact btn-block">➕ Добавить локацию</a>
                    <?php endif; ?>
                </div>

                <div class="hiw-column<?php echo $role === 'operator' ? ' hiw-column-active' : ''; ?>">
                    <div class="hiw-column-header">
                        <span class="hiw-column-icon">🥤</span>
                        <div>
                            <h2>Операторам вендинга</h2>
                            <p>Находите точки с подходящей проходимостью и размещайте автоматы</p>
                        </div>
                    </div>
                    <ol class="hiw-steps">
                        <li>
                            <strong>Зарегистрируйтесь как оператор.</strong>
                            При регистрации выберите роль «Оператор».
                        </li>
                        <li>
                            <strong>Найдите локацию в каталоге.</strong>
                            Используйте поиск и фильтры: город, тип помещения, минимальная проходимость,
                            цена, наличие электричества, Wi-Fi и воды.
                        </li>
                        <li>
                            <strong>Оформите подписку.</strong>
                            Без подписки на карточке локации виден только город. Подписка открывает точный
                            адрес, имя владельца и возможность написать ему напрямую.
                        </li>
                        <li>
                            <strong>Напишите владельцу.</strong>
                            Кнопка «Отправить заявку на аренду» на карточке локации открывает чат по этой
                            точке.
                        </li>
                        <li>
                            <strong>Обсудите условия.</strong>
                            Договоритесь о цене и деталях размещения прямо в переписке.
                        </li>
                        <li>
                            <strong>Запросите закрепление.</strong>
                            Если договорились — отправьте запрос на закрепление за локацией прямо из чата.
                            После подтверждения владельцем точка закрепляется за вами.
                        </li>
                        <li>
                            <strong>Спланируйте установку и обслуживание.</strong>
                            Согласуйте дату выезда в календаре, а дальше отмечайте обслуживание и историю
                            визитов в личном кабинете.
                        </li>
                    </ol>
                    <?php if (!$isLoggedIn): ?>
                        <a href="/pages/register.php?role=operator" class="btn-contact btn-block">Стать оператором</a>
                    <?php elseif ($role === 'operator'): ?>
                        <a href="/pages/catalog.php" class="btn-contact btn-block">🔍 Искать локации</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hiw-faq">
                <h2>Частые вопросы</h2>
                <div class="hiw-faq-item">
                    <div class="hiw-faq-q">Нужно ли платить, чтобы разместить локацию?</div>
                    <div class="hiw-faq-a">Нет, добавление локаций для владельцев бесплатно.</div>
                </div>
                <div class="hiw-faq-item">
                    <div class="hiw-faq-q">Зачем нужна подписка?</div>
                    <div class="hiw-faq-a">
                        Подписка — на стороне оператора. Она открывает точный адрес и имя владельца
                        локации, а также возможность написать ему напрямую. Без подписки на карточке видно
                        только город.
                    </div>
                </div>
                <div class="hiw-faq-item">
                    <div class="hiw-faq-q">Как быстро объявление появится в каталоге?</div>
                    <div class="hiw-faq-a">
                        Сразу после модерации администратором — обычно это быстрая проверка на корректность
                        данных.
                    </div>
                </div>
                <div class="hiw-faq-item">
                    <div class="hiw-faq-q">Что если владелец передумал сдавать место оператору?</div>
                    <div class="hiw-faq-a">
                        Владелец может отклонить запрос на закрепление или в любой момент открепить
                        оператора через раздел «Операторы на моих точках».
                    </div>
                </div>
                <div class="hiw-faq-item">
                    <div class="hiw-faq-q">Можно ли договориться об аренде без закрепления?</div>
                    <div class="hiw-faq-a">
                        Да, чат по заявке ни к чему не обязывает — закрепление это отдельный шаг, который
                        оператор запрашивает сам, когда стороны уже договорились.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
