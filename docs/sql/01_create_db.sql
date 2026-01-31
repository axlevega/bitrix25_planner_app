-- Фаза 0: создание БД и пользователя.
-- Таблицы создаются в фазе 1 (миграции).

CREATE DATABASE IF NOT EXISTS bitrix25_planner
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'bitrix25_planner'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON bitrix25_planner.* TO 'bitrix25_planner'@'localhost';
FLUSH PRIVILEGES;
