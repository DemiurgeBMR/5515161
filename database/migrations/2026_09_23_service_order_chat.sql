-- Чат по заявке "Сделка под ключ" (и другим будущим разовым услугам из
-- service_orders) — раньше единственной обратной связью было текстовое
-- поле "note" на самой заявке, которое админ мог заполнить один раз, без
-- переписки. Теперь у каждого заказа отдельный тред сообщений между
-- заказчиком и любым администратором (админ видит и отвечает на любой
-- заказ — своей персональной очереди у заказов нет).
--
-- is_system — переключает автоматические записи о смене статуса
-- ("Статус изменён на «В обработке»") в отдельный визуальный стиль,
-- отличный от обычных сообщений участников.
CREATE TABLE `service_order_messages` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `service_order_id` INT NOT NULL,
    `sender_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order` (`service_order_id`),
    CONSTRAINT `service_order_messages_ibfk_1` FOREIGN KEY (`service_order_id`) REFERENCES `service_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `service_order_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
