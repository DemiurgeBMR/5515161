-- Открепление оператора (api/operator_assign.php, action=unassign) переводит
-- реальную запись в location_operators в 'inactive', но статус самой заявки
-- оставался 'approved' навсегда — 'approved' терминален и ничего его назад
-- не сбрасывает. Из-за этого заявка выглядела так, будто закрепление всё ещё
-- подтверждено, хотя по факту его уже сняли: страницы со списками заявок,
-- дашборд оператора и сам чат были вынуждены на лету пересчитывать реальное
-- состояние отдельным запросом к location_operators, вместо того чтобы
-- просто прочитать статус заявки.
--
-- Добавляем 'unassigned' как отдельный терминальный статус и сразу же
-- переводим в него все уже существующие заявки, у которых approved,
-- но соответствующего активного закрепления в location_operators больше нет
-- (открепление могло произойти ещё до этой миграции).

ALTER TABLE `applications`
    MODIFY `status` enum('pending','viewed','negotiating','agreed','placed','cancelled','approved','rejected','unassigned')
        COLLATE utf8mb4_unicode_ci DEFAULT 'pending';

UPDATE `applications` a
LEFT JOIN `location_operators` lo
    ON lo.location_id = a.location_id
    AND lo.operator_id = a.operator_id
    AND lo.status = 'active'
SET a.status = 'unassigned'
WHERE a.status = 'approved' AND lo.id IS NULL;
