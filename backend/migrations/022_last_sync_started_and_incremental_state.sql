-- Миграция 022: инкрементальная синхронизация
-- last_sync_started_at — время старта последней синхронизации (для фильтра «изменённые после» в следующем инкременте)
-- sync_filter_since — дата «изменённые после» для текущего инкрементного прогона (используется в фазах задач и пользователей)
-- sync_incremental_task_ids — JSON-массив ID задач, полученных в инкременте (для фазы elapsed только по ним)

INSERT INTO `integration_settings` (`setting_key`, `setting_value`) VALUES
  ('last_sync_started_at', ''),
  ('sync_filter_since', ''),
  ('sync_incremental_task_ids', '[]')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
