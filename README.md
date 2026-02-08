# Bitrix25 Planner

Приложение для планирования нагрузки специалистов на основе данных из Битрикс24.

## Структура

- **backend/** — PHP API (чистый PHP, PDO, REST), точка входа `public/index.php`
- **frontend/** — Vue 3 + Vite, Vue Router, Pinia, SCSS
- **docs/** — документация, SQL-скрипты

## Запуск

### Вариант 1: Локальный фронт + удалённый бэкенд

Бэкенд уже на сервере, фронт запускаете у себя.

1. В **frontend** создайте `frontend/.env` (из `frontend/.env.example`).
2. Укажите URL API: `VITE_API_BASE_URL=https://ваш-домен.ru/api`.
3. В каталоге frontend: `npm install`, затем `npm run dev`.
4. Откройте в браузере адрес Vite (обычно http://localhost:5173). Запросы пойдут на удалённый API (CORS уже настроен на бэкенде).

### Вариант 2: Всё на сервере (деплой)

Собрать и залить проект на сервер, тестировать по домену.

1. В **frontend/.env** оставьте `VITE_API_BASE_URL=` пустым или задайте `/api`.
2. Настройте FTP в корневом `.env`, выполните `npm run deploy`.
3. На сервере настройте БД, `.env`, `composer install` в backend. Открывайте сайт по домену.

Подробнее — **docs/DEPLOY.md** (оба варианта и настройка FTP).

### Деплой на сервер по FTP

1. В корне: скопировать `.env.example` в `.env`, заполнить `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` (и при необходимости `FTP_REMOTE_DIR`, `FTP_REMOTE_BACKEND`, `FTP_REMOTE_FRONTEND`).
2. В корне: `npm install`, затем `npm run deploy`.  
   Собирается фронт и по FTP загружаются backend (без vendor и .env) и frontend/dist. Данные БД на сервере хранить в `.env` или в `backend/.env` / `backend/.env.local` (эти файлы не участвуют в деплое и не попадают в git). Подробнее — `docs/DEPLOY.md`.

### Документация и планы

- **PLANING.md** — техническое задание, видение продукта, текущее состояние и план дальнейших работ.
- **CHECKLIST.md** — чеклист реализации по фазам (отмечать выполненное).
- **backend/migrations/README.md** — список миграций БД и порядок применения.

### Доступ

- Защита по .htpasswd: см. `docs/htpasswd-setup.md` и `docs/ACCESS.md`.
- Bitrix24 в MVP подключается по токену вебхука (OAuth не используется).
