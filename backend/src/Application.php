<?php
declare(strict_types=1);

namespace App;

use App\Router;
use App\Service\LoadService;
use App\Service\SyncService;

final class Application
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function run(): void
    {
        $this->sendCorsHeaders();
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = rtrim(preg_replace('#^/api#', '', $path) ?: '/', '/') ?: '/';

        $handler = $this->router->match($method, $path);
        if ($handler === null) {
            $this->json(['error' => 'Not Found'], 404);
            return;
        }

        try {
            $response = $handler($this->getRequestPayload());
            if (is_array($response)) {
                $this->json($response);
            }
        } catch (\Throwable $e) {
            $this->json(['error' => 'Internal Server Error', 'message' => $e->getMessage()], 500);
        }
    }

    private function registerRoutes(): void
    {
        $this->router->get('/', function (): array {
            return ['app' => 'bitrix25-planner', 'version' => '0.1', 'status' => 'ok'];
        });

        // Отладка учёта времени B24: один запрос task.elapseditem.getlist по task_id (проверка, возвращает ли B24 данные)
        $this->router->get('/debug-elapsed', function (array $payload): array {
            $pdo = Database::getConnection();
            $taskId = trim((string) ($payload['task_id'] ?? ''));
            if ($taskId === '') {
                $row = $pdo->query('SELECT bitrix24_task_id FROM bitrix24_tasks_cache LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
                $taskId = $row['bitrix24_task_id'] ?? '';
            }
            if ($taskId === '') {
                return ['error' => 'Нет задач в кэше, укажите task_id или сначала запустите синхронизацию задач'];
            }
            $url = null;
            $stmt = $pdo->query('SELECT webhook_token, portal_url FROM integration_settings ORDER BY id LIMIT 1');
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && trim((string) ($row['portal_url'] ?? '')) !== '' && trim((string) ($row['webhook_token'] ?? '')) !== '') {
                $url = rtrim($row['portal_url'], '/') . '/rest/' . trim($row['webhook_token'], '/') . '/';
            }
            if ($url === null) {
                $url = $_ENV['BITRIX24_WEBHOOK_URL'] ?? getenv('BITRIX24_WEBHOOK_URL') ?: null;
            }
            if ($url === null || $url === '') {
                return ['error' => 'Webhook URL не задан', 'task_id' => $taskId];
            }
            $client = new \App\Bitrix24\Client($url);
            $result = $client->getTaskElapsedItems($taskId);
            $raw = $client->call('task.elapseditem.getlist', ['TASK_ID' => $taskId]);
            return [
                'task_id' => $taskId,
                'parsed_count' => isset($result['data']) ? count($result['data']) : 0,
                'parsed_sample' => isset($result['data']) && $result['data'] !== [] ? array_slice($result['data'], 0, 3) : null,
                'b24_error' => $result['error'] ?? null,
                'b24_error_description' => $result['error_description'] ?? null,
                'b24_raw_keys' => is_array($raw) ? array_keys($raw) : null,
                'b24_result_type' => isset($raw['result']) ? gettype($raw['result']) : null,
            ];
        });

        // Отладка подключения к БД: какие .env найдены, установлены ли DB_*, текст ошибки подключения
        $this->router->get('/debug-db', function (): array {
            $paths = $_ENV['_ENV_DEBUG_PATHS'] ?? [];
            $found = $_ENV['_ENV_DEBUG_FOUND'] ?? [];
            $dbHostSet = !empty($_ENV['DB_HOST']);
            $dbUserSet = !empty($_ENV['DB_USER']);
            $dbNameSet = !empty($_ENV['DB_NAME']);
            $dbPasswordSet = isset($_ENV['DB_PASSWORD']) && $_ENV['DB_PASSWORD'] !== '';
            $connectionError = null;
            try {
                Database::getConnection();
            } catch (\Throwable $e) {
                $connectionError = $e->getMessage();
            }
            return [
                'env_paths_checked' => $paths,
                'env_files_found' => $found,
                'db_host_set' => $dbHostSet,
                'db_user_set' => $dbUserSet,
                'db_name_set' => $dbNameSet,
                'db_password_set' => $dbPasswordSet,
                'connection_ok' => $connectionError === null,
                'connection_error' => $connectionError,
            ];
        });

        // PDO получаем только в обработчиках (не при OPTIONS preflight), иначе ошибка БД даёт 500 до CORS
        // Отделы
        $this->router->get('/departments', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = $payload['id'] ?? null;
            if ($id !== null && $id !== '') {
                $stmt = $pdo->prepare('SELECT * FROM departments WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: ['error' => 'Not found'];
            }
            $stmt = $pdo->query('SELECT * FROM departments ORDER BY name');
            return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        });
        $this->router->post('/departments', function (array $payload): array {
            $pdo = Database::getConnection();
            $name = trim((string) ($payload['name'] ?? ''));
            $type = trim((string) ($payload['type'] ?? ''));
            $externalId = trim((string) ($payload['external_id'] ?? ''));
            if ($name === '') {
                return ['error' => 'name обязателен'];
            }
            $pdo->prepare('INSERT INTO departments (name, type, external_id) VALUES (?, ?, ?)')
                ->execute([$name, $type ?: null, $externalId ?: null]);
            return ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'type' => $type ?: null, 'external_id' => $externalId ?: null];
        });
        $this->router->put('/departments', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return ['error' => 'Not found'];
            }
            $name = trim((string) ($payload['name'] ?? ''));
            $type = trim((string) ($payload['type'] ?? ''));
            $externalId = trim((string) ($payload['external_id'] ?? ''));
            $pdo->prepare('UPDATE departments SET name=?, type=?, external_id=? WHERE id=?')
                ->execute([$name, $type ?: null, $externalId ?: null, $id]);
            return ['id' => $id, 'ok' => true];
        });

        // Специалисты
        $this->router->get('/specialists', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = $payload['id'] ?? null;
            if ($id !== null && $id !== '') {
                $stmt = $pdo->prepare('SELECT s.*, d.name as department_name FROM specialists s LEFT JOIN departments d ON s.department_id = d.id WHERE s.id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: ['error' => 'Not found'];
            }
            $stmt = $pdo->query('SELECT s.*, d.name as department_name FROM specialists s LEFT JOIN departments d ON s.department_id = d.id ORDER BY s.name');
            return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        });
        $this->router->post('/specialists', function (array $payload): array {
            $pdo = Database::getConnection();
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                return ['error' => 'name обязателен'];
            }
            $departmentId = isset($payload['department_id']) ? (int) $payload['department_id'] : null;
            $b24UserId = trim((string) ($payload['bitrix24_user_id'] ?? ''));
            $normDay = isset($payload['norm_hours_per_day']) ? (float) $payload['norm_hours_per_day'] : null;
            $normWeek = isset($payload['norm_hours_per_week']) ? (float) $payload['norm_hours_per_week'] : null;
            $flightDay = isset($payload['flight_hours_limit_per_day']) ? (float) $payload['flight_hours_limit_per_day'] : null;
            $flightWeek = isset($payload['flight_hours_limit_per_week']) ? (float) $payload['flight_hours_limit_per_week'] : null;
            $isActive = isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1;
            $pdo->prepare('INSERT INTO specialists (bitrix24_user_id, department_id, name, norm_hours_per_day, norm_hours_per_week, flight_hours_limit_per_day, flight_hours_limit_per_week, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$b24UserId ?: null, $departmentId ?: null, $name, $normDay, $normWeek, $flightDay, $flightWeek, $isActive]);
            return ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
        });
        $this->router->put('/specialists', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('SELECT id FROM specialists WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return ['error' => 'Not found'];
            }
            $name = trim((string) ($payload['name'] ?? ''));
            $departmentId = isset($payload['department_id']) ? (int) $payload['department_id'] : null;
            $b24UserId = trim((string) ($payload['bitrix24_user_id'] ?? ''));
            $normDay = isset($payload['norm_hours_per_day']) ? (float) $payload['norm_hours_per_day'] : null;
            $normWeek = isset($payload['norm_hours_per_week']) ? (float) $payload['norm_hours_per_week'] : null;
            $flightDay = isset($payload['flight_hours_limit_per_day']) ? (float) $payload['flight_hours_limit_per_day'] : null;
            $flightWeek = isset($payload['flight_hours_limit_per_week']) ? (float) $payload['flight_hours_limit_per_week'] : null;
            $isActive = isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1;
            $pdo->prepare('UPDATE specialists SET name=?, department_id=?, bitrix24_user_id=?, norm_hours_per_day=?, norm_hours_per_week=?, flight_hours_limit_per_day=?, flight_hours_limit_per_week=?, is_active=? WHERE id=?')
                ->execute([$name, $departmentId ?: null, $b24UserId ?: null, $normDay, $normWeek, $flightDay, $flightWeek, $isActive, $id]);
            return ['id' => $id, 'ok' => true];
        });

        // Настройки интеграции (одна запись; токен не отдаём в ответе)
        $this->router->get('/integration-settings', function (): array {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT id, portal_url, sync_interval_minutes, last_sync_at, created_at, updated_at FROM integration_settings ORDER BY id LIMIT 1');
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: ['portal_url' => '', 'sync_interval_minutes' => 30, 'last_sync_at' => null];
        });
        $this->router->post('/integration-settings', function (array $payload): array {
            $pdo = Database::getConnection();
            $portalUrl = trim((string) ($payload['portal_url'] ?? ''));
            $webhookToken = trim((string) ($payload['webhook_token'] ?? ''));
            $interval = isset($payload['sync_interval_minutes']) ? (int) $payload['sync_interval_minutes'] : 30;
            $stmt = $pdo->query('SELECT id FROM integration_settings LIMIT 1');
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare('UPDATE integration_settings SET portal_url=?, webhook_token=?, sync_interval_minutes=? WHERE id=?')
                    ->execute([$portalUrl, $webhookToken, $interval, $row['id']]);
                return ['id' => (int) $row['id'], 'portal_url' => $portalUrl, 'sync_interval_minutes' => $interval];
            }
            $pdo->prepare('INSERT INTO integration_settings (portal_url, webhook_token, sync_interval_minutes) VALUES (?, ?, ?)')
                ->execute([$portalUrl, $webhookToken, $interval]);
            return ['id' => (int) $pdo->lastInsertId(), 'portal_url' => $portalUrl, 'sync_interval_minutes' => $interval];
        });

        // Плановые записи (фаза 2)
        $this->router->get('/plan-entries', function (array $payload): array {
            $pdo = Database::getConnection();
            $specialistId = isset($payload['specialist_id']) ? (int) $payload['specialist_id'] : null;
            $dateFrom = trim((string) ($payload['date_from'] ?? ''));
            $dateTo = trim((string) ($payload['date_to'] ?? ''));
            $sql = 'SELECT pe.*, s.name as specialist_name, wt.name as work_type_name FROM plan_entries pe
                    JOIN specialists s ON pe.specialist_id = s.id
                    JOIN work_types wt ON pe.work_type_id = wt.id WHERE 1=1';
            $params = [];
            if ($specialistId > 0) {
                $sql .= ' AND pe.specialist_id = ?';
                $params[] = $specialistId;
            }
            if ($dateFrom !== '') {
                $sql .= ' AND pe.date_to >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $sql .= ' AND pe.date_from <= ?';
                $params[] = $dateTo;
            }
            $sql .= ' ORDER BY pe.date_from, pe.specialist_id';
            $stmt = $params === [] ? $pdo->query($sql) : $pdo->prepare($sql);
            if ($params !== []) {
                $stmt->execute($params);
            }
            return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        });
        $this->router->post('/plan-entries', function (array $payload): array {
            $pdo = Database::getConnection();
            $specialistId = (int) ($payload['specialist_id'] ?? 0);
            $dateFrom = trim((string) ($payload['date_from'] ?? ''));
            $dateTo = trim((string) ($payload['date_to'] ?? ''));
            $hours = isset($payload['hours']) ? (float) $payload['hours'] : 0.0;
            $workTypeId = isset($payload['work_type_id']) ? (int) $payload['work_type_id'] : 1;
            $note = trim((string) ($payload['note'] ?? ''));
            $bitrix24TaskId = trim((string) ($payload['bitrix24_task_id'] ?? ''));
            if ($specialistId <= 0 || $dateFrom === '' || $dateTo === '') {
                return ['error' => 'specialist_id, date_from, date_to обязательны'];
            }
            if ($hours < 0) {
                return ['error' => 'hours должно быть >= 0'];
            }
            if ($dateFrom > $dateTo) {
                return ['error' => 'date_from не может быть позже date_to'];
            }
            $stmt = $pdo->prepare('SELECT id FROM specialists WHERE id = ?');
            $stmt->execute([$specialistId]);
            if (!$stmt->fetch()) {
                return ['error' => 'Specialist not found'];
            }
            $pdo->prepare('INSERT INTO plan_entries (specialist_id, date_from, date_to, hours, work_type_id, source, bitrix24_task_id, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$specialistId, $dateFrom, $dateTo, $hours, $workTypeId, $bitrix24TaskId !== '' ? 'bitrix24_task_id' : 'manual', $bitrix24TaskId ?: null, $note ?: null]);
            return ['id' => (int) $pdo->lastInsertId(), 'specialist_id' => $specialistId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'hours' => $hours];
        });
        $this->router->put('/plan-entries', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('SELECT id FROM plan_entries WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return ['error' => 'Not found'];
            }
            $specialistId = isset($payload['specialist_id']) ? (int) $payload['specialist_id'] : null;
            $dateFrom = isset($payload['date_from']) ? trim((string) $payload['date_from']) : null;
            $dateTo = isset($payload['date_to']) ? trim((string) $payload['date_to']) : null;
            $hours = isset($payload['hours']) ? (float) $payload['hours'] : null;
            $workTypeId = isset($payload['work_type_id']) ? (int) $payload['work_type_id'] : null;
            $note = isset($payload['note']) ? trim((string) $payload['note']) : null;
            $bitrix24TaskId = isset($payload['bitrix24_task_id']) ? trim((string) $payload['bitrix24_task_id']) : null;
            $updates = [];
            $params = [];
            if ($specialistId !== null) {
                $updates[] = 'specialist_id = ?';
                $params[] = $specialistId;
            }
            if ($dateFrom !== null) {
                $updates[] = 'date_from = ?';
                $params[] = $dateFrom;
            }
            if ($dateTo !== null) {
                $updates[] = 'date_to = ?';
                $params[] = $dateTo;
            }
            if ($hours !== null) {
                $updates[] = 'hours = ?';
                $params[] = $hours;
            }
            if ($workTypeId !== null) {
                $updates[] = 'work_type_id = ?';
                $params[] = $workTypeId;
            }
            if ($note !== null) {
                $updates[] = 'note = ?';
                $params[] = $note ?: null;
            }
            if ($bitrix24TaskId !== null) {
                $updates[] = 'bitrix24_task_id = ?';
                $params[] = $bitrix24TaskId ?: null;
            }
            if ($updates === []) {
                return ['id' => $id, 'ok' => true];
            }
            $params[] = $id;
            $pdo->prepare('UPDATE plan_entries SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            return ['id' => $id, 'ok' => true];
        });
        $this->router->delete('/plan-entries', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('DELETE FROM plan_entries WHERE id = ?');
            $stmt->execute([$id]);
            return ['id' => $id, 'deleted' => $stmt->rowCount() > 0];
        });

        // Справочник «проект B24 (group_id) → тип работы» (регулярка/флайт)
        $this->router->get('/project-work-types', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = $payload['id'] ?? null;
            if ($id !== null && $id !== '') {
                $stmt = $pdo->prepare('SELECT pwt.*, wt.code as work_type_code, wt.name as work_type_name FROM project_work_type pwt JOIN work_types wt ON pwt.work_type_id = wt.id WHERE pwt.id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: ['error' => 'Not found'];
            }
            $stmt = $pdo->query('SELECT pwt.*, wt.code as work_type_code, wt.name as work_type_name FROM project_work_type pwt JOIN work_types wt ON pwt.work_type_id = wt.id ORDER BY pwt.bitrix24_group_id');
            return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        });
        $this->router->post('/project-work-types', function (array $payload): array {
            $pdo = Database::getConnection();
            $groupId = trim((string) ($payload['bitrix24_group_id'] ?? ''));
            $workTypeId = (int) ($payload['work_type_id'] ?? 1);
            if ($groupId === '') {
                return ['error' => 'bitrix24_group_id обязателен'];
            }
            if ($workTypeId < 1 || $workTypeId > 2) {
                return ['error' => 'work_type_id должен быть 1 (regular) или 2 (flight)'];
            }
            $pdo->prepare('INSERT INTO project_work_type (bitrix24_group_id, work_type_id) VALUES (?, ?)')
                ->execute([$groupId, $workTypeId]);
            return ['id' => (int) $pdo->lastInsertId(), 'bitrix24_group_id' => $groupId, 'work_type_id' => $workTypeId];
        });
        $this->router->put('/project-work-types', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('SELECT id FROM project_work_type WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return ['error' => 'Not found'];
            }
            $workTypeId = (int) ($payload['work_type_id'] ?? 1);
            if ($workTypeId < 1 || $workTypeId > 2) {
                return ['error' => 'work_type_id должен быть 1 или 2'];
            }
            $pdo->prepare('UPDATE project_work_type SET work_type_id = ? WHERE id = ?')->execute([$workTypeId, $id]);
            return ['id' => $id, 'ok' => true];
        });
        $this->router->delete('/project-work-types', function (array $payload): array {
            $pdo = Database::getConnection();
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'id обязателен'];
            }
            $stmt = $pdo->prepare('DELETE FROM project_work_type WHERE id = ?');
            $stmt->execute([$id]);
            return ['id' => $id, 'deleted' => $stmt->rowCount() > 0];
        });

        // Сетка планирования: задачи выбранных специалистов + учёт времени по дням (для шахматки)
        $this->router->get('/planning-grid', function (array $payload): array {
            $pdo = Database::getConnection();
            $specialistIdsRaw = trim((string) ($payload['specialist_ids'] ?? ''));
            $departmentId = isset($payload['department_id']) ? (int) $payload['department_id'] : 0;
            $dateFrom = trim((string) ($payload['date_from'] ?? ''));
            $dateTo = trim((string) ($payload['date_to'] ?? ''));
            if ($dateFrom === '' || $dateTo === '') {
                return ['error' => 'date_from and date_to required', 'tasks' => [], 'elapsed' => [], 'specialists' => []];
            }
            $b24UserIds = [];
            $specialists = [];
            if ($departmentId > 0) {
                $stmt = $pdo->prepare('SELECT s.id, s.name, s.bitrix24_user_id FROM specialists s WHERE s.department_id = ? AND s.is_active = 1');
                $stmt->execute([$departmentId]);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $specialists[] = $row;
                    if (!empty($row['bitrix24_user_id'])) {
                        $b24UserIds[] = $row['bitrix24_user_id'];
                    }
                }
            } elseif ($specialistIdsRaw !== '') {
                $ids = array_filter(array_map('intval', explode(',', $specialistIdsRaw)));
                if ($ids === []) {
                    return ['error' => 'specialist_ids required', 'tasks' => [], 'elapsed' => [], 'specialists' => []];
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT s.id, s.name, s.bitrix24_user_id FROM specialists s WHERE s.id IN ($placeholders)");
                $stmt->execute($ids);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $specialists[] = $row;
                    if (!empty($row['bitrix24_user_id'])) {
                        $b24UserIds[] = $row['bitrix24_user_id'];
                    }
                }
            }
            $portalUrl = '';
            $rowPortal = $pdo->query('SELECT portal_url FROM integration_settings ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
            if ($rowPortal && !empty(trim((string) ($rowPortal['portal_url'] ?? '')))) {
                $portalUrl = rtrim(trim($rowPortal['portal_url']), '/');
            }
            if ($b24UserIds === []) {
                return ['tasks' => [], 'elapsed' => [], 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl];
            }
            $placeholders = implode(',', array_fill(0, count($b24UserIds), '?'));
            // Сначала получаем учёт времени только за выбранный период
            $stmt = $pdo->prepare("SELECT e.bitrix24_task_id as task_id, e.bitrix24_user_id as user_id, e.elapsed_date as date, e.minutes FROM bitrix24_task_elapsed e
                INNER JOIN bitrix24_tasks_cache t ON t.bitrix24_task_id = e.bitrix24_task_id
                WHERE t.responsible_user_id IN ($placeholders) AND e.elapsed_date >= ? AND e.elapsed_date <= ?");
            $stmt->execute(array_merge($b24UserIds, [$dateFrom, $dateTo]));
            $elapsed = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $taskIdsInPeriod = array_values(array_unique(array_column($elapsed, 'task_id')));
            if ($taskIdsInPeriod === []) {
                return ['tasks' => [], 'elapsed' => [], 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl];
            }
            $phTask = implode(',', array_fill(0, count($taskIdsInPeriod), '?'));
            $stmt = $pdo->prepare("SELECT bitrix24_task_id, title, responsible_user_id, deadline, time_estimate, time_spent, group_id FROM bitrix24_tasks_cache WHERE bitrix24_task_id IN ($phTask) ORDER BY deadline, bitrix24_task_id");
            $stmt->execute($taskIdsInPeriod);
            $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return ['tasks' => $tasks, 'elapsed' => $elapsed, 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl];
        });

        // Расчёт загрузки за период (фаза 2): specialist_id или department_id, date_from, date_to
        $this->router->get('/load', function (array $payload): array {
            $pdo = Database::getConnection();
            $specialistId = isset($payload['specialist_id']) ? (int) $payload['specialist_id'] : null;
            $departmentId = isset($payload['department_id']) ? (int) $payload['department_id'] : null;
            $dateFrom = trim((string) ($payload['date_from'] ?? ''));
            $dateTo = trim((string) ($payload['date_to'] ?? ''));
            if ($dateFrom === '' || $dateTo === '') {
                return ['error' => 'date_from and date_to required'];
            }
            $service = new LoadService($pdo);
            if ($specialistId > 0) {
                return $service->getSpecialistLoad($specialistId, $dateFrom, $dateTo);
            }
            if ($departmentId > 0) {
                return $service->getDepartmentLoad($departmentId, $dateFrom, $dateTo);
            }
            return ['error' => 'specialist_id or department_id required'];
        });

        // Ручной запуск синхронизации: один чанк за запрос (до 50 задач или 50 пользователей), фронт вызывает в цикле пока has_more
        $this->router->post('/sync', function (): array {
            set_time_limit(60);
            $pdo = Database::getConnection();
            $service = new SyncService($pdo);
            $result = $service->runChunk();
            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'tasks_count' => $result['tasks_count'] ?? 0,
                'users_count' => $result['users_count'] ?? 0,
                'has_more' => $result['has_more'] ?? false,
            ];
        });
    }

    private function sendCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json; charset=utf-8');
    }

    private function getRequestPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return array_merge($_GET, $_POST);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_merge($_GET, $decoded) : $_GET;
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
