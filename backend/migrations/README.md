# Миграции БД

Все изменения схемы БД фиксируются здесь в виде SQL-файлов.

## Именование

- `NNN_краткое_описание.sql` — нумерация с ведущими нулями (001, 002, …), порядок применения — по возрастанию.
- Внутри файла допустимы комментарии `--` и разбиение на несколько `CREATE TABLE` / `ALTER TABLE` по смыслу.

## Как применять

1. Создание БД и пользователя — один раз по скрипту `docs/sql/01_create_db.sql` (в миграции не входит).
2. Миграции применять **по порядку**, только те, что ещё не выполнялись:
   - вручную: `mysql -u user -p bitrix25_planner < backend/migrations/001_initial_schema.sql`;
   - либо через скрипт (если добавлен): `php backend/scripts/migrate.php` из корня проекта.

Перед применением новой миграции сделайте бэкап БД.

## Список миграций

- **001_initial_schema.sql** — таблицы: departments, specialists, work_types, bitrix24_*, integration_settings, sync_log.
- **002_sync_state.sql** — колонки в integration_settings для постраничной синхронизации: sync_phase, sync_tasks_offset, sync_users_offset.
- **003_plan_entries.sql** — таблица плановых записей: specialist_id, date_from, date_to, hours, work_type_id, source, bitrix24_task_id, note.
- **004_project_work_type.sql** — справочник «проект B24 (group_id) → тип работы»: bitrix24_group_id, work_type_id (1=regular, 2=flight).
- **005_task_elapsed.sql** — учёт времени по задачам B24 по дням: bitrix24_task_id, bitrix24_user_id, elapsed_date, minutes (для сетки планирования).
- **006_sync_elapsed_state.sql** — колонка sync_elapsed_task_offset в integration_settings для фазы синхронизации elapsed (phase 2).
- **007_task_plan_dates.sql** — колонки start_date_plan, end_date_plan, created_date в bitrix24_tasks_cache для расчёта планируемых трудозатрат по дням (план/факт в сетке).