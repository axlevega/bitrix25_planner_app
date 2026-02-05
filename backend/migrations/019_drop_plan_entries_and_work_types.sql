-- Миграция 019: удаление плановых записей и справочника типов работ.
-- Функционал «Плановые записи» не используется; загрузка считается только по задачам B24.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `plan_entries`;
DROP TABLE IF EXISTS `work_types`;

SET FOREIGN_KEY_CHECKS = 1;
