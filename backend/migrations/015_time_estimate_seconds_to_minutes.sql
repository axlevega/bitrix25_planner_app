-- Миграция 015: приведение time_estimate к минутам
-- В Bitrix24 REST API TIME_ESTIMATE приходит в секундах; в БД и в коде везде используются минуты.
-- Старые записи могли быть сохранены в секундах. Значения > 1440 (24*60) почти наверняка секунды
-- (оценка > 24 часов в минутах встречается редко) — конвертируем в минуты.

UPDATE bitrix24_tasks_cache
SET time_estimate = ROUND(time_estimate / 60)
WHERE time_estimate > 1440;

UPDATE task_plan_override
SET original_time_estimate = ROUND(original_time_estimate / 60)
WHERE original_time_estimate > 1440;
