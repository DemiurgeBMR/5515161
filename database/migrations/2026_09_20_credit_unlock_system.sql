-- Переход от "подписка = безлимитный доступ на время" к системе оплаты за
-- контакт: оператор тратит кредит-разблокировку на КОНКРЕТНУЮ локацию (её
-- точный адрес и контакт собственника открываются для него навсегда), а не
-- покупает доступ ко всем локациям сразу на срок. См. rr_unlock_location()
-- и соседние функции в config.php.
--
-- Источники кредитов:
--  - бесплатный грант при регистрации оператора (1 контакт, разово);
--  - разовые пакеты 5/15/40 контактов (никогда не сгорают);
--  - помесячная квота у тарифов "Оператор"/"Сеть" (сгорает в конце периода,
--    не переносится) — считается на лету по location_unlocks, отдельного
--    счётчика не храним, чтобы не рассинхронизировался с фактом разблокировок.
--
-- Тариф `subscriptions.plan` меняется на новый набор (см. rr_recurring_plans()
-- в config.php) — старые ('monthly','half_year','yearly') были введены на
-- предыдущей итерации и полностью заменяются этой системой, поэтому просто
-- переопределяем enum, как и в прошлый раз (см. 2026_09_19_rework_subscriptions_plans.sql).
TRUNCATE TABLE `subscriptions`;
ALTER TABLE `subscriptions`
    MODIFY `plan` ENUM('operator_monthly','operator_yearly','network_monthly') NOT NULL;

-- Разовые покупки кредитов (и бесплатный грант при регистрации) — то, что
-- никогда не сгорает. price_paid = 0 для бесплатного гранта.
CREATE TABLE `credit_purchases` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `source` ENUM('free_grant','pack_5','pack_15','pack_40') NOT NULL,
    `credits_granted` INT NOT NULL,
    `price_paid` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `credit_purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Разблокировка конкретной локации конкретным оператором — навсегда, даже
-- если позже кончится подписка/кредиты. source — за счёт чего разблокировано
-- (для истории/аналитики, на саму проверку доступа не влияет: сам факт
-- строки в этой таблице и есть разрешение).
CREATE TABLE `location_unlocks` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `operator_id` INT NOT NULL,
    `location_id` INT NOT NULL,
    `source` ENUM('permanent_credit','subscription_allowance') NOT NULL,
    `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_operator_location` (`operator_id`, `location_id`),
    KEY `idx_operator_source_time` (`operator_id`, `source`, `unlocked_at`),
    CONSTRAINT `location_unlocks_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `location_unlocks_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Сделка под ключ" — ручная услуга команды (договор/акт/проверка), заказ
-- просто фиксируется здесь, дальше её обрабатывает админ вручную вне сайта.
CREATE TABLE `service_orders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `service` ENUM('turnkey_deal') NOT NULL DEFAULT 'turnkey_deal',
    `price` DECIMAL(10,2) NOT NULL,
    `status` ENUM('new','in_progress','done','cancelled') NOT NULL DEFAULT 'new',
    `location_id` INT NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `service_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `service_orders_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
