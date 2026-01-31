<?php
declare(strict_types=1);

namespace App;

use App\Router;

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
        // Дальнейшие маршруты добавляются в фазах 1–2
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
