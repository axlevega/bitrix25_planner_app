-- Миграция 020: настройки фильтра по пользовательским полям задач в планировании по умолчанию
-- planning_default_uf_field_code — код UF-поля (из каталога), пусто = не фильтровать
-- planning_default_uf_value — значение для фильтра (например "1", "0"), пусто = любое

INSERT INTO `integration_settings` (`setting_key`, `setting_value`) VALUES
  ('planning_default_uf_field_code', ''),
  ('planning_default_uf_value', '')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
