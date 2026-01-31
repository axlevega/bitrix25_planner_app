<?php
declare(strict_types=1);

namespace App;

use App\Router;
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
