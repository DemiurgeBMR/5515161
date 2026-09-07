-- Базовая схема БД проекта Riveg Rent (RR), реконструирована по коду
-- приложения (запросам INSERT/UPDATE/SELECT во всех *.php), а не снята
-- напрямую с рабочей базы через mysqldump — в репозитории до этого файла
-- не было ни одной миграции создания таблиц, только одна ALTER-миграция
-- (2026_09_04_add_location_coordinates.sql), из-за чего проект нельзя было
-- поднять с нуля.
--
-- ВАЖНО: типы столбцов, длины, NULL/NOT NULL и внешние ключи ниже —
-- лучшее приближение по тому, как код обращается с данными, а НЕ проверенный
-- дамп реальной структуры. Прежде чем полагаться на этот файл как на
-- источник истины, сверьте (а лучше — замените) его результатом реального
-- дампа с рабочей базы разработчика:
--
--   mysqldump --no-data --skip-comments --column-statistics=0 \
--       -u root riveg_rent > database/migrations/0000_00_00_initial_schema.sql
--
-- Таблица `_cities` (используется в api/cities.php как справочник городов
-- для автодополнения) сюда намеренно НЕ включена: это внешний
-- заполненный данными справочник (судя по названию — список городов РФ),
-- а не таблица, которую создаёт/наполняет само приложение. Её нужно
-- перенести отдельно (структуру и содержимое) с рабочей базы.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- users
-- ============================================================
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(255) NOT NULL,
    `password`      VARCHAR(255) NOT NULL,
    `full_name`     VARCHAR(150) NOT NULL,
    `phone`         VARCHAR(30) NULL DEFAULT NULL,
    `role`          VARCHAR(20) NOT NULL DEFAULT 'operator', -- 'operator' | 'owner' | 'admin'
    `avatar_color`  VARCHAR(7) NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- locations
-- (latitude/longitude добавляются последующей миграцией
-- 2026_09_04_add_location_coordinates.sql — здесь их нет намеренно)
-- ============================================================
CREATE TABLE `locations` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id`         INT UNSIGNED NOT NULL,
    `title`            VARCHAR(255) NOT NULL,
    `address`          VARCHAR(255) NOT NULL,
    `city`             VARCHAR(100) NOT NULL,
    `description`      TEXT NULL DEFAULT NULL,
    `price_month`      DECIMAL(10,2) NOT NULL DEFAULT 0,
    `width`            DECIMAL(5,2) NULL DEFAULT NULL,
    `height`           DECIMAL(5,2) NULL DEFAULT NULL,
    `depth`            DECIMAL(5,2) NULL DEFAULT NULL,
    `has_electricity`  TINYINT(1) NOT NULL DEFAULT 0,
    `has_wifi`         TINYINT(1) NOT NULL DEFAULT 0,
    `access_hours`     VARCHAR(50) NOT NULL DEFAULT '24/7',
    `traffic_rating`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `space_type`       VARCHAR(20) NULL DEFAULT NULL,
    `is_moderated`     TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 0,
    `views`            INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_locations_owner` (`owner_id`),
    KEY `idx_locations_city` (`city`),
    KEY `idx_locations_active_moderated` (`is_active`, `is_moderated`),
    CONSTRAINT `fk_locations_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- location_photos
-- ============================================================
CREATE TABLE `location_photos` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `location_id`     INT UNSIGNED NOT NULL,
    `photo_path`      VARCHAR(255) NOT NULL,
    `sort_order`      INT NOT NULL DEFAULT 0,
    `is_main`         TINYINT(1) NOT NULL DEFAULT 0,
    `is_pending`      TINYINT(1) NOT NULL DEFAULT 0,
    `pending_action`  VARCHAR(10) NULL DEFAULT NULL, -- 'add' | 'delete'
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_location_photos_location` (`location_id`),
    CONSTRAINT `fk_location_photos_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- location_revisions
-- `data` хранит JSON с полями локации/фото на модерации (см. admin/actions.php
-- applyRevision() и pages/owner_actions.php applyRevisionToLocation()).
-- ============================================================
CREATE TABLE `location_revisions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `location_id`   INT UNSIGNED NOT NULL,
    `data`          LONGTEXT NOT NULL,
    `status`        VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending' | 'approved' | 'rejected'
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at`   DATETIME NULL DEFAULT NULL,
    `reviewed_by`   INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_location_revisions_location_status` (`location_id`, `status`),
    CONSTRAINT `fk_location_revisions_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_location_revisions_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- applications
-- `status`: 'pending' | 'cancelled' | 'approved' | 'rejected' | 'placed'
-- `operator_tag`/`owner_tag` — личные пометки сторон в общем чате:
-- 'negotiating' | 'agreed' | 'placed'
-- ============================================================
CREATE TABLE `applications` (
    `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `location_id`                     INT UNSIGNED NOT NULL,
    `operator_id`                     INT UNSIGNED NOT NULL,
    `owner_id`                        INT UNSIGNED NOT NULL,
    `initial_message`                 TEXT NULL DEFAULT NULL,
    `status`                          VARCHAR(20) NOT NULL DEFAULT 'pending',
    `operator_tag`                    VARCHAR(20) NULL DEFAULT NULL,
    `owner_tag`                       VARCHAR(20) NULL DEFAULT NULL,
    `cancelled_by`                    INT UNSIGNED NULL DEFAULT NULL,
    `operator_approved`               TINYINT(1) NOT NULL DEFAULT 0,
    `operator_notifications_enabled`  TINYINT(1) NOT NULL DEFAULT 1,
    `owner_notifications_enabled`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_applications_location` (`location_id`),
    KEY `idx_applications_operator` (`operator_id`),
    KEY `idx_applications_owner` (`owner_id`),
    CONSTRAINT `fk_applications_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_applications_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_applications_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_applications_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- messages
