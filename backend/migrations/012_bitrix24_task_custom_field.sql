-- Миграция 012: пользовательские поля задач Bitrix24 (UF_*).
-- Отдельная таблица для хранения имён и значений UF_* по каждой задаче;
-- позволяет фильтровать задачи по кастомным полям (например «Флайт» true/false).
-- Применять после 001 (bitrix24_tasks_cache).

CREATE TABLE IF NOT EXISTS `bitrix24_task_custom_field` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_task_id` varchar(64) NOT NULL,
  `field_code` varchar(64) NOT NULL COMMENT 'код поля в B24, напр. UF_CRM_123',
  `value_text` text COMMENT 'значение в виде строки (для bool: 1/0, массивов: JSON)',
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_field` (`bitrix24_task_id`, `field_code`),
  KEY `idx_field_value` (`field_code`, `value_text`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Пользовательские поля задач Bitrix24 (UF_*), синхронизируются при загрузке задач';
