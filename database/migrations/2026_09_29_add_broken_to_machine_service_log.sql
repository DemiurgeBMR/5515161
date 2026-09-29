-- Оператор мог отметить обслуживание любого типа (даже просто "пополнил
-- товар"), и api/installation.php (action=quick_service) молча возвращал
-- location_machines.status обратно в 'active' — enum для этого поля уже
-- включает 'broken'/'needs_service', но в интерфейсе не было ни одного
-- действия, которое ставило бы 'broken' явно, и ни одного типа отметки,
-- который не сбрасывал бы его случайно.
--
-- Добавляем 'broken' как ещё один event_type в machine_service_log (наравне
-- с maintenance/restock/repair) — оператор сообщает о поломке через ту же
-- форму "Отметить обслуживание" (с тем же комментарием и фото), это остаётся
-- в истории обслуживания, и — в отличие от остальных типов — реально ставит
-- location_machines.status = 'broken', а не 'active'.

ALTER TABLE `machine_service_log`
    MODIFY `event_type` enum('maintenance','restock','repair','broken') NOT NULL;
