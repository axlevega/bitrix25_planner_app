-- Миграция 010: настройки синхронизации — диапазон дат и список специалистов
-- sync_date_range_type: week, month, half_year, year, custom (при custom используются sync_date_from/sync_date_to)
-- sync_specialist_ids: JSON-массив id из таблицы specialists (пустой или NULL = все пользователи B24 по задачам не фильтруются — текущее поведение)

ALTER TABLE `integration_settings`
  ADD COLUMN `sync_date_range_type` varchar(32) NOT NULL DEFAULT 'month' COMMENT 'week, month, half_year, year, custom' AFTER `sync_interval_minutes`,
  ADD COLUMN `sync_date_from` date DEFAULT NULL COMMENT 'начало диапазона при custom' AFTER `sync_date_range_type`,
  ADD COLUMN `sync_date_to` date DEFAULT NULL COMMENT 'конец диапазона при custom' AFTER `sync_date_from`,
  ADD COLUMN `sync_specialist_ids` json DEFAULT NULL COMMENT 'массив id специалистов для фильтра задач по ответственным' AFTER `sync_date_to`;