-- ============================================================
CREATE TABLE `messages` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id`  INT UNSIGNED NOT NULL,
    `sender_id`       INT UNSIGNED NOT NULL,
    `receiver_id`     INT UNSIGNED NOT NULL,
    `message`         TEXT NOT NULL,
    `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_application` (`application_id`),
    KEY `idx_messages_receiver_read` (`receiver_id`, `is_read`),
    CONSTRAINT `fk_messages_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- notifications
-- ============================================================
CREATE TABLE `notifications` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `type`        VARCHAR(40) NOT NULL,
    `message`     TEXT NOT NULL,
    `link`        VARCHAR(255) NULL DEFAULT NULL,
    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_read` (`user_id`, `is_read`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- location_operators
-- Закрепление оператора за локацией. `status`: 'active' (единственное
-- значение, встреченное в коде; place for future 'inactive'/etc.).
--
-- ON DELETE CASCADE от locations: ни owner_actions.php, ни admin/actions.php
-- при удалении локации не чистят эту таблицу вручную. Сейчас (без внешних
-- ключей в реальной БД) это просто оставляет осиротевшие строки; здесь
-- решили не воспроизводить этот баг, а закрыть его каскадом.
-- ============================================================
CREATE TABLE `location_operators` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `location_id`  INT UNSIGNED NOT NULL,
    `operator_id`  INT UNSIGNED NOT NULL,
    `owner_id`     INT UNSIGNED NOT NULL,
    `status`       VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_location_operators_location` (`location_id`),
    KEY `idx_location_operators_operator` (`operator_id`),
    KEY `idx_location_operators_owner` (`owner_id`),
    CONSTRAINT `fk_location_operators_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_location_operators_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_location_operators_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- location_machines
-- Одна карточка вендинга на закрепление (см. ON DUPLICATE KEY UPDATE
-- по location_operator_id в api/installation.php save_machine).
-- ============================================================
CREATE TABLE `location_machines` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `location_operator_id`  INT UNSIGNED NOT NULL,
    `machine_type`          VARCHAR(20) NOT NULL, -- 'snacks' | 'drinks' | 'coffee' | 'combo' | 'other'
    `model`                 VARCHAR(100) NULL DEFAULT NULL,
    `serial_number`         VARCHAR(100) NULL DEFAULT NULL,
    `installed_at`          DATE NULL DEFAULT NULL,
    `last_service_at`       DATETIME NULL DEFAULT NULL,
    `status`                VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active' | 'removed'
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_location_machines_location_operator` (`location_operator_id`),
    CONSTRAINT `fk_location_machines_location_operator` FOREIGN KEY (`location_operator_id`) REFERENCES `location_operators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- machine_service_log
-- Постфактум-отметки обслуживания (в отличие от installation_events,
-- которые согласуются заранее между сторонами).
-- ============================================================
CREATE TABLE `machine_service_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id`    INT UNSIGNED NOT NULL,
    `event_type`    VARCHAR(20) NOT NULL, -- 'maintenance' | 'restock' | 'repair'
    `comment`       TEXT NULL DEFAULT NULL,
    `performed_by`  INT UNSIGNED NOT NULL,
    `performed_at`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_machine_service_log_machine` (`machine_id`),
    CONSTRAINT `fk_machine_service_log_machine` FOREIGN KEY (`machine_id`) REFERENCES `location_machines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_machine_service_log_performer` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- installation_events
