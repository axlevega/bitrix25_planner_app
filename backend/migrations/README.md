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

- **001_initial_schema.sql** — таблицы: departments, specialists, work_types (удалён в 019), bitrix24_*, integration_settings, sync_log.
- **002_sync_state.sql** — колонки в integration_settings для постраничной синхронизации: sync_phase, sync_tasks_offset, sync_users_offset.
- **003_plan_entries.sql** — таблица плановых записей (удалена в 019).
- **004_project_work_type.sql** — справочник «проект B24 (group_id) → тип работы» (удалён в 018).
- **005_task_elapsed.sql** — учёт времени по задачам B24 по дням: bitrix24_task_id, bitrix24_user_id, elapsed_date, minutes (для сетки планирования).
- **006_sync_elapsed_state.sql** — колонка sync_elapsed_task_offset в integration_settings для фазы синхронизации elapsed (phase 2).
- **007_task_plan_dates.sql** — колонки start_date_plan, end_date_plan, created_date в bitrix24_tasks_cache для расчёта планируемых трудозатрат по дням (план/факт в сетке).
- **008_task_plan_override.sql** — переплан ПМ: даты переплана и снимок исходных дат/оценки из B24.
- **009_task_plan_daily.sql** — скорректированные плановые часы по дням (task_id, plan_date, planned_hours).
- **010_integration_sync_options.sql** — настройки синхронизации: диапазон дат (sync_date_range_type, sync_date_from, sync_date_to) и список специалистов (sync_specialist_ids) для фильтра задач.
- **011_integration_settings_key_value.sql** — переход integration_settings на модель «одна строка = одна настройка» (setting_key, setting_value). Новые настройки добавляются без изменения схемы. Применять после 010.
- **012_bitrix24_task_custom_field.sql** — таблица пользовательских полей задач Bitrix24 (UF_*): bitrix24_task_id, field_code, value_text; для фильтрации по кастомным полям (например «Флайт»).
- **013_bitrix24_task_uf_catalog.sql** — каталог уникальных пользовательских полей (field_code, label); в 012 пишутся только значения по полям из каталога (системные поля вроде вложений/почты/CRM исключены).
- **014_planning_default_department.sql** — настройка отдела по умолчанию для экрана планирования.
- **015_time_estimate_seconds_to_minutes.sql** — приведение хранения оценки времени к минутам (если было в секундах).
- **016_bitrix24_task_groups.sql** — таблица групп задач Bitrix24 (проекты): bitrix24_group_id, name; ключ sync_groups_offset; синхронизация через sonet.group.get (фаза 0 в общей синхронизации).
- **017_bitrix24_task_status.sql** — статусы задач B24 (см. файл).
- **018_drop_project_work_type.sql** — удаление таблицы project_work_type; тип задачи определяется пользовательским свойством в B24.
- **019_drop_plan_entries_and_work_types.sql** — удаление таблиц plan_entries и work_types; загрузка считается только по задачам B24.