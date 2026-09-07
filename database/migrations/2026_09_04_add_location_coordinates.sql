-- Добавляет координаты (широта/долгота) к локациям для отображения на карте.
-- Координаты получаются через геокодер OpenStreetMap Nominatim при добавлении
-- или редактировании локации (см. functions.php::geocodeAddress) и кешируются
-- в этих столбцах — повторный запрос к геокодеру для той же локации не нужен.

ALTER TABLE `locations`
    ADD COLUMN `latitude` DECIMAL(10,7) NULL DEFAULT NULL AFTER `city`,
    ADD COLUMN `longitude` DECIMAL(10,7) NULL DEFAULT NULL AFTER `latitude`;

CREATE INDEX `idx_locations_geo` ON `locations` (`latitude`, `longitude`);
