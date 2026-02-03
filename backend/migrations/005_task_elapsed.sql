-- Миграция 005: учёт времени по задачам Bitrix24 по дням (для сетки планирования)
-- Данные из task.elapseditem.getlist: задача, пользователь, дата (день), минуты.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `bitrix24_task_elapsed` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `bitrix24_user_id` varchar(64) NOT NULL COMMENT 'кто трекал',
  `elapsed_date` date NOT NULL COMMENT 'день (дата)',
  `minutes` int unsigned NOT NULL DEFAULT 0,
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_user_date` (`bitrix24_task_id`, `bitrix24_user_id`, `elapsed_date`),
  KEY `idx_task_date` (`bitrix24_task_id`, `elapsed_date`),
  KEY `idx_user_date` (`bitrix24_user_id`, `elapsed_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
