-- Базовая схема БД проекта Riveg Rent (RR).
--
-- Это ВЕРИФИЦИРОВАННЫЙ дамп структуры реальной рабочей базы разработчика
-- (экспорт phpMyAdmin, MySQL 8.0.45, без данных), а не реконструкция по
-- коду — в отличие от предыдущей версии этого файла. Включает все
-- колонки, индексы и внешние ключи "как есть" на момент экспорта,
-- в том числе координаты локаций (latitude/longitude) и поля has_water /
-- has_subscription, которые раньше были описаны отдельными инкрементными
-- миграциями, а по факту уже были частью основной схемы — поэтому
-- database/migrations/2026_09_04_add_location_coordinates.sql удалена
-- как избыточная: то, что она добавляла, теперь уже здесь, в базовой схеме.
--
-- Таблицы `_cities`, `_countries`, `_regions` — внешний заполненный
-- справочник (страны/регионы/города), а не то, что создаёт или наполняет
-- само приложение. Здесь только их структура; данные нужно перенести
-- отдельно с рабочей базы разработчика.
--
-- Таблица `subscriptions` (план/даты/платёж) уже заложена в схему про
-- запас под будущую реальную оплату, но пока не используется — сейчас
-- подписка — это просто флаг `users.has_subscription`, включаемый на
-- pages/subscription.php (см. config.php::currentUserHasSubscription()).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- --------------------------------------------------------

