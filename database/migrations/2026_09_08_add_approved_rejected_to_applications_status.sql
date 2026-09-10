-- api/operator_assign.php (действия 'approve'/'reject' для запроса оператора
-- на закрепление за локацией) пишет status = 'approved' / 'rejected' в
-- applications, но эти значения отсутствовали в ENUM — при попытке
-- одобрить/отклонить такой запрос MySQL в strict-режиме (умолчание для
-- MySQL 8) отклонял UPDATE с ошибкой, и кнопки фактически никогда не
-- работали. Добавляем недостающие значения, не трогая существующие.

ALTER TABLE `applications`
    MODIFY `status` enum('pending','viewed','negotiating','agreed','placed','cancelled','approved','rejected')
        COLLATE utf8mb4_unicode_ci DEFAULT 'pending';
