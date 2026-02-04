-- Миграция 009: скорректированные плановые часы по дням (редактирует ПМ)
-- Одна запись на (задача, день); при отсутствии — используется авто-распределение.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `task_plan_daily` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `plan_date` date NOT NULL,
  `planned_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_date` (`bitrix24_task_id`, `plan_date`),
  KEY `idx_task_id` (`bitrix24_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
