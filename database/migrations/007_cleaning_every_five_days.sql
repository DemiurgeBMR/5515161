-- Стандартная периодичность уборки занятого номера — каждые пять дней.
-- Не перезаписываем настройку, если администратор уже задавал свой интервал.
INSERT INTO settings (name, value)
SELECT 'cleaning_every_days', '5'
WHERE NOT EXISTS (
    SELECT 1 FROM settings WHERE name = 'cleaning_every_days'
);
