-- Rate limiting для api/*.php — раньше ограничение по частоте было только на
-- логине (блокировка аккаунта после серии неудачных попыток, users.locked_until).
-- Остальные 8 эндпоинтов в api/ можно было дёргать без остановки: сообщения в
-- чате, опрос уведомлений/сообщений и т.д.
--
-- Простой rate-limiter с фиксированным окном: одна строка на ключ (например
-- "send_message:42"), окно и счётчик сбрасываются при переходе в новое окно —
-- поэтому таблица не растёт бесконечно, а хранит максимум одну строку на
-- каждую реально использующуюся пару (эндпоинт, пользователь/IP).
-- См. rr_check_rate_limit()/rr_rate_limit_or_429() в config.php.

CREATE TABLE `rate_limits` (
    `rate_key` varchar(191) NOT NULL,
    `window_start` int NOT NULL,
    `request_count` int NOT NULL DEFAULT 1,
    PRIMARY KEY (`rate_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
