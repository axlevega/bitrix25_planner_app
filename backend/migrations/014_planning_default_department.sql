-- Миграция 014: настройка «отдел по умолчанию в планировании»
-- Добавляет ключ planning_default_department_id в integration_settings (ID отдела или пусто).
-- При открытии страницы планирования подставляется этот отдел и подгружаются данные.

INSERT INTO `integration_settings` (`setting_key`, `setting_value`) VALUES
  ('planning_default_department_id', '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
