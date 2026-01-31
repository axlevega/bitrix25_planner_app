# Деплой на сервер по FTP

## Локально (откуда запускается деплой)

1. В корне проекта скопируйте `.env.example` в `.env`.
2. Заполните переменные FTP (и при необходимости DB для локальной разработки):
   - `FTP_HOST` — хост FTP-сервера
   - `FTP_USER` — логин
   - `FTP_PASSWORD` — пароль
   - `FTP_PORT` — порт (по умолчанию 21)
   - `FTP_SECURE=true` — для FTPS (TLS)
   - `FTP_REMOTE_DIR` — корневой путь на сервере (например `/public_html`)
   - `FTP_REMOTE_BACKEND` — подкаталог для бэкенда (по умолчанию `backend`)
   - `FTP_REMOTE_FRONTEND` — подкаталог для статики фронта (по умолчанию `public`)

3. Установите зависимости в корне и во фронте:
   ```bash
   npm install
   cd frontend && npm install && cd ..
   ```

4. Запуск деплоя:
   ```bash
   npm run deploy
   ```

Скрипт собирает фронт и загружает по FTP:
- каталог **backend** (без `vendor`, `.env`, `.env.local`) в `FTP_REMOTE_DIR/FTP_REMOTE_BACKEND`;
- каталог **frontend/dist** в `FTP_REMOTE_DIR/FTP_REMOTE_FRONTEND`;
- корневой **.htaccess** в `FTP_REMOTE_DIR` (файл-шаблон: `deploy/root.htaccess`).

Файлы `.env`, `backend/.env`, `backend/.env.local` **не загружаются** — данные БД и секреты на сервере хранятся отдельно.

### Роутинг на виртуальном хостинге (public_html)

Корневой `.htaccess` настраивает:
- запросы **/api** и **/api/** → `backend/public/index.php` (API);
- запросы к существующим файлам/папкам в каталоге фронта → отдача из него;
- всё остальное → `public/index.html` (SPA, Vue Router).

Имена каталогов (`backend`, `public`) подставляются из `FTP_REMOTE_BACKEND` и `FTP_REMOTE_FRONTEND`. Для стандартного деплоя в `/public_html` после деплоя при открытии домена должна открываться страница приложения.

---

## На сервере после деплоя

### Требования: PHP 8.1+

Бэкенд требует **PHP 8.1 или выше**. Если на хостинге по умолчанию старая версия (например 5.6), в панели хостинга нужно переключить версию PHP для сайта на 8.1 или 8.2 (cPanel → «Select PHP Version», Plesk → «PHP version» и т.п.). Без этого `composer install` выдаст ошибку «your php version does not satisfy that requirement».

Если при `composer install` мешает глобальный плагин (например `fxp/composer-asset-plugin`), запустите:
```bash
composer install --no-plugins
```

### Данные подключения к БД

Они **не участвуют в деплое** и не попадают в git. Варианты:

1. **Файл `.env` в корне развёрнутого приложения**  
   Создайте на сервере вручную файл `.env` в корне (рядом с `backend/` и `public/`), задайте в нём `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. `backend/bootstrap.php` читает этот файл.

2. **Файл в каталоге backend (удобно, если корень недоступен)**  
   Создайте на сервере `backend/.env` или `backend/.env.local` с переменными `DB_*`. Эти файлы в `.gitignore` и при деплое не загружаются. В `backend/bootstrap.php` предусмотрена загрузка `backend/.env` и `backend/.env.local`.

После первого деплоя на сервере нужно один раз выполнить `composer install` в каталоге `backend` (каталог `vendor` по FTP не заливается).
