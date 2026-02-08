# Frontend — Bitrix25 Planner

Vue 3 + Vite, Vue Router, Pinia, SCSS. Сборка для разработки и продакшена.

## Запуск

```bash
npm install
npm run dev
```

Для работы с API задайте в `frontend/.env` (из `.env.example`):

- `VITE_API_BASE_URL` — URL бэкенда (например `https://ваш-домен.ru/api` или пусто для относительного `/api`)

## Сборка

```bash
npm run build
```

Артефакты в `dist/`. При деплое через корневой `npm run deploy` сборка выполняется автоматически.

## Структура

- `src/views/` — страницы: Home, Настройки (Отделы, Специалисты, Синхронизация, Фильтры), Планирование (сетка), Загрузка
- `src/components/` — общие компоненты и UI-кит
- `src/api/client.js` — клиент запросов к backend API
- `src/router/`, `src/stores/` — роутинг и состояние

Подробнее о проекте — корневой `README.md` и `docs/DEPLOY.md`.