-- Согласованные заранее визиты (установка/обслуживание/пополнение/
-- ремонт/демонтаж). `application_id` необязателен — заполняется, только
-- если визит запрошен из чата конкретной заявки.
-- ============================================================
CREATE TABLE `installation_events` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id`        INT UNSIGNED NULL DEFAULT NULL,
    `location_operator_id`  INT UNSIGNED NOT NULL,
    `event_type`            VARCHAR(20) NOT NULL, -- installation|maintenance|restock|repair|removal
    `proposed_datetime`     DATETIME NOT NULL,
    `confirmed_datetime`    DATETIME NULL DEFAULT NULL,
    `status`                VARCHAR(20) NOT NULL DEFAULT 'requested', -- requested|reviewing|confirmed|completed|cancelled
    `requested_by`          INT UNSIGNED NOT NULL,
    `last_modified_by`      INT UNSIGNED NULL DEFAULT NULL,
    `is_emergency`          TINYINT(1) NOT NULL DEFAULT 0,
    `emergency_comment`     TEXT NULL DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_installation_events_application` (`application_id`),
    KEY `idx_installation_events_location_operator` (`location_operator_id`),
    KEY `idx_installation_events_status` (`status`),
    CONSTRAINT `fk_installation_events_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_installation_events_location_operator` FOREIGN KEY (`location_operator_id`) REFERENCES `location_operators` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_installation_events_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_installation_events_last_modified_by` FOREIGN KEY (`last_modified_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- installation_event_log
-- Аудит-лог изменений одного installation_events (created/confirmed/
-- rescheduled/cancelled/completed).
-- ============================================================
CREATE TABLE `installation_event_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id`      INT UNSIGNED NOT NULL,
    `action`        VARCHAR(20) NOT NULL,
    `old_datetime`  DATETIME NULL DEFAULT NULL,
    `new_datetime`  DATETIME NULL DEFAULT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_installation_event_log_event` (`event_id`),
    CONSTRAINT `fk_installation_event_log_event` FOREIGN KEY (`event_id`) REFERENCES `installation_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_installation_event_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- service_photos
-- Полиморфная связь: source_type='event' -> installation_events.id,
-- source_type='log' -> machine_service_log.id. Без FK, т.к. родительская
-- таблица зависит от значения source_type (см. attachServicePhotos()
-- в api/installation.php).
-- ============================================================
CREATE TABLE `service_photos` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_type`  VARCHAR(10) NOT NULL, -- 'event' | 'log'
    `source_id`    INT UNSIGNED NOT NULL,
    `photo_path`   VARCHAR(255) NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_service_photos_source` (`source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- system_cron_log
-- "Бедный cron" (includes/cron_check.php) — по одной строке на job_name,
-- отмечает, что задача уже отработала сегодня.
-- ============================================================
CREATE TABLE `system_cron_log` (
    `job_name`       VARCHAR(50) NOT NULL,
    `last_run_date`  DATE NOT NULL,
    `last_run_at`    DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`job_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
