<?php
declare(strict_types=1);

namespace App\Service;

use App\Bitrix24\Client;
use App\Database;
use PDO;

/**
 * Синхронизация задач и пользователей из Bitrix24 в кэш-таблицы.
 * Постранично: один запрос POST /sync = один чанк (до 50 задач или до 50 пользователей).
 * Задачи ограничены последними 30 днями (CREATED_DATE).
 */
final class SyncService
{
    private const TASKS_DAYS_BACK = 30;
    private const PAGE_SIZE = 50;

    private PDO $pdo;
    private ?string $webhookUrl = null;

    public function __construct(PDO $pdo, ?string $webhookUrl = null)
    {
        $this->pdo = $pdo;
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Один чанк синхронизации. Возвращает has_more, counts и т.д.
     */
    public function runChunk(): array
    {
        $url = $this->webhookUrl ?? $this->getWebhookFromSettings();
        if ($url === '' || $url === null) {
            return ['success' => false, 'message' => 'Webhook URL не задан', 'tasks_count' => 0, 'users_count' => 0, 'has_more' => false];
        }

        $state = $this->getSyncState();
        $phase = (int) $state['sync_phase'];
        $tasksOffset = (int) $state['sync_tasks_offset'];
        $usersOffset = (int) $state['sync_users_offset'];

        // Новый цикл синхронизации
        if ($phase === 2) {
            $phase = 0;
            $tasksOffset = 0;
            $usersOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset);
        }

        $client = new Client($url);
        $taskSelect = ['ID', 'TITLE', 'RESPONSIBLE_ID', 'DEADLINE', 'TIME_ESTIMATE', 'TIME_SPENT', 'STATUS', 'GROUP_ID'];
        $userSelect = ['ID', 'NAME', 'EMAIL'];
        $tasksCount = 0;
        $usersCount = 0;

        if ($phase === 0) {
            $filter = ['>=CREATED_DATE' => date('Y-m-d', strtotime('-' . self::TASKS_DAYS_BACK . ' days'))];
            $tasksResult = $client->callOnePage('tasks.task.list', 'tasks', $tasksOffset, self::PAGE_SIZE, $taskSelect, $filter);
            if (!empty($tasksResult['error'])) {
                return [
                    'success' => false,
                    'message' => $tasksResult['error_description'] ?? $tasksResult['error'],
                    'tasks_count' => 0,
                    'users_count' => 0,
                    'has_more' => false,
                ];
            }
            $tasks = $tasksResult['data'] ?? [];
            $tasksCount = count($tasks);
            $this->upsertTasks($tasks);
            if ($tasksCount >= self::PAGE_SIZE) {
                $tasksOffset += $tasksCount;
            } else {
                $phase = 1;
                $tasksOffset = 0;
            }
            $this->setSyncState($phase, $tasksOffset, $usersOffset);
            return [
                'success' => true,
                'message' => $phase === 1 ? 'Задачи загружены, далее пользователи' : 'Чанк задач',
                'tasks_count' => $tasksCount,
                'users_count' => 0,
                'has_more' => true,
            ];
        }

        if ($phase === 1) {
            $usersResult = $client->callOnePage('user.get', 'result', $usersOffset, self::PAGE_SIZE, $userSelect, []);
            if (!empty($usersResult['error'])) {
                return [
                    'success' => false,
                    'message' => $usersResult['error_description'] ?? $usersResult['error'],
                    'tasks_count' => 0,
                    'users_count' => 0,
                    'has_more' => true,
                ];
            }
            $users = $usersResult['data'] ?? [];
            $usersCount = count($users);
            $this->upsertUsers($users);
            if ($usersCount >= self::PAGE_SIZE) {
                $usersOffset += $usersCount;
                $this->setSyncState($phase, $tasksOffset, $usersOffset);
                return [
                    'success' => true,
                    'message' => 'Чанк пользователей',
                    'tasks_count' => 0,
                    'users_count' => $usersCount,
                    'has_more' => true,
                ];
            }
            $phase = 2;
            $usersOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset);
            $this->updateLastSyncAt(date('Y-m-d H:i:s'));
            return [
                'success' => true,
                'message' => 'Синхронизация завершена',
                'tasks_count' => 0,
                'users_count' => $usersCount,
                'has_more' => false,
            ];
        }

