<?php
declare(strict_types=1);

$loader = require __DIR__ . '/vendor/autoload.php';
$loader->addPsr4('App\\', __DIR__ . '/src');

(static function (): void {
    $root = dirname(__DIR__, 2);
    $backendDir = __DIR__;
    $paths = [
        'root' => $root . '/.env',
        'backend' => $backendDir . '/.env',
        'backend_local' => $backendDir . '/.env.local',
    ];
    $_ENV['_ENV_DEBUG_PATHS'] = $paths;
    $_ENV['_ENV_DEBUG_FOUND'] = [];
    $load = function (string $path): void {
        $found = is_readable($path);
        $_ENV['_ENV_DEBUG_FOUND'][$path] = $found;
        if (!$found) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\"'");
                if ($name !== '') {
                    putenv("$name=$value");
                    $_ENV[$name] = $value;
                }
            }
        }
    };
    $load($paths['root']);
    $load($paths['backend']);
    $load($paths['backend_local']);
})();
