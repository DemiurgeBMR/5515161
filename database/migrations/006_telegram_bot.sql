-- Бот сотрудникам — в Telegram вместо MAX: те же поля привязки, другие имена.
-- Привязки MAX к Telegram не подходят — сбрасываем, сотрудники подключатся заново.

ALTER TABLE staff
    DROP INDEX uq_staff_max_user,
    CHANGE max_user_id tg_user_id BIGINT NULL,
    CHANGE max_chat_id tg_chat_id BIGINT NULL,
    CHANGE max_name tg_name VARCHAR(100) NOT NULL DEFAULT '',
    CHANGE max_notify tg_notify TINYINT(1) NOT NULL DEFAULT 1,
    CHANGE max_link_code tg_link_code VARCHAR(16) NULL,
    CHANGE max_link_expires tg_link_expires DATETIME NULL,
    CHANGE max_linked_at tg_linked_at DATETIME NULL;

ALTER TABLE staff ADD UNIQUE KEY uq_staff_tg_user (tg_user_id);

UPDATE staff SET tg_user_id = NULL, tg_chat_id = NULL, tg_name = '', tg_linked_at = NULL, tg_link_code = NULL, tg_link_expires = NULL;

DELETE FROM settings WHERE name IN ('max_bot_name', 'max_bot_username', 'max_last_error', 'max_marker', 'max_summary_date', 'max_webhook_url');
