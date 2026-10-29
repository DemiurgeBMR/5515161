-- 1. Оплаты нельзя удалить. Ошибочную запись владелец аннулирует с причиной:
--    она остаётся в карточке брони зачёркнутой и не входит в суммы.
--    Вместе с оплатой наличными записывается, сколько дал гость и какую
--    сдачу ему выдали.
-- 2. Уборка номеров: задачи для горничной (после выезда, текущая уборка
--    у проживающих, разовая по просьбе).

ALTER TABLE payments
    ADD COLUMN received INT UNSIGNED NULL AFTER method,
    ADD COLUMN change_given INT UNSIGNED NOT NULL DEFAULT 0 AFTER received,
    ADD COLUMN voided_at DATETIME NULL,
    ADD COLUMN voided_by INT UNSIGNED NULL,
    ADD COLUMN void_reason VARCHAR(255) NOT NULL DEFAULT '';

-- Бронь с оплатами больше не удаляется вместе с ними: база не даст.
ALTER TABLE payments DROP FOREIGN KEY fk_payments_booking;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id);

CREATE TABLE cleanings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL,
    due_date DATE NOT NULL,
    due_at DATETIME NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    notified_at DATETIME NULL,
    done_at DATETIME NULL,
    done_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cleanings_auto (room_id, due_date, kind, booking_id),
    KEY idx_cleanings_due (due_date, status),
    CONSTRAINT fk_cleanings_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT fk_cleanings_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
