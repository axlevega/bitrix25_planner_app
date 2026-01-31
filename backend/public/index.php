<?php
/**
 * Точка входа API. Все запросы к /api/* направляются сюда (через .htaccess или веб-сервер).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$app = new \App\Application();
$app->run();
