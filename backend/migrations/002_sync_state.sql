-- Миграция 002: состояние постраничной синхронизации
-- sync_phase: 0=задачи, 1=пользователи, 2=ожидание (сброс при новом запуске)
-- sync_tasks_offset, sync_users_offset — смещения для следующей страницы

ALTER TABLE `integration_settings`
  ADD COLUMN `sync_phase` tinyint unsigned NOT NULL DEFAULT 2 COMMENT '0=tasks, 1=users, 2=idle' AFTER `last_sync_at`,
  ADD COLUMN `sync_tasks_offset` int unsigned NOT NULL DEFAULT 0 AFTER `sync_phase`,
  ADD COLUMN `sync_users_offset` int unsigned NOT NULL DEFAULT 0 AFTER `sync_tasks_offset`;
