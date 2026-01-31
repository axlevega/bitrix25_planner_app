<?php
declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('App\\', __DIR__ . '/src');

(static function (): void {
    $load = function (string $path): void {
        if (!is_readable($path)) {
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
    $root = dirname(__DIR__, 2);
    $load($root . '/.env');
    $load(__DIR__ . '/.env');
    $load(__DIR__ . '/.env.local');
})();
