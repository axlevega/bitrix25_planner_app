-- Миграция 004: справочник «проект Bitrix24 (group_id) → тип работы» для классификации задач B24
-- Задачи из групп, не указанных в справочнике, считаются «регулярка» (work_type_id = 1).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `project_work_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_group_id` varchar(64) NOT NULL COMMENT 'ID проекта/группы задачи в Bitrix24',
  `work_type_id` int unsigned NOT NULL COMMENT '1=regular, 2=flight',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_group_id` (`bitrix24_group_id`),
  CONSTRAINT `fk_project_work_type_work_type` FOREIGN KEY (`work_type_id`) REFERENCES `work_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
