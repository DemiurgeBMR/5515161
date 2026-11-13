-- Начальная схема базы гостиницы.
--
-- Совместима с MySQL 5.7+, MySQL 8 и MariaDB 10.3+: без ENUM (статусы —
-- строки, их проверяет PHP), без CHECK и JSON, кодировка utf8mb4_unicode_ci.
-- Даты проживания — модель «ночей»: бронь занимает ночи с check_in
-- включительно по check_out не включая, поэтому выезд одного гостя и заезд
-- другого в один день не пересекаются (выезд до 12:00, заезд с 13:00).

CREATE TABLE staff (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notify TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    summary TEXT NULL,
    features TEXT NULL,
    capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
    area VARCHAR(30) NOT NULL DEFAULT '',
    bed VARCHAR(100) NOT NULL DEFAULT '',
    base_price INT UNSIGNED NOT NULL DEFAULT 0,
    price_tbd TINYINT(1) NOT NULL DEFAULT 0,
    badge VARCHAR(50) NOT NULL DEFAULT '',
    featured TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_type_photos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_type_id INT UNSIGNED NOT NULL,
    path VARCHAR(255) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_photos_type (room_type_id),
    CONSTRAINT fk_photos_type FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_type_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    ical_token CHAR(32) NOT NULL,
    ical_import_url TEXT NULL,
    ical_synced_at DATETIME NULL,
    ical_error VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_rooms_type (room_type_id),
    CONSTRAINT fk_rooms_type FOREIGN KEY (room_type_id) REFERENCES room_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(25) NOT NULL DEFAULT '',
    phone_digits VARCHAR(15) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    anonymized_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_guests_phone (phone_digits),
    KEY idx_guests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(40) NOT NULL,
    guest_id INT UNSIGNED NULL,
    room_type_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    guests_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    source VARCHAR(20) NOT NULL DEFAULT 'site',
    contact_pref VARCHAR(20) NOT NULL DEFAULT 'call',
    total_price INT UNSIGNED NULL,
    price_manual TINYINT(1) NOT NULL DEFAULT 0,
    prepayment INT UNSIGNED NOT NULL DEFAULT 0,
    guest_comment TEXT NULL,
    admin_note TEXT NULL,
    cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
    reminder_sent_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_token (token),
    KEY idx_bookings_room_dates (room_id, check_in, check_out),
    KEY idx_bookings_status (status),
    KEY idx_bookings_check_in (check_in),
    KEY idx_bookings_check_out (check_out),
    KEY idx_bookings_guest (guest_id),
    CONSTRAINT fk_bookings_guest FOREIGN KEY (guest_id) REFERENCES guests (id) ON DELETE SET NULL,
    CONSTRAINT fk_bookings_type FOREIGN KEY (room_type_id) REFERENCES room_types (id),
    CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    amount INT NOT NULL,
    method VARCHAR(20) NOT NULL DEFAULT 'cash',
    paid_on DATE NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_paid_on (paid_on),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_closures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    source VARCHAR(10) NOT NULL DEFAULT 'manual',
    external_uid VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_closures_room_dates (room_id, date_from, date_to),
    CONSTRAINT fk_closures_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_type_id INT UNSIGNED NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    price INT UNSIGNED NULL,
    percent SMALLINT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_price_rules_dates (date_from, date_to),
    CONSTRAINT fk_price_rules_type FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stay_discounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    min_nights SMALLINT UNSIGNED NOT NULL,
    percent TINYINT UNSIGNED NULL,
    label VARCHAR(50) NOT NULL,
    note VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    message TEXT NULL,
    new_check_out DATE NULL,
    created_at DATETIME NOT NULL,
    handled_at DATETIME NULL,
    handled_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_messages_booking (booking_id),
    KEY idx_messages_handled (handled_at),
    CONSTRAINT fk_messages_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE waitlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic VARCHAR(20) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    actor VARCHAR(10) NOT NULL,
    staff_id INT UNSIGNED NULL,
    entity VARCHAR(20) NOT NULL,
    entity_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(20) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rate_limits (scope, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_codes_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name VARCHAR(64) NOT NULL,
    value TEXT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
