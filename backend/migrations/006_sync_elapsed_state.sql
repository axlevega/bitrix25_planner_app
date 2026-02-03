-- Миграция 006: состояние синхронизации учёта времени (elapsed) по задачам
-- sync_phase: 0=задачи, 1=пользователи, 2=elapsed по задачам, 3=ожидание (сброс при новом запуске)
-- sync_elapsed_task_offset — смещение по списку task_id для phase=2

ALTER TABLE `integration_settings`
  ADD COLUMN `sync_elapsed_task_offset` int unsigned NOT NULL DEFAULT 0 COMMENT 'offset для phase 2 (elapsed)' AFTER `sync_users_offset`;
