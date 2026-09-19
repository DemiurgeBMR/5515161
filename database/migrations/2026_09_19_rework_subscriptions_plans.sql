-- Система подписки операторов: переходим от заглушки `users.has_subscription`
-- (просто вкл/выкл, без сроков и тарифов) к настоящим тарифам со сроком
-- действия. Таблица `subscriptions` была заложена в схему заранее (см.
-- комментарий в 0000_00_00_initial_schema.sql), но с другим набором планов
-- ('free'/'standard'/'premium') и без цены на момент покупки — в неё ещё
-- ни разу не писали (подписка всегда работала только через
-- users.has_subscription), поэтому просто переопределяем структуру под
-- реальные тарифы, ничего не теряя.
--
-- Тарифы (см. rr_subscription_plans() в config.php): месяц — 499₽,
-- полгода — 2799₽, год — 4999₽. price_paid фиксирует цену на момент
-- покупки, чтобы будущее изменение прайса не переписывало историю прошлых
-- "оплат" (платежей по факту ещё нет — оформление тарифа просто пишет
-- строку сюда, см. rr_purchase_subscription()).
--
-- is_active — отдельно от end_date: обычно они совпадают, но админ может
-- закрыть доступ раньше срока (admin/user_actions.php, cancel_subscription),
-- не трогая end_date и не теряя историю покупок.
TRUNCATE TABLE `subscriptions`;

ALTER TABLE `subscriptions`
    MODIFY `plan` ENUM('monthly','half_year','yearly') NOT NULL,
    ADD COLUMN `price_paid` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `plan`;
