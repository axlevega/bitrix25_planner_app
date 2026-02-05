-- Миграция 017: справочник статусов задач Bitrix24.
-- В bitrix24_tasks_cache хранится status (ID из B24). Таблица задаёт человекочитаемые названия
-- для отображения в публичной части. Стандартные ID по REST API: 1–7, мета -2, -1.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bitrix24_task_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_status_id` varchar(16) NOT NULL COMMENT 'ID статуса в Bitrix24 (1–7: новые/в работе/завершены и т.д.; -2, -1: мета)',
  `name` varchar(128) NOT NULL COMMENT 'Название для отображения',
  `sort_order` smallint NOT NULL DEFAULT 0 COMMENT 'Порядок вывода (меньше — выше)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_status_id` (`bitrix24_status_id`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Справочник статусов задач Bitrix24: id → название для публичной части';

-- Стандартные статусы по документации Bitrix24 REST API
INSERT INTO `bitrix24_task_status` (`bitrix24_status_id`, `name`, `sort_order`) VALUES
  ('1', 'Новая', 10),
  ('2', 'Ожидание', 20),
  ('3', 'В работе', 30),
  ('4', 'Предположительно завершена', 40),
  ('5', 'Завершена', 50),
  ('6', 'Отложена', 60),
  ('7', 'Отклонена', 70),
  ('-2', 'Непросмотренная', 5),
  ('-1', 'Просроченная', 15)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`);
