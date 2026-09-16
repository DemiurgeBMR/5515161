-- Продолжение доработки профиля/аутентификации (после
-- 2026_09_11_add_login_security_fields_to_users.sql): подтверждение email,
-- двухфакторная аутентификация по коду на email и возможность админу
-- заблокировать аккаунт.
--
-- Почта пока не настроена (проект на локалке) — и ссылка подтверждения
-- email, и код 2FA временно показываются прямо на экране вместо письма
-- (см. pages/verify_email.php, pages/verify_2fa.php) — так же, как уже
-- сделано для восстановления пароля.

ALTER TABLE `users`
    ADD COLUMN `verify_token` varchar(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `is_verified`,
    ADD COLUMN `verify_token_expires` datetime NULL DEFAULT NULL AFTER `verify_token`,
    ADD COLUMN `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `verify_token_expires`,
    ADD COLUMN `two_factor_code` varchar(6) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `two_factor_enabled`,
    ADD COLUMN `two_factor_code_expires` datetime NULL DEFAULT NULL AFTER `two_factor_code`,
    ADD COLUMN `is_banned` tinyint(1) NOT NULL DEFAULT 0 AFTER `two_factor_code_expires`,
    ADD COLUMN `banned_reason` varchar(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `is_banned`,
    ADD UNIQUE KEY `verify_token` (`verify_token`);
