# Bitrix25 Planner

Приложение для планирования нагрузки специалистов на основе данных из Битрикс24.

## Структура

- **backend/** — PHP API (чистый PHP, PDO, REST), точка входа `public/index.php`
- **frontend/** — Vue 3 + Vite, Vue Router, Pinia, SCSS
- **docs/** — документация, SQL-скрипты

## Запуск

### Backend

1. Скопировать `.env.example` в `.env`, заполнить `DB_*` и при необходимости `BITRIX24_WEBHOOK_URL`.
2. Выполнить `docs/sql/01_create_db.sql` (создание БД и пользователя).
3. В каталоге `backend`: `composer install`.
4. Настроить веб-сервер на `backend/public` (или `php -S localhost:8000 -t backend/public` для разработки).

### Frontend

1. В каталоге `frontend`: `npm install`, затем `npm run dev`.
2. Для запросов к API в dev можно использовать proxy (настроен в `vite.config.js` на `/api` → `http://localhost:8000`). Либо задать `VITE_API_BASE_URL` в `.env`.

### Доступ

- Защита по .htpasswd: см. `docs/htpasswd-setup.md` и `docs/ACCESS.md`.
- Bitrix24 в MVP подключается по токену вебхука (OAuth не используется).
