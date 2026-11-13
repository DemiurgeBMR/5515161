-- Фактический момент выезда нужен, чтобы создать уборку,
-- даже если гость выехал раньше даты check_out (в том числе в день заезда).
ALTER TABLE bookings
    ADD COLUMN checked_out_at DATETIME NULL AFTER status;
