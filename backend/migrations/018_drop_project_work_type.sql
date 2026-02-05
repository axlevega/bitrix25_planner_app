-- Миграция 018: удаление справочника «проект B24 → тип работы».
-- Тип задачи определяется пользовательским свойством в Bitrix24, разбивка по проектам не используется.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `project_work_type`;

SET FOREIGN_KEY_CHECKS = 1;
