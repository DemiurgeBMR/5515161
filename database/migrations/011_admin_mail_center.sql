-- Почтовый центр: журнал входящих/исходящих, шаблоны и согласие на рассылки.
ALTER TABLE guests
    ADD COLUMN marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN marketing_consent_at DATETIME NULL,
    ADD COLUMN marketing_consent_by INT UNSIGNED NULL,
    ADD COLUMN marketing_unsubscribe_token CHAR(64) NULL,
    ADD KEY idx_guests_marketing (marketing_consent, anonymized_at),
    ADD UNIQUE KEY uq_guests_unsubscribe_token (marketing_unsubscribe_token),
    ADD CONSTRAINT fk_guests_marketing_staff FOREIGN KEY (marketing_consent_by) REFERENCES staff (id) ON DELETE SET NULL;

CREATE TABLE admin_mail_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_mail_templates_updated (updated_at),
    CONSTRAINT fk_mail_templates_staff FOREIGN KEY (created_by) REFERENCES staff (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_mail_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    direction VARCHAR(8) NOT NULL,
    email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body MEDIUMTEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'accepted',
    error_text VARCHAR(500) NULL,
    message_id VARCHAR(255) NULL,
    mailbox_uid BIGINT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_mail_message_id (message_id),
    UNIQUE KEY uq_admin_mail_uid (mailbox_uid),
    KEY idx_admin_mail_created (created_at),
    KEY idx_admin_mail_email (email),
    KEY idx_admin_mail_direction (direction, created_at),
    CONSTRAINT fk_admin_mail_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;