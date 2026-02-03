<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    /** На хостинге getenv() часто не видит переменные из .env — читаем из $_ENV. */
    private static function env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return $v !== false && $v !== null ? (string) $v : $default;
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::env('DB_HOST', 'localhost'),
                self::env('DB_PORT', '3306'),
                self::env('DB_NAME', 'bitrix25_planner')
            );
            self::$pdo = new PDO(
                $dsn,
                self::env('DB_USER'),
                self::env('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }
}
