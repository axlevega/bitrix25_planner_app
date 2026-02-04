-- Миграция 008: переплан задачи ПМ — даты и снимок исходного плана для сравнения
-- plan_start_date/plan_end_date — скорректированные даты; original_* — снимок из B24 при первом сохранении.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `task_plan_override` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `plan_start_date` date DEFAULT NULL COMMENT 'скорректированная дата начала',
  `plan_end_date` date DEFAULT NULL COMMENT 'скорректированная дата окончания',
  `original_plan_start` date DEFAULT NULL COMMENT 'снимок даты начала из B24',
  `original_plan_end` date DEFAULT NULL COMMENT 'снимок даты окончания из B24',
  `original_time_estimate` int unsigned DEFAULT NULL COMMENT 'снимок оценки (минуты) из B24',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_task_id` (`bitrix24_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
