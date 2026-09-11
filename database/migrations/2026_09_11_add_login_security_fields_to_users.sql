-- Этап 1 доработки профиля/аутентификации: защита от подбора пароля
-- (счётчик неудачных попыток входа + временная блокировка аккаунта) и
-- восстановление забытого пароля по одноразовому токену со сроком жизни.
--
-- Токен восстановления хранится прямо на users, а не в отдельной таблице —
-- одному пользователю за раз нужен максимум один активный токен, новый
-- запрос восстановления просто перезаписывает предыдущий.

ALTER TABLE `users`
    ADD COLUMN `failed_login_attempts` int NOT NULL DEFAULT 0 AFTER `password`,
    ADD COLUMN `locked_until` datetime NULL DEFAULT NULL AFTER `failed_login_attempts`,
    ADD COLUMN `reset_token` varchar(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `locked_until`,
    ADD COLUMN `reset_token_expires` datetime NULL DEFAULT NULL AFTER `reset_token`,
    ADD UNIQUE KEY `reset_token` (`reset_token`);
