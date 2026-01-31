# Настройка .htpasswd

1. Создать файл паролей (из корня проекта или любой папки):
   ```bash
   htpasswd -c .htpasswd admin
   ```
2. Добавить пользователей без перезаписи файла:
   ```bash
   htpasswd .htpasswd another_user
   ```
3. Включить защиту в `backend/public/.htaccess` и в конфиге раздачи фронта — раскомментировать блок `AuthType Basic` и задать **абсолютный путь** к `.htpasswd` в `AuthUserFile`.

Файл `.htpasswd` в репозиторий не входит (см. `.gitignore`).
