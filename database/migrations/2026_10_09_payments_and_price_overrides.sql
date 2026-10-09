-- Реальная оплата через ЮKassa и редактирование цен из админ-панели.
--
-- payments — одна строка на попытку оплаты пакета контактов или тарифа.
-- Контакты/тариф начисляются только после того, как ЮKassa подтвердила
-- оплату (fulfilled_at ставится ровно один раз — защита от повторной выдачи
-- при повторном вебхуке или повторном заходе на страницу возврата).
--
-- Внешнего ключа на users нарочно нет: платёжная история не должна пропадать
-- вместе с удалённым аккаунтом.
--
-- price_overrides — цены, которые админ поменял в панели; если строки нет,
-- действует цена по умолчанию из config.php (rr_catalog_defaults()).

CREATE TABLE `payments` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `kind` enum('pack','plan') COLLATE utf8mb4_unicode_ci NOT NULL,
    `item_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `status` enum('pending','succeeded','canceled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
    `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yookassa',
    `provider_payment_id` varchar(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `idempotence_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at` datetime NULL DEFAULT NULL,
    `fulfilled_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `provider_payment_id` (`provider_payment_id`),
    KEY `user_id` (`user_id`),
    KEY `status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `price_overrides` (
    `item_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `price` int NOT NULL,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`item_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
