-- Миграция 011: таблица integration_settings — модель «одна строка = одна системная настройка»
-- Позволяет добавлять новые настройки через INSERT без изменения схемы; управление с фронта по ключам.
-- Применять после 001, 002, 006, 010 (таблица к этому моменту содержит все колонки).

RENAME TABLE `integration_settings` TO `integration_settings_old`;

CREATE TABLE `integration_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(128) NOT NULL,
  `setting_value` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Системные настройки: одна строка — одно значение (key-value)';

-- Все ключи с умолчаниями (при пустой _old они останутся; при наличии _old — обновим ниже)
INSERT INTO `integration_settings` (`setting_key`, `setting_value`) VALUES
  ('portal_url', ''),
  ('webhook_token', ''),
  ('sync_interval_minutes', '30'),
  ('last_sync_at', ''),
  ('sync_phase', '3'),
  ('sync_tasks_offset', '0'),
  ('sync_users_offset', '0'),
  ('sync_elapsed_task_offset', '0'),
  ('sync_date_range_type', 'month'),
  ('sync_date_from', ''),
  ('sync_date_to', ''),
  ('sync_specialist_ids', '[]');

-- Перенос данных из старой таблицы (если в ней есть строка)
UPDATE integration_settings s
CROSS JOIN (SELECT portal_url, webhook_token, sync_interval_minutes, last_sync_at, sync_phase, sync_tasks_offset, sync_users_offset, sync_elapsed_task_offset,
  sync_date_range_type, sync_date_from, sync_date_to, IFNULL(CAST(sync_specialist_ids AS CHAR), '[]') AS sync_specialist_ids
  FROM integration_settings_old LIMIT 1) o
SET s.setting_value = CASE s.setting_key
  WHEN 'portal_url' THEN IFNULL(o.portal_url, '')
  WHEN 'webhook_token' THEN IFNULL(o.webhook_token, '')
  WHEN 'sync_interval_minutes' THEN IFNULL(CAST(o.sync_interval_minutes AS CHAR), '30')
  WHEN 'last_sync_at' THEN IFNULL(CAST(o.last_sync_at AS CHAR), '')
  WHEN 'sync_phase' THEN IFNULL(CAST(o.sync_phase AS CHAR), '3')
  WHEN 'sync_tasks_offset' THEN IFNULL(CAST(o.sync_tasks_offset AS CHAR), '0')
  WHEN 'sync_users_offset' THEN IFNULL(CAST(o.sync_users_offset AS CHAR), '0')
  WHEN 'sync_elapsed_task_offset' THEN IFNULL(CAST(o.sync_elapsed_task_offset AS CHAR), '0')
  WHEN 'sync_date_range_type' THEN IFNULL(o.sync_date_range_type, 'month')
  WHEN 'sync_date_from' THEN IFNULL(CAST(o.sync_date_from AS CHAR), '')
  WHEN 'sync_date_to' THEN IFNULL(CAST(o.sync_date_to AS CHAR), '')
  WHEN 'sync_specialist_ids' THEN o.sync_specialist_ids
  ELSE s.setting_value
END;

DROP TABLE `integration_settings_old`;
