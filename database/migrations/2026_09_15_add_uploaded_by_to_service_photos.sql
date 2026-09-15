-- Накопительная квота на загрузку фото (users.php показал: размер и число
-- файлов ограничены на один запрос, но общего объёма на пользователя никто
-- не считал — настойчивый пользователь мог годами копить фото на диске).
--
-- Для locations/location_revisions владелец фото уже известен через
-- locations.owner_id, но service_photos (фото подтверждения обслуживания)
-- не хранил, кто именно загрузил файл — добавляем uploaded_by, чтобы
-- getUserUploadedBytes() в config.php могла посчитать квоту и для них.
-- NULL допустим и не бэкфиллится: у уже загруженных фото атрибуция не
-- восстановима (в исходной таблице её никогда не было), они просто не
-- войдут в подсчёт квоты для конкретного пользователя, но останутся на
-- диске и в БД как есть.

ALTER TABLE `service_photos`
    ADD COLUMN `uploaded_by` int NULL DEFAULT NULL AFTER `photo_path`,
    ADD KEY `idx_uploaded_by` (`uploaded_by`),
    ADD CONSTRAINT `service_photos_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
