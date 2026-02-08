-- Миграция 021: интервалы переплана задачи (переплан с разрывами)
-- Один интервал = диапазон дат (date_from, date_to) и часы в день (hours_per_day) для рабочих дней.
-- У одной задачи может быть несколько интервалов; между ними — разрывы (дни без плана).
-- Используется для отчётов и визуального отображения сегментов на таймлайне.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `task_plan_intervals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `date_from` date NOT NULL COMMENT 'начало интервала',
  `date_to` date NOT NULL COMMENT 'конец интервала',
  `hours_per_day` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'часов в день (рабочие дни)',
  `sort_order` smallint unsigned NOT NULL DEFAULT 0 COMMENT 'порядок интервала в списке',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_id` (`bitrix24_task_id`),
  KEY `idx_task_sort` (`bitrix24_task_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