CREATE TABLE `applications` (
  `id` int NOT NULL,
  `location_id` int NOT NULL,
  `operator_id` int NOT NULL,
  `owner_id` int NOT NULL,
  `status` enum('pending','viewed','negotiating','agreed','placed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `initial_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `operator_notifications_enabled` tinyint(1) DEFAULT '1',
  `owner_notifications_enabled` tinyint(1) DEFAULT '1',
  `cancelled_by` int DEFAULT NULL,
  `operator_tag` enum('negotiating','agreed','placed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_tag` enum('negotiating','agreed','placed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_approved` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `location_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `installation_events` (
  `id` int NOT NULL,
  `application_id` int DEFAULT NULL,
  `location_operator_id` int DEFAULT NULL,
  `event_type` enum('installation','maintenance','restock','repair','removal') NOT NULL DEFAULT 'maintenance',
  `proposed_datetime` datetime NOT NULL,
  `confirmed_datetime` datetime DEFAULT NULL,
  `status` enum('requested','reviewing','confirmed','rescheduled','completed','cancelled') DEFAULT 'requested',
  `requested_by` int NOT NULL,
  `last_modified_by` int DEFAULT NULL,
  `is_emergency` tinyint(1) DEFAULT '0',
  `emergency_comment` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `assigned_operator_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `installation_event_log` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `old_datetime` datetime DEFAULT NULL,
  `new_datetime` datetime DEFAULT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `locations` (
  `id` int NOT NULL,
  `owner_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price_month` decimal(10,2) NOT NULL,
  `width` decimal(5,2) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `depth` decimal(5,2) DEFAULT NULL,
  `has_electricity` tinyint(1) DEFAULT '1',
  `has_wifi` tinyint(1) DEFAULT '0',
  `access_hours` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '24/7',
  `is_active` tinyint(1) DEFAULT '1',
  `is_moderated` tinyint(1) DEFAULT '0',
  `views` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `traffic_rating` tinyint(1) DEFAULT '0',
  `space_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_security` tinyint(1) NOT NULL DEFAULT '0',
  `has_water` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `location_machines` (
  `id` int NOT NULL,
  `location_operator_id` int NOT NULL,
  `machine_type` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `installed_at` date DEFAULT NULL,
  `last_service_at` datetime DEFAULT NULL,
  `status` enum('active','needs_service','broken','removed') NOT NULL DEFAULT 'active',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `location_operators` (
  `id` int NOT NULL,
  `location_id` int NOT NULL,
  `operator_id` int NOT NULL,
  `owner_id` int NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `location_photos` (
  `id` int NOT NULL,
  `location_id` int NOT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_main` tinyint(1) DEFAULT '0',
  `is_pending` tinyint(1) DEFAULT '0',
  `pending_action` enum('add','delete','none') COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `location_revisions` (
  `id` int NOT NULL,
  `location_id` int NOT NULL,
  `data` json NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `machine_service_log` (
  `id` int NOT NULL,
  `machine_id` int NOT NULL,
  `event_type` enum('maintenance','restock','repair') NOT NULL,
  `comment` text,
  `performed_by` int NOT NULL,
  `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `messages` (
  `id` int NOT NULL,
  `application_id` int NOT NULL,
  `sender_id` int NOT NULL,
  `receiver_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `service_photos` (
  `id` int NOT NULL,
  `source_type` enum('log','event') NOT NULL,
  `source_id` int NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

-- Про запас под будущую реальную оплату — сейчас не используется
-- (см. заголовок файла и config.php::currentUserHasSubscription()).
CREATE TABLE `subscriptions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `plan` enum('free','standard','premium') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free',
  `start_date` timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '0',
  `payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE `system_cron_log` (
  `id` int NOT NULL,
  `job_name` varchar(50) NOT NULL,
  `last_run_date` date NOT NULL,
  `last_run_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('owner','operator','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operator',
  `has_subscription` tinyint(1) NOT NULL DEFAULT '0',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT '0',
  `rating` decimal(3,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `avatar_color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#e94560'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Внешний справочник (страны/регионы/города) — только структура,
-- данные переносятся отдельно с рабочей базы разработчика.
-- --------------------------------------------------------

CREATE TABLE `_cities` (
  `city_id` int NOT NULL,
  `country_id` int NOT NULL,
  `important` tinyint(1) NOT NULL,
  `region_id` int DEFAULT NULL,
  `title_ru` varchar(150) DEFAULT NULL,
  `area_ru` varchar(150) DEFAULT NULL,
  `region_ru` varchar(150) DEFAULT NULL,
  `title_ua` varchar(150) DEFAULT NULL,
  `area_ua` varchar(150) DEFAULT NULL,
  `region_ua` varchar(150) DEFAULT NULL,
  `title_be` varchar(150) DEFAULT NULL,
  `area_be` varchar(150) DEFAULT NULL,
  `region_be` varchar(150) DEFAULT NULL,
  `title_en` varchar(150) DEFAULT NULL,
  `area_en` varchar(150) DEFAULT NULL,
  `region_en` varchar(150) DEFAULT NULL,
  `title_es` varchar(150) DEFAULT NULL,
  `area_es` varchar(150) DEFAULT NULL,
  `region_es` varchar(150) DEFAULT NULL,
  `title_pt` varchar(150) DEFAULT NULL,
  `area_pt` varchar(150) DEFAULT NULL,
  `region_pt` varchar(150) DEFAULT NULL,
  `title_de` varchar(150) DEFAULT NULL,
  `area_de` varchar(150) DEFAULT NULL,
  `region_de` varchar(150) DEFAULT NULL,
  `title_fr` varchar(150) DEFAULT NULL,
  `area_fr` varchar(150) DEFAULT NULL,
  `region_fr` varchar(150) DEFAULT NULL,
  `title_it` varchar(150) DEFAULT NULL,
  `area_it` varchar(150) DEFAULT NULL,
  `region_it` varchar(150) DEFAULT NULL,
  `title_pl` varchar(150) DEFAULT NULL,
  `area_pl` varchar(150) DEFAULT NULL,
  `region_pl` varchar(150) DEFAULT NULL,
  `title_ja` varchar(150) DEFAULT NULL,
  `area_ja` varchar(150) DEFAULT NULL,
  `region_ja` varchar(150) DEFAULT NULL,
  `title_lt` varchar(150) DEFAULT NULL,
  `area_lt` varchar(150) DEFAULT NULL,
  `region_lt` varchar(150) DEFAULT NULL,
  `title_lv` varchar(150) DEFAULT NULL,
  `area_lv` varchar(150) DEFAULT NULL,
  `region_lv` varchar(150) DEFAULT NULL,
  `title_cz` varchar(150) DEFAULT NULL,
  `area_cz` varchar(150) DEFAULT NULL,
  `region_cz` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `_countries` (
  `country_id` int NOT NULL,
  `title_ru` varchar(60) DEFAULT NULL,
  `title_ua` varchar(60) DEFAULT NULL,
  `title_be` varchar(60) DEFAULT NULL,
  `title_en` varchar(60) DEFAULT NULL,
  `title_es` varchar(60) DEFAULT NULL,
  `title_pt` varchar(60) DEFAULT NULL,
  `title_de` varchar(60) DEFAULT NULL,
  `title_fr` varchar(60) DEFAULT NULL,
  `title_it` varchar(60) DEFAULT NULL,
  `title_pl` varchar(60) DEFAULT NULL,
  `title_ja` varchar(60) DEFAULT NULL,
  `title_lt` varchar(60) DEFAULT NULL,
  `title_lv` varchar(60) DEFAULT NULL,
  `title_cz` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `_regions` (
  `region_id` int NOT NULL,
  `country_id` int NOT NULL,
  `title_ru` varchar(150) DEFAULT NULL,
  `title_ua` varchar(150) DEFAULT NULL,
  `title_be` varchar(150) DEFAULT NULL,
  `title_en` varchar(150) DEFAULT NULL,
  `title_es` varchar(150) DEFAULT NULL,
  `title_pt` varchar(150) DEFAULT NULL,
  `title_de` varchar(150) DEFAULT NULL,
  `title_fr` varchar(150) DEFAULT NULL,
  `title_it` varchar(150) DEFAULT NULL,
  `title_pl` varchar(150) DEFAULT NULL,
  `title_ja` varchar(150) DEFAULT NULL,
  `title_lt` varchar(150) DEFAULT NULL,
  `title_lv` varchar(150) DEFAULT NULL,
  `title_cz` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------
-- Индексы
-- --------------------------------------------------------

ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_location` (`location_id`),
  ADD KEY `idx_operator` (`operator_id`),
  ADD KEY `idx_owner` (`owner_id`);

ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `location_id` (`location_id`);

ALTER TABLE `installation_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `assigned_operator_id` (`assigned_operator_id`),
  ADD KEY `idx_installation_events_location_operator` (`location_operator_id`);

ALTER TABLE `installation_event_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `idx_locations_geo` (`latitude`,`longitude`);

ALTER TABLE `location_machines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_location_operator` (`location_operator_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `location_operators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_location_operator` (`location_id`,`operator_id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `owner_id` (`owner_id`);

ALTER TABLE `location_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `location_id` (`location_id`);

ALTER TABLE `location_revisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `location_id` (`location_id`);

ALTER TABLE `machine_service_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_service_log_user` (`performed_by`),
  ADD KEY `idx_machine_performed` (`machine_id`,`performed_at`);

ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_application` (`application_id`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_receiver` (`receiver_id`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`);

ALTER TABLE `service_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_source` (`source_type`,`source_id`);

ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `system_cron_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_job` (`job_name`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `_cities`
  ADD KEY `idx_title_ru` (`title_ru`(50));

-- --------------------------------------------------------
-- AUTO_INCREMENT
-- --------------------------------------------------------

ALTER TABLE `applications` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `favorites` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `installation_events` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `installation_event_log` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `locations` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `location_machines` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `location_operators` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `location_photos` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `location_revisions` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `machine_service_log` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `messages` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `notifications` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `service_photos` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `subscriptions` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `system_cron_log` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `users` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- Внешние ключи
-- --------------------------------------------------------

ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `applications_ibfk_3` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

ALTER TABLE `installation_events`
  ADD CONSTRAINT `fk_installation_events_location_operator` FOREIGN KEY (`location_operator_id`) REFERENCES `location_operators` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `installation_events_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `installation_events_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `installation_events_ibfk_3` FOREIGN KEY (`assigned_operator_id`) REFERENCES `users` (`id`);

ALTER TABLE `installation_event_log`
  ADD CONSTRAINT `installation_event_log_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `installation_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `installation_event_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `locations`
  ADD CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `location_machines`
  ADD CONSTRAINT `fk_location_machines_location_operator` FOREIGN KEY (`location_operator_id`) REFERENCES `location_operators` (`id`) ON DELETE CASCADE;

ALTER TABLE `location_operators`
  ADD CONSTRAINT `location_operators_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `location_operators_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `location_operators_ibfk_3` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `location_photos`
  ADD CONSTRAINT `location_photos_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

ALTER TABLE `location_revisions`
  ADD CONSTRAINT `location_revisions_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

ALTER TABLE `machine_service_log`
  ADD CONSTRAINT `fk_service_log_machine` FOREIGN KEY (`machine_id`) REFERENCES `location_machines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_service_log_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
