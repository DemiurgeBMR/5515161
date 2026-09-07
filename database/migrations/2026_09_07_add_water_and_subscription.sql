-- Добавляет:
--  1) locations.has_water — наличие доступа к воде на месте (по аналогии с
--     has_electricity/has_wifi), для карточки локации, форм добавления/
--     редактирования и фильтра каталога.
--  2) users.is_subscribed — заглушка подписки (просто вкл/выкл, без
--     реальной оплаты). От неё зависит доступ к карте с точками и к
--     точному адресу локации на её карточке (pages/subscription.php,
--     config.php::currentUserHasSubscription()).

ALTER TABLE `locations`
    ADD COLUMN `has_water` TINYINT(1) NOT NULL DEFAULT 0 AFTER `has_wifi`;

ALTER TABLE `users`
    ADD COLUMN `is_subscribed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avatar_color`;
