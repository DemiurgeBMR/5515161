-- Полный пересмотр системы уведомлений — старая версия была написана на
-- скорую руку: is_read проставлялся всем сразу при заходе на страницу
-- (пользователь физически не мог увидеть непрочитанное как непрочитанное),
-- иконка/категория нигде не хранились (emoji вручную вписывался в текст
-- сообщения), а создание уведомления было скопипащено в три разных файла
-- без единой точки правды.
--
-- category      — группа для фильтров/настроек (chat, visits, assignment,
--                 maintenance, moderation, system); соответствие
--                 type → category/иконка/подпись живёт в config.php
--                 (NOTIFICATION_META), здесь только хранимое значение.
-- data          — структурированные метаданные (например application_id),
--                 чтобы можно было находить и гасить связанные уведомления
--                 (например все "новое сообщение" по конкретной заявке),
--                 не тесно связываясь с текстом ссылки.
-- read_at       — замена is_read: NULL = непрочитано, дата = когда именно
--                 прочитано. Более точная семантика и задел под будущую
--                 сортировку/аналитику "просрочено — уже сутки не прочитано".

ALTER TABLE `notifications`
    ADD COLUMN `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system' AFTER `type`,
    ADD COLUMN `data` JSON NULL DEFAULT NULL AFTER `link`,
    ADD COLUMN `read_at` datetime NULL DEFAULT NULL AFTER `is_read`;

-- Бэкфилл category по уже накопленным типам, чтобы старые уведомления
-- сразу корректно попадали под фильтры/группировку в обновлённом UI.
UPDATE `notifications` SET `category` = CASE
    WHEN `type` IN ('event_requested', 'emergency_event', 'event_confirmed', 'event_rescheduled', 'event_cancelled', 'event_completed') THEN 'visits'
    WHEN `type` IN ('operator_assigned', 'assignment_request', 'assignment_approved', 'assignment_rejected') THEN 'assignment'
    WHEN `type` IN ('maintenance_due', 'maintenance_due_owner', 'quick_service') THEN 'maintenance'
    WHEN `type` IN ('revision_approved', 'revision_rejected') THEN 'moderation'
    ELSE 'system'
END;

UPDATE `notifications` SET `read_at` = `created_at` WHERE `is_read` = 1;

ALTER TABLE `notifications`
    DROP COLUMN `is_read`,
    ADD INDEX `idx_user_category` (`user_id`, `category`),
    ADD INDEX `idx_user_created` (`user_id`, `created_at`);

-- Настройки уведомлений по категориям. Отсутствие строки = включено
-- (значение по умолчанию), поэтому существующим пользователям не нужен
-- бэкфилл — таблица заполняется только явными выключениями.
CREATE TABLE `notification_preferences` (
    `user_id` int NOT NULL,
    `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `category`),
    CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
