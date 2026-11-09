-- Установить согласованный интервал уборки занятого номера — пять дней.
-- Это бизнес-правило гостиницы, поэтому обновляем и прежнее значение настройки.
INSERT INTO settings (name, value)
VALUES ('cleaning_every_days', '5')
ON DUPLICATE KEY UPDATE value = '5';
