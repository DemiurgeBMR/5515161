-- Баня и хамам: записи на время, фото, оплаты и уборка после сеанса.
--
-- Баня одна: в каждый момент времени в ней может быть только одна запись.
-- Между сеансами — перерыв на уборку и протопку (настраивается в админке).
-- Статус бани (скоро открытие / работает / временно закрыта), цены, часы
-- работы и описание хранятся в settings и меняются в админке → «Баня».

CREATE TABLE bath_bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(40) NOT NULL,
    guest_id INT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    guests_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    source VARCHAR(20) NOT NULL DEFAULT 'site',
    contact_pref VARCHAR(20) NOT NULL DEFAULT 'call',
    hotel_guest TINYINT(1) NOT NULL DEFAULT 0,
    extras TEXT NULL,
    total_price INT UNSIGNED NULL,
    price_manual TINYINT(1) NOT NULL DEFAULT 0,
    guest_comment TEXT NULL,
    admin_note TEXT NULL,
    cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
    reminder_sent_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bath_token (token),
    KEY idx_bath_starts (starts_at),
    KEY idx_bath_status (status),
    KEY idx_bath_guest (guest_id),
    CONSTRAINT fk_bath_guest FOREIGN KEY (guest_id) REFERENCES guests (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bath_photos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    path VARCHAR(255) NOT NULL,
    width INT UNSIGNED NOT NULL DEFAULT 0,
    height INT UNSIGNED NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Оплата относится либо к брони номера, либо к записи в баню.
ALTER TABLE payments DROP FOREIGN KEY fk_payments_booking;

ALTER TABLE payments
    MODIFY booking_id INT UNSIGNED NULL,
    ADD COLUMN bath_booking_id INT UNSIGNED NULL AFTER booking_id,
    ADD KEY idx_payments_bath (bath_booking_id);

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
    ADD CONSTRAINT fk_payments_bath FOREIGN KEY (bath_booking_id) REFERENCES bath_bookings (id);

-- Уборка бани после сеанса: задача без комнаты, привязана к записи.
ALTER TABLE cleanings DROP FOREIGN KEY fk_cleanings_room;

ALTER TABLE cleanings
    MODIFY room_id INT UNSIGNED NULL,
    ADD COLUMN bath_booking_id INT UNSIGNED NULL AFTER booking_id,
    ADD UNIQUE KEY uq_cleanings_bath (bath_booking_id, kind);

ALTER TABLE cleanings
    ADD CONSTRAINT fk_cleanings_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_cleanings_bath FOREIGN KEY (bath_booking_id) REFERENCES bath_bookings (id) ON DELETE SET NULL;

-- Строка-замок: запись в баню блокирует её, чтобы двое не заняли одно время.
INSERT IGNORE INTO settings (name, value) VALUES ('bath_lock', '');
