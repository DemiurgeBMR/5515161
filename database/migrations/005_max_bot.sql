-- Бот в мессенджере MAX: сотрудник привязывает свой MAX к учётной записи
-- в админке (по одноразовому коду) и получает уведомления с кнопками.

ALTER TABLE staff
    ADD COLUMN max_user_id BIGINT NULL,
    ADD COLUMN max_chat_id BIGINT NULL,
    ADD COLUMN max_name VARCHAR(100) NOT NULL DEFAULT '',
    ADD COLUMN max_notify TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN max_link_code VARCHAR(16) NULL,
    ADD COLUMN max_link_expires DATETIME NULL,
    ADD COLUMN max_linked_at DATETIME NULL,
    ADD UNIQUE KEY uq_staff_max_user (max_user_id);
