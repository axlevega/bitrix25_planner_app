-- Миграция 016: таблица групп задач Bitrix24 (проекты / рабочие группы)
-- GROUP_ID в задачах ссылается на эти группы. Названия подтягиваются через sonet.group.get.
-- Отдельная синхронизация групп и включение в общую синхронизацию (фаза 0).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bitrix24_task_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bitrix24_group_id` varchar(64) NOT NULL COMMENT 'ID группы в Bitrix24 (проект задачи)',
  `name` varchar(512) DEFAULT NULL COMMENT 'Название группы из B24',
  `synced_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bitrix24_group_id` (`bitrix24_group_id`),
  KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Группы задач Bitrix24 (проекты): id и название из sonet.group.get';

-- Ключ для постраничной синхронизации групп (фаза 0). Таблица integration_settings — key-value (миграция 011).
INSERT INTO `integration_settings` (`setting_key`, `setting_value`) VALUES ('sync_groups_offset', '0')
ON DUPLICATE KEY UPDATE `setting_value` = IFNULL(`setting_value`, '0');

-- После введения фазы 0 (группы) idle = 4; старый sync_phase 3 означал idle — приводим к 4.
UPDATE `integration_settings` SET `setting_value` = '4' WHERE `setting_key` = 'sync_phase' AND `setting_value` = '3';
