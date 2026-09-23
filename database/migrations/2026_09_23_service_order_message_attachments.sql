-- Вложения (фото и документы: договор, скан, акт) к сообщениям в чате
-- заявки на услугу. Одно сообщение = либо текст, либо вложение, либо и то,
-- и другое сразу (message допускает пустую строку, если есть вложение).
--
-- Файлы хранятся ВНЕ public_html (см. rr_service_order_attachments_dir() в
-- config.php) — в отличие от фото локаций/обслуживания, здесь может быть
-- скан паспорта или подписанный договор с персональными данными, и это
-- приватная переписка 1:1 с админом, а не публичный листинг. Отдаётся
-- только через api/download_service_order_attachment.php с проверкой
-- доступа, а не напрямую по URL.
ALTER TABLE `service_order_messages`
    ADD COLUMN `attachment_path` VARCHAR(255) NULL DEFAULT NULL AFTER `message`,
    ADD COLUMN `attachment_name` VARCHAR(255) NULL DEFAULT NULL AFTER `attachment_path`,
    ADD COLUMN `attachment_size` INT NULL DEFAULT NULL AFTER `attachment_name`,
    ADD COLUMN `attachment_type` ENUM('image','document') NULL DEFAULT NULL AFTER `attachment_size`;
