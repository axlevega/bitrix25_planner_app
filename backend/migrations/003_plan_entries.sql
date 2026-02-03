-- Миграция 003: плановые записи (фаза 2)
-- specialist_id, период (date_from, date_to), часы, тип работы (regular/flight), привязка к задаче B24

CREATE TABLE IF NOT EXISTS `plan_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `specialist_id` int unsigned NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `hours` decimal(6,2) NOT NULL,
  `work_type_id` int unsigned NOT NULL DEFAULT 1 COMMENT '1=regular, 2=flight',
  `source` varchar(32) NOT NULL DEFAULT 'manual' COMMENT 'manual, bitrix24_task_id',
  `bitrix24_task_id` varchar(64) DEFAULT NULL,
  `note` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_specialist_dates` (`specialist_id`, `date_from`, `date_to`),
  KEY `idx_dates` (`date_from`, `date_to`),
  CONSTRAINT `fk_plan_entries_specialist` FOREIGN KEY (`specialist_id`) REFERENCES `specialists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plan_entries_work_type` FOREIGN KEY (`work_type_id`) REFERENCES `work_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
