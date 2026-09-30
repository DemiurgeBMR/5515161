-- 'broken' уже даёт оператору способ сообщить о полной поломке (вендинг не
-- работает вовсе). Но location_machines.status также предусматривает
-- 'needs_service' — более мягкий случай ("работает, но пора чинить": течёт,
-- дребезжит, барахлит купюроприёмник и т.п.), и для него до сих пор не было
-- ни одного действия в интерфейсе — enum молчаливо простаивал так же, как
-- 'broken' до 2026_09_29_add_broken_to_machine_service_log.sql.
--
-- Добавляем 'needs_service' как ещё один event_type в machine_service_log,
-- той же формой "Отметить обслуживание" — ставит location_machines.status =
-- 'needs_service' (а не 'active', в отличие от maintenance/restock, и не
-- 'broken' — вендинг всё ещё работает).

ALTER TABLE `machine_service_log`
    MODIFY `event_type` enum('maintenance','restock','repair','broken','needs_service') NOT NULL;
