-- Дополнительные запросы по времени заезда/выезда и сведения для командировочных.
-- Миграция добавляет новые таблицы и не меняет существующие брони/платежи.

CREATE TABLE IF NOT EXISTS booking_time_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    kind ENUM('early_checkin','early_checkout') NOT NULL,
    requested_time TIME NOT NULL,
    offered_time TIME NULL,
    fee_amount INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending','offered','accepted','rejected') NOT NULL DEFAULT 'pending',
    guest_message VARCHAR(1000) NULL,
    admin_note VARCHAR(500) NULL,
    offered_at DATETIME NULL,
    guest_responded_at DATETIME NULL,
    handled_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_time_requests_booking (booking_id, id),
    KEY idx_time_requests_status (status, created_at),
    CONSTRAINT fk_time_requests_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_business_details (
    booking_id INT NOT NULL,
    is_business_trip TINYINT(1) NOT NULL DEFAULT 0,
    employee_name VARCHAR(190) NULL,
    organization_name VARCHAR(190) NULL,
    organization_inn VARCHAR(12) NULL,
    payer ENUM('guest','organization') NOT NULL DEFAULT 'guest',
    requested_documents VARCHAR(255) NULL,
    document_status ENUM('not_requested','requested','preparing','ready','issued') NOT NULL DEFAULT 'not_requested',
    admin_note VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (booking_id),
    CONSTRAINT fk_business_details_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
