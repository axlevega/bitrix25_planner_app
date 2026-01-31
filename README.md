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

### Деплой на сервер по FTP

1. В корне: скопировать `.env.example` в `.env`, заполнить `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` (и при необходимости `FTP_REMOTE_DIR`, `FTP_REMOTE_BACKEND`, `FTP_REMOTE_FRONTEND`).
2. В корне: `npm install`, затем `npm run deploy`.  
   Собирается фронт и по FTP загружаются backend (без vendor и .env) и frontend/dist. Данные БД на сервере хранить в `.env` или в `backend/.env` / `backend/.env.local` (эти файлы не участвуют в деплое и не попадают в git). Подробнее — `docs/DEPLOY.md`.

### Доступ

- Защита по .htpasswd: см. `docs/htpasswd-setup.md` и `docs/ACCESS.md`.
- Bitrix24 в MVP подключается по токену вебхука (OAuth не используется).
