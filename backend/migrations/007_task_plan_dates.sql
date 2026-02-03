-- Миграция 007: плановые даты и дата постановки задачи для расчёта планируемых трудозатрат по дням
-- start_date_plan / end_date_plan из Bitrix24; при отсутствии — используем created_date и deadline.

SET NAMES utf8mb4;

ALTER TABLE `bitrix24_tasks_cache`
  ADD COLUMN `start_date_plan` datetime DEFAULT NULL COMMENT 'плановое начало (B24 START_DATE_PLAN)' AFTER `group_id`,
  ADD COLUMN `end_date_plan` datetime DEFAULT NULL COMMENT 'плановое окончание (B24 END_DATE_PLAN)' AFTER `start_date_plan`,
  ADD COLUMN `created_date` datetime DEFAULT NULL COMMENT 'дата постановки задачи (B24 CREATED_DATE)' AFTER `end_date_plan`;
