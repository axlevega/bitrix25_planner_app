-- Миграция 013: каталог уникальных пользовательских полей задач (только поля, созданные пользователем в B24).
-- Системные поля (вложения, почта, CRM и т.д.) в каталог не попадают; в bitrix24_task_custom_field
-- сохраняются только значения по полям из этого каталога.
-- Применять после 012.

CREATE TABLE IF NOT EXISTS `bitrix24_task_uf_catalog` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `field_code` varchar(64) NOT NULL COMMENT 'код поля в camelCase, как приходит в tasks.task.list',
  `label` varchar(255) DEFAULT NULL COMMENT 'название из B24 (LIST_COLUMN_LABEL)',
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_field_code` (`field_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Каталог пользовательских полей задач B24 (без системных); значения только по этим полям пишутся в bitrix24_task_custom_field';
