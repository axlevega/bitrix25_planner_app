<?php
declare(strict_types=1);

namespace App;

use App\Repository\IntegrationSettingsRepository;
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

        // Отладка (только для разработки): учёт времени B24 по task_id
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
            $repo = new IntegrationSettingsRepository($pdo);
            $set = $repo->getValues(['portal_url', 'webhook_token']);
            if (trim((string) ($set['portal_url'] ?? '')) !== '' && trim((string) ($set['webhook_token'] ?? '')) !== '') {
                $url = rtrim($set['portal_url'], '/') . '/rest/' . trim($set['webhook_token'], '/') . '/';
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

        // Отладка (только для разработки): проверка .env и подключения к БД
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

        // Настройки интеграции: ключ — значение (одна строка БД = одна настройка). Токен не отдаём в ответе.
        $this->router->get('/integration-settings', function (): array {
            $pdo = Database::getConnection();
            $repo = new IntegrationSettingsRepository($pdo);
            $keys = ['portal_url', 'sync_interval_minutes', 'last_sync_at', 'sync_date_range_type', 'sync_date_from', 'sync_date_to', 'sync_specialist_ids', 'planning_default_department_id'];
            $v = $repo->getValues($keys);
            $out = [
                'portal_url' => $v['portal_url'] ?? '',
                'webhook_token' => '', // не отдаём сохранённый токен
                'sync_interval_minutes' => (int) ($v['sync_interval_minutes'] ?? 30),
                'last_sync_at' => ($v['last_sync_at'] ?? null) !== '' ? $v['last_sync_at'] : null,
                'sync_date_range_type' => $v['sync_date_range_type'] ?? 'month',
                'sync_date_from' => ($v['sync_date_from'] ?? '') !== '' ? $v['sync_date_from'] : null,
                'sync_date_to' => ($v['sync_date_to'] ?? '') !== '' ? $v['sync_date_to'] : null,
                'sync_specialist_ids' => [],
                'planning_default_department_id' => null,
            ];
            if (isset($v['sync_specialist_ids']) && $v['sync_specialist_ids'] !== '') {
                $decoded = json_decode($v['sync_specialist_ids'], true);
                $out['sync_specialist_ids'] = is_array($decoded) ? $decoded : [];
            }
            if (isset($v['planning_default_department_id']) && $v['planning_default_department_id'] !== '') {
                $out['planning_default_department_id'] = (int) $v['planning_default_department_id'];
            }
            return $out;
        });
        $this->router->post('/integration-settings', function (array $payload): array {
            $pdo = Database::getConnection();
            $repo = new IntegrationSettingsRepository($pdo);
            $allowed = ['portal_url', 'webhook_token', 'sync_interval_minutes', 'sync_date_range_type', 'sync_date_from', 'sync_date_to', 'sync_specialist_ids', 'planning_default_department_id'];
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }
                if ($key === 'planning_default_department_id') {
                    $val = $payload[$key];
                    $repo->setValue($key, ($val !== null && $val !== '') ? (string) (int) $val : '');
                } elseif ($key === 'sync_specialist_ids') {
                    $val = $payload[$key];
                    $repo->setValue($key, is_array($val) ? json_encode(array_values(array_map('intval', $val))) : '[]');
                } elseif ($key === 'sync_date_range_type') {
                    $val = trim((string) $payload[$key]);
                    $repo->setValue($key, in_array($val, ['week', 'month', 'half_year', 'year', 'custom'], true) ? $val : 'month');
                } elseif ($key === 'sync_date_from' || $key === 'sync_date_to') {
                    $val = isset($payload[$key]) && (string) $payload[$key] !== '' ? trim((string) $payload[$key]) : '';
                    $repo->setValue($key, $val);
                } elseif ($key === 'sync_interval_minutes') {
                    $repo->setValue($key, (string) max(5, min(1440, (int) $payload[$key])));
                } else {
                    $repo->setValue($key, trim((string) $payload[$key]));
                }
            }
            $v = $repo->getValues(['portal_url', 'sync_interval_minutes', 'sync_date_range_type', 'sync_date_from', 'sync_date_to', 'sync_specialist_ids', 'planning_default_department_id']);
            $ids = isset($v['sync_specialist_ids']) && $v['sync_specialist_ids'] !== '' ? (json_decode($v['sync_specialist_ids'], true) ?: []) : [];
            $defaultDepId = isset($v['planning_default_department_id']) && $v['planning_default_department_id'] !== '' ? (int) $v['planning_default_department_id'] : null;
            return [
                'portal_url' => $v['portal_url'] ?? '',
                'sync_interval_minutes' => (int) ($v['sync_interval_minutes'] ?? 30),
                'sync_date_range_type' => $v['sync_date_range_type'] ?? 'month',
                'sync_date_from' => ($v['sync_date_from'] ?? '') !== '' ? $v['sync_date_from'] : null,
                'sync_date_to' => ($v['sync_date_to'] ?? '') !== '' ? $v['sync_date_to'] : null,
                'sync_specialist_ids' => $ids,
                'planning_default_department_id' => $defaultDepId,
            ];
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
            $repo = new IntegrationSettingsRepository($pdo);
            $portal = $repo->getValue('portal_url');
            if ($portal !== null && trim($portal) !== '') {
                $portalUrl = rtrim(trim($portal), '/');
            }
            if ($b24UserIds === []) {
                return ['tasks' => [], 'elapsed' => [], 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl, 'task_uf_catalog' => self::getTaskUfCatalog($pdo)];
            }
            $placeholders = implode(',', array_fill(0, count($b24UserIds), '?'));
            // Сначала получаем учёт времени только за выбранный период
            $stmt = $pdo->prepare("SELECT e.bitrix24_task_id as task_id, e.bitrix24_user_id as user_id, e.elapsed_date as date, e.minutes FROM bitrix24_task_elapsed e
                INNER JOIN bitrix24_tasks_cache t ON t.bitrix24_task_id = e.bitrix24_task_id
                WHERE t.responsible_user_id IN ($placeholders) AND e.elapsed_date >= ? AND e.elapsed_date <= ?");
            $stmt->execute(array_merge($b24UserIds, [$dateFrom, $dateTo]));
            $elapsed = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $taskIdsInPeriod = array_values(array_unique(array_column($elapsed, 'task_id')));
            $filterUfField = trim((string) ($payload['filter_uf_field_code'] ?? ''));
            $filterUfValue = isset($payload['filter_uf_value']) ? (string) $payload['filter_uf_value'] : null;
            if ($filterUfField !== '' && $filterUfValue !== null && $filterUfValue !== '' && $taskIdsInPeriod !== []) {
                $check = $pdo->prepare('SELECT 1 FROM bitrix24_task_uf_catalog WHERE field_code = ?');
                $check->execute([$filterUfField]);
                if ($check->fetch()) {
                    $ph = implode(',', array_fill(0, count($taskIdsInPeriod), '?'));
                    $stmt = $pdo->prepare("SELECT DISTINCT f.bitrix24_task_id FROM bitrix24_task_custom_field f WHERE f.field_code = ? AND f.bitrix24_task_id IN ($ph) AND f.value_text = ?");
                    $stmt->execute(array_merge([$filterUfField], $taskIdsInPeriod, [$filterUfValue]));
                    $filteredTaskIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                    $taskIdsInPeriod = array_values(array_intersect($taskIdsInPeriod, $filteredTaskIds));
                    $elapsed = array_filter($elapsed, function ($e) use ($taskIdsInPeriod) {
                        return in_array($e['task_id'], $taskIdsInPeriod, true);
                    });
                }
            }
            if ($taskIdsInPeriod === []) {
                return ['tasks' => [], 'elapsed' => $elapsed, 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl, 'task_uf_catalog' => self::getTaskUfCatalog($pdo)];
            }
            $phTask = implode(',', array_fill(0, count($taskIdsInPeriod), '?'));
            $stmt = $pdo->prepare("SELECT bitrix24_task_id, title, responsible_user_id, deadline, time_estimate, time_spent, group_id, start_date_plan, end_date_plan, created_date FROM bitrix24_tasks_cache WHERE bitrix24_task_id IN ($phTask) ORDER BY deadline, bitrix24_task_id");
            $stmt->execute($taskIdsInPeriod);
            $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Сумма фактических трудозатрат из учёта по дням (если в кэше time_spent пусто)
            $totalElapsedByTask = [];
            if ($tasks !== []) {
                $stmt = $pdo->prepare("SELECT bitrix24_task_id, SUM(minutes) AS total_minutes FROM bitrix24_task_elapsed WHERE bitrix24_task_id IN ($phTask) GROUP BY bitrix24_task_id");
                $stmt->execute($taskIdsInPeriod);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $totalElapsedByTask[$row['bitrix24_task_id']] = (int) $row['total_minutes'];
                }
            }

            $overrides = [];
            $dailyByTask = [];
            if ($tasks !== []) {
                $stmt = $pdo->prepare("SELECT bitrix24_task_id, plan_start_date, plan_end_date, original_plan_start, original_plan_end, original_time_estimate FROM task_plan_override WHERE bitrix24_task_id IN ($phTask)");
                $stmt->execute($taskIdsInPeriod);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $overrides[$row['bitrix24_task_id']] = $row;
                }
                $stmt = $pdo->prepare("SELECT bitrix24_task_id, plan_date, planned_hours FROM task_plan_daily WHERE bitrix24_task_id IN ($phTask) AND plan_date >= ? AND plan_date <= ?");
                $stmt->execute(array_merge($taskIdsInPeriod, [$dateFrom, $dateTo]));
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $tid = $row['bitrix24_task_id'];
                    if (!isset($dailyByTask[$tid])) {
                        $dailyByTask[$tid] = [];
                    }
                    $dailyByTask[$tid][$row['plan_date']] = (float) $row['planned_hours'];
                }
            }

            foreach ($tasks as &$task) {
                $tid = $task['bitrix24_task_id'];
                $override = $overrides[$tid] ?? null;
                $dailyMap = $dailyByTask[$tid] ?? [];
                $task['has_plan_override'] = $override !== null;
                if ($override !== null) {
                    $task['original_plan_hours_by_date'] = self::computePlanHoursByDateWithRange(
                        $override['original_plan_start'],
                        $override['original_plan_end'],
                        (int) $override['original_time_estimate'],
                        $dateFrom,
                        $dateTo
                    );
                    $replanStart = $override['plan_start_date'] ?? $override['original_plan_start'];
                    $replanEnd = $override['plan_end_date'] ?? $override['original_plan_end'];
                    $baseReplan = self::computePlanHoursByDateWithRange(
                        $replanStart,
                        $replanEnd,
                        (int) $task['time_estimate'],
                        $dateFrom,
                        $dateTo
                    );
                } else {
                    $task['original_plan_hours_by_date'] = [];
                    $baseReplan = self::computePlanHoursByDate($task, $dateFrom, $dateTo);
                }
                $task['plan_hours_by_date'] = $baseReplan;
                foreach ($dailyMap as $d => $h) {
                    $task['plan_hours_by_date'][$d] = round($h, 1);
                }
                // Факт: из кэша или сумма из bitrix24_task_elapsed (минуты)
                if ($task['time_spent'] === null || $task['time_spent'] === '') {
                    $task['time_spent'] = $totalElapsedByTask[$tid] ?? null;
                }
            }
            unset($task);
            return ['tasks' => $tasks, 'elapsed' => $elapsed, 'specialists' => $specialists, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'portal_url' => $portalUrl, 'task_uf_catalog' => self::getTaskUfCatalog($pdo)];
        });

        // Каталог пользовательских полей задач (для фильтра по полям и подписей)
        $this->router->get('/task-uf-catalog', function (array $payload): array {
            $pdo = Database::getConnection();
            return ['items' => self::getTaskUfCatalog($pdo)];
        });
        $this->router->patch('/task-uf-catalog', function (array $payload): array {
            $pdo = Database::getConnection();
            $fieldCode = trim((string) ($payload['field_code'] ?? ''));
            $label = isset($payload['label']) ? trim((string) $payload['label']) : null;
            if ($fieldCode === '') {
                return ['error' => 'field_code required'];
            }
            $stmt = $pdo->prepare('UPDATE bitrix24_task_uf_catalog SET label = ? WHERE field_code = ?');
            $stmt->execute([$label !== '' ? $label : null, $fieldCode]);
            if ($stmt->rowCount() === 0) {
                return ['error' => 'field not found in catalog', 'field_code' => $fieldCode];
            }
            return ['field_code' => $fieldCode, 'label' => $label !== '' ? $label : null];
        });

        // GET task-plan: исходный план и переплан по задаче (query: bitrix24_task_id)
        $this->router->get('/task-plan', function (array $payload): array {
            $pdo = Database::getConnection();
            $taskId = trim((string) ($payload['bitrix24_task_id'] ?? ''));
            if ($taskId === '') {
                return ['error' => 'bitrix24_task_id required'];
            }
            $task = $pdo->prepare("SELECT bitrix24_task_id, title, responsible_user_id, deadline, time_estimate, time_spent, start_date_plan, end_date_plan, created_date FROM bitrix24_tasks_cache WHERE bitrix24_task_id = ?");
            $task->execute([$taskId]);
            $task = $task->fetch(\PDO::FETCH_ASSOC);
            if (!$task) {
                return ['error' => 'Task not found'];
            }
            $override = $pdo->prepare("SELECT plan_start_date, plan_end_date, original_plan_start, original_plan_end, original_time_estimate FROM task_plan_override WHERE bitrix24_task_id = ?");
            $override->execute([$taskId]);
            $override = $override->fetch(\PDO::FETCH_ASSOC);
            $daily = $pdo->prepare("SELECT plan_date, planned_hours FROM task_plan_daily WHERE bitrix24_task_id = ? ORDER BY plan_date");
            $daily->execute([$taskId]);
            $planHoursByDate = [];
            while ($row = $daily->fetch(\PDO::FETCH_ASSOC)) {
                $planHoursByDate[$row['plan_date']] = (float) $row['planned_hours'];
            }
            $original = [
                'plan_start' => ($override !== null ? $override['original_plan_start'] : null) ?? self::dateOnly($task['start_date_plan'] ?? null) ?? self::dateOnly($task['created_date'] ?? null),
                'plan_end' => ($override !== null ? $override['original_plan_end'] : null) ?? self::dateOnly($task['end_date_plan'] ?? null) ?? self::dateOnly($task['deadline'] ?? null),
                'time_estimate' => $override !== null ? (int) $override['original_time_estimate'] : (int) $task['time_estimate'],
            ];
            $replanned = [
                'plan_start' => $override['plan_start_date'] ?? $original['plan_start'],
                'plan_end' => $override['plan_end_date'] ?? $original['plan_end'],
                'plan_hours_by_date' => $planHoursByDate,
            ];
            return ['task' => $task, 'original' => $original, 'replanned' => $replanned, 'has_plan_override' => $override !== null];
        });

        // PUT task-plan: сохранение переплана (body: bitrix24_task_id, plan_start_date?, plan_end_date?, plan_hours_by_date?)
        $this->router->put('/task-plan', function (array $payload): array {
            $pdo = Database::getConnection();
            $taskId = trim((string) ($payload['bitrix24_task_id'] ?? ''));
            if ($taskId === '') {
                return ['error' => 'bitrix24_task_id required'];
            }
            $task = $pdo->prepare("SELECT bitrix24_task_id, time_estimate, start_date_plan, end_date_plan, created_date, deadline FROM bitrix24_tasks_cache WHERE bitrix24_task_id = ?");
            $task->execute([$taskId]);
            $task = $task->fetch(\PDO::FETCH_ASSOC);
            if (!$task) {
                return ['error' => 'Task not found'];
            }
            $planStart = self::dateOnly($payload['plan_start_date'] ?? null);
            $planEnd = self::dateOnly($payload['plan_end_date'] ?? null);
            $planHoursByDate = $payload['plan_hours_by_date'] ?? [];
            if (!is_array($planHoursByDate)) {
                $planHoursByDate = [];
            }

            $override = $pdo->prepare("SELECT original_plan_start, original_plan_end, original_time_estimate FROM task_plan_override WHERE bitrix24_task_id = ?");
            $override->execute([$taskId]);
            $override = $override->fetch(\PDO::FETCH_ASSOC);
            $isFirst = $override === null;
            if ($isFirst) {
                $originalStart = self::dateOnly($task['start_date_plan'] ?? null) ?? self::dateOnly($task['created_date'] ?? null);
                $originalEnd = self::dateOnly($task['end_date_plan'] ?? null) ?? self::dateOnly($task['deadline'] ?? null);
                $originalEst = (int) $task['time_estimate'];
            } else {
                $originalStart = $override['original_plan_start'];
                $originalEnd = $override['original_plan_end'];
                $originalEst = (int) $override['original_time_estimate'];
            }
            if ($planStart === null) {
                $planStart = $originalStart;
            }
            if ($planEnd === null) {
                $planEnd = $originalEnd;
            }

            $pdo->prepare("INSERT INTO task_plan_override (bitrix24_task_id, plan_start_date, plan_end_date, original_plan_start, original_plan_end, original_time_estimate) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE plan_start_date = VALUES(plan_start_date), plan_end_date = VALUES(plan_end_date)")
                ->execute([$taskId, $planStart, $planEnd, $originalStart, $originalEnd, $originalEst]);

            $pdo->prepare("DELETE FROM task_plan_daily WHERE bitrix24_task_id = ?")->execute([$taskId]);
            $ins = $pdo->prepare("INSERT INTO task_plan_daily (bitrix24_task_id, plan_date, planned_hours) VALUES (?, ?, ?)");
            foreach ($planHoursByDate as $date => $hours) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                    continue;
                }
                $h = (float) $hours;
                if ($h < 0) {
                    continue;
                }
                $ins->execute([$taskId, $date, $h]);
            }

            return ['success' => true, 'bitrix24_task_id' => $taskId];
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

        // Ручной запуск синхронизации: один чанк за запрос; фронт вызывает в цикле пока has_more. mode: full|tasks|users
        $this->router->post('/sync', function (array $payload): array {
            set_time_limit(60);
            $pdo = Database::getConnection();
            $service = new SyncService($pdo);
            $mode = trim((string) ($payload['mode'] ?? 'full'));
            if (!in_array($mode, [SyncService::MODE_FULL, SyncService::MODE_GROUPS, SyncService::MODE_TASKS, SyncService::MODE_USERS], true)) {
                $mode = SyncService::MODE_FULL;
            }
            $result = $service->runChunk($mode);
            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'tasks_count' => $result['tasks_count'] ?? 0,
                'users_count' => $result['users_count'] ?? 0,
                'elapsed_count' => $result['elapsed_count'] ?? 0,
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

    /** @return list<array{field_code: string, label: string|null}> */
    private static function getTaskUfCatalog(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT field_code, label FROM bitrix24_task_uf_catalog ORDER BY field_code');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Суббота (6) и воскресенье (0) не участвуют в планировании. */
    private static function isWeekend(\DateTimeImmutable $d): bool
    {
        $w = (int) $d->format('w');
        return $w === 0 || $w === 6;
    }

    /**
     * Распределение планируемых трудозатрат по рабочим дням (пн–пт): план_начало = start_date_plan или created_date,
     * план_окончание = end_date_plan или deadline; время равномерно по рабочим дням, выходные не учитываются.
     *
     * @return array<string, float> дата Y-m-d => часы
     */
    private static function computePlanHoursByDate(array $task, string $periodFrom, string $periodTo): array
    {
        $planStart = self::dateOnly($task['start_date_plan'] ?? null) ?? self::dateOnly($task['created_date'] ?? null);
        $planEnd = self::dateOnly($task['end_date_plan'] ?? null) ?? self::dateOnly($task['deadline'] ?? null);
        if ($planStart === null || $planEnd === null) {
            return [];
        }
        $estimate = (int) ($task['time_estimate'] ?? 0);
        if ($estimate <= 0) {
            return [];
        }
        // В БД время в минутах
        $totalHours = $estimate / 60.0;
        $start = new \DateTimeImmutable($planStart);
        $end = new \DateTimeImmutable($planEnd);
        if ($start > $end) {
            $start = $end;
            $end = new \DateTimeImmutable($planStart);
        }
        $workingDays = [];
        $cursor = $start;
        while ($cursor <= $end) {
            if (!self::isWeekend($cursor)) {
                $workingDays[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        $workingDaysCount = count($workingDays);
        if ($workingDaysCount === 0) {
            return [];
        }
        $hoursPerDay = $totalHours / $workingDaysCount;
        $periodStart = new \DateTimeImmutable($periodFrom);
        $periodEnd = new \DateTimeImmutable($periodTo);
        $result = [];
        foreach ($workingDays as $dayStr) {
            $d = new \DateTimeImmutable($dayStr);
            if ($d >= $periodStart && $d <= $periodEnd) {
                $result[$dayStr] = round($hoursPerDay, 3);
            }
        }
        return $result;
    }

    /**
     * Распределение плановых часов по рабочим дням (пн–пт) при заданных датах и оценке; выходные не участвуют.
     *
     * @return array<string, float> дата Y-m-d => часы
     */
    private static function computePlanHoursByDateWithRange(?string $planStart, ?string $planEnd, int $estimateMinutes, string $periodFrom, string $periodTo): array
    {
        if ($planStart === null || $planEnd === null || $estimateMinutes <= 0) {
            return [];
        }
        $totalHours = $estimateMinutes / 60.0;
        $start = new \DateTimeImmutable($planStart);
        $end = new \DateTimeImmutable($planEnd);
        if ($start > $end) {
            $start = new \DateTimeImmutable($planEnd);
            $end = new \DateTimeImmutable($planStart);
        }
        $workingDays = [];
        $cursor = $start;
        while ($cursor <= $end) {
            if (!self::isWeekend($cursor)) {
                $workingDays[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        $workingDaysCount = count($workingDays);
        if ($workingDaysCount === 0) {
            return [];
        }
        $hoursPerDay = $totalHours / $workingDaysCount;
        $periodStart = new \DateTimeImmutable($periodFrom);
        $periodEnd = new \DateTimeImmutable($periodTo);
        $result = [];
        foreach ($workingDays as $dayStr) {
            $d = new \DateTimeImmutable($dayStr);
            if ($d >= $periodStart && $d <= $periodEnd) {
                $result[$dayStr] = round($hoursPerDay, 3);
            }
        }
        return $result;
    }

    private static function dateOnly(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