        return ['success' => true, 'message' => 'OK', 'tasks_count' => 0, 'users_count' => 0, 'has_more' => false];
    }

    private function getSyncState(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT sync_phase, sync_tasks_offset, sync_users_offset FROM integration_settings LIMIT 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['sync_phase' => 2, 'sync_tasks_offset' => 0, 'sync_users_offset' => 0];
        }
        if (!$row) {
            return ['sync_phase' => 2, 'sync_tasks_offset' => 0, 'sync_users_offset' => 0];
        }
        return [
            'sync_phase' => (int) ($row['sync_phase'] ?? 2),
            'sync_tasks_offset' => (int) ($row['sync_tasks_offset'] ?? 0),
            'sync_users_offset' => (int) ($row['sync_users_offset'] ?? 0),
        ];
    }

    private function setSyncState(int $phase, int $tasksOffset, int $usersOffset): void
    {
        try {
            $stmt = $this->pdo->query('SELECT id FROM integration_settings LIMIT 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->pdo->prepare('UPDATE integration_settings SET sync_phase = ?, sync_tasks_offset = ?, sync_users_offset = ? WHERE id = ?')
                    ->execute([$phase, $tasksOffset, $usersOffset, $row['id']]);
            }
        } catch (\Throwable $e) {
            // колонки могут отсутствовать до применения миграции 002
        }
    }

    private function getWebhookFromSettings(): ?string
    {
        $stmt = $this->pdo->query('SELECT webhook_token, portal_url FROM integration_settings ORDER BY id LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return getenv('BITRIX24_WEBHOOK_URL') ?: null;
        }
        $token = trim((string) ($row['webhook_token'] ?? ''));
        $portal = trim((string) ($row['portal_url'] ?? ''));
        if ($token !== '' && $portal !== '') {
            $base = rtrim($portal, '/');
            $path = trim($token, '/');
            return $base . '/rest/' . $path . '/';
        }
        return getenv('BITRIX24_WEBHOOK_URL') ?: null;
    }

    private function insertSyncLog(string $startedAt, string $status, ?string $message, ?int $tasksCount): int
    {
        $this->pdo->prepare('INSERT INTO sync_log (started_at, status, message, tasks_count) VALUES (?, ?, ?, ?)')
            ->execute([$startedAt, $status, $message, $tasksCount]);
        return (int) $this->pdo->lastInsertId();
    }

    private function updateSyncLog(
        int $id,
        string $startedAt,
        string $status,
        ?string $message,
        ?int $tasksCount = null,
        ?string $finishedAt = null
    ): void {
        $sql = 'UPDATE sync_log SET finished_at = ?, status = ?, message = COALESCE(?, message)';
        $params = [$finishedAt ?? $startedAt, $status, $message];
        if ($tasksCount !== null) {
            $sql .= ', tasks_count = ?';
            $params[] = $tasksCount;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $this->pdo->prepare($sql)->execute($params);
    }

    private function updateLastSyncAt(string $at): void
    {
        $stmt = $this->pdo->query('SELECT id FROM integration_settings LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->pdo->prepare('UPDATE integration_settings SET last_sync_at = ? WHERE id = ?')->execute([$at, $row['id']]);
        }
    }

    private function upsertTasks(array $tasks): void
    {
        $syncedAt = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_tasks_cache (bitrix24_task_id, title, responsible_user_id, deadline, time_estimate, time_spent, status, group_id, synced_at, raw_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), responsible_user_id = VALUES(responsible_user_id), deadline = VALUES(deadline),
             time_estimate = VALUES(time_estimate), time_spent = VALUES(time_spent), status = VALUES(status), group_id = VALUES(group_id), synced_at = VALUES(synced_at), raw_json = VALUES(raw_json)'
        );

        foreach ($tasks as $t) {
            $id = $t['id'] ?? $t['ID'] ?? null;
            if ($id === null) {
                continue;
            }
            $title = $t['title'] ?? $t['TITLE'] ?? null;
            $responsible = $t['responsibleId'] ?? $t['RESPONSIBLE_ID'] ?? null;
            $deadline = $this->parseDate($t['deadline'] ?? $t['DEADLINE'] ?? null);
            $timeEst = $t['timeEstimate'] ?? $t['TIME_ESTIMATE'] ?? null;
            $timeSpent = $t['timeSpent'] ?? $t['TIME_SPENT'] ?? null;
            $status = $t['status'] ?? $t['STATUS'] ?? null;
            $groupId = $t['groupId'] ?? $t['GROUP_ID'] ?? null;
            $rawJson = json_encode($t);
            $stmt->execute([
                (string) $id,
                $title,
                $responsible !== null ? (string) $responsible : null,
                $deadline,
                $timeEst !== null ? (int) $timeEst : null,
                $timeSpent !== null ? (int) $timeSpent : null,
                $status !== null ? (string) $status : null,
                $groupId !== null ? (string) $groupId : null,
                $syncedAt,
                $rawJson,
            ]);
        }
    }

    private function upsertUsers(array $users): void
    {
        $syncedAt = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_users_cache (bitrix24_user_id, name, email, synced_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), synced_at = VALUES(synced_at)'
        );

        foreach ($users as $u) {
            $id = $u['ID'] ?? $u['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $name = $u['NAME'] ?? $u['name'] ?? null;
            $email = $u['EMAIL'] ?? $u['email'] ?? null;
            $stmt->execute([
                (string) $id,
                $name,
                $email,
                $syncedAt,
            ]);
        }
    }

    private function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = is_numeric($v) ? (int) $v : strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
