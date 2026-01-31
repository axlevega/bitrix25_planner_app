-- Миграция 001: начальная схема (фаза 1)
-- Таблицы: departments, specialists, work_types, bitrix24_*, integration_settings, sync_log

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Отделы (SEO, разработка, дизайн и т.п.)
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` varchar(64) DEFAULT NULL COMMENT 'SEO, разработка, дизайн',
  `external_id` varchar(64) DEFAULT NULL COMMENT 'Bitrix24',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_external_id` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Типы работ (регулярка / флайт)
CREATE TABLE IF NOT EXISTS `work_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL COMMENT 'regular, flight',
  `name` varchar(128) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `work_types` (`code`, `name`) VALUES
  ('regular', 'Регулярные'),
  ('flight',  'Флайт')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Специалисты (привязка к B24, отдел, нормы часов)
CREATE TABLE IF NOT EXISTS `specialists` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_user_id` varchar(64) DEFAULT NULL,
  `department_id` int unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `norm_hours_per_day` decimal(5,2) DEFAULT NULL,
  `norm_hours_per_week` decimal(5,2) DEFAULT NULL,
  `flight_hours_limit_per_day` decimal(5,2) DEFAULT NULL,
  `flight_hours_limit_per_week` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_user_id` (`bitrix24_user_id`),
  KEY `idx_department_id` (`department_id`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_specialists_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш задач Bitrix24
CREATE TABLE IF NOT EXISTS `bitrix24_tasks_cache` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `title` varchar(512) DEFAULT NULL,
  `responsible_user_id` varchar(64) DEFAULT NULL,
  `deadline` datetime DEFAULT NULL,
  `time_estimate` int unsigned DEFAULT NULL COMMENT 'минуты',
  `time_spent` int unsigned DEFAULT NULL COMMENT 'минуты',
  `status` varchar(32) DEFAULT NULL,
  `group_id` varchar(64) DEFAULT NULL COMMENT 'проект задачи',
  `synced_at` datetime NOT NULL,
  `raw_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_task_id` (`bitrix24_task_id`),
  KEY `idx_responsible_deadline` (`responsible_user_id`, `deadline`),
  KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш пользователей Bitrix24
CREATE TABLE IF NOT EXISTS `bitrix24_users_cache` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_user_id` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_user_id` (`bitrix24_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Настройки интеграции (портал, вебхук, интервал синхронизации)
CREATE TABLE IF NOT EXISTS `integration_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `portal_url` varchar(512) DEFAULT NULL,
  `webhook_token` varchar(512) DEFAULT NULL,
  `sync_interval_minutes` int unsigned DEFAULT 30,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог синхронизаций
CREATE TABLE IF NOT EXISTS `sync_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL COMMENT 'success, error',
  `message` text,
  `tasks_count` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
