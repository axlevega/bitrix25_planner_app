<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Системные настройки интеграции: одна строка таблицы — одно значение (key-value).
 * Новые настройки добавляются вставкой строки с setting_key/setting_value без изменения схемы.
 */
final class IntegrationSettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && isset($row['setting_value']) ? (string) $row['setting_value'] : null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, string|null> key => value
     */
    public function getValues(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare('SELECT setting_key, setting_value FROM integration_settings WHERE setting_key IN (' . $placeholders . ')');
        $stmt->execute($keys);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = null;
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[$row['setting_key']] = isset($row['setting_value']) ? (string) $row['setting_value'] : null;
        }
        return $out;
    }

    public function setValue(string $key, ?string $value): void
    {
        $value = $value ?? '';
        $stmt = $this->pdo->prepare('INSERT INTO integration_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
    }
}
