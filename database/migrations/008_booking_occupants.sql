-- Дополнительные проживающие по брони. Основной гость остаётся в guests.
CREATE TABLE booking_occupants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_booking_occupants_booking (booking_id),
    CONSTRAINT fk_booking_occupants_booking FOREIGN KEY (booking_id)
        REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
