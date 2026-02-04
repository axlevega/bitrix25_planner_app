<?php
declare(strict_types=1);

namespace App\Service;

use App\Bitrix24\Client;
use App\Repository\IntegrationSettingsRepository;
use PDO;

/**
 * Синхронизация задач, пользователей и учёта времени по задачам из Bitrix24.
 * Постранично: задачи → пользователи → elapsed по задачам (task.elapseditem.getlist).
 * phase: 0=tasks, 1=users, 2=elapsed, 3=idle.
 * Режимы: full (всё подряд), tasks (только задачи + elapsed), users (только пользователи).
 */
final class SyncService
{
    private const TASKS_DAYS_BACK_DEFAULT = 365;
    private const PAGE_SIZE = 50;
    private const ELAPSED_TASKS_PER_CHUNK = 10;

    public const MODE_FULL = 'full';
    public const MODE_TASKS = 'tasks';
    public const MODE_USERS = 'users';

    private PDO $pdo;
    private IntegrationSettingsRepository $settings;
    private ?string $webhookUrl = null;

    public function __construct(PDO $pdo, ?string $webhookUrl = null)
    {
        $this->pdo = $pdo;
        $this->settings = new IntegrationSettingsRepository($pdo);
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Один чанк синхронизации. Возвращает has_more, counts и т.д.
     * @param string $mode full|tasks|users — полная, только задачи+elapsed, только сотрудники
     */
    public function runChunk(string $mode = self::MODE_FULL): array
    {
        $url = $this->webhookUrl ?? $this->getWebhookFromSettings();
        if ($url === '' || $url === null) {
            return ['success' => false, 'message' => 'Webhook URL не задан', 'tasks_count' => 0, 'users_count' => 0, 'elapsed_count' => 0, 'has_more' => false];
        }

        $state = $this->getSyncState();
        $phase = (int) $state['sync_phase'];
        $tasksOffset = (int) $state['sync_tasks_offset'];
        $usersOffset = (int) $state['sync_users_offset'];
        $elapsedOffset = (int) ($state['sync_elapsed_task_offset'] ?? 0);

        // При выборе режима «только сотрудники»/«только задачи» — сбрасываем на нужную фазу, если сейчас не в ней (иначе при phase=0 запустились бы задачи вместо сотрудников).
        // В цикле (has_more) фронт шлёт тот же mode — не сбрасываем, продолжаем с текущей фазы.
        if ($mode === self::MODE_USERS && $phase !== 1) {
            $phase = 1;
            $tasksOffset = 0;
            $usersOffset = 0;
            $elapsedOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
        } elseif ($mode === self::MODE_TASKS && $phase !== 0 && $phase !== 2) {
            $phase = 0;
            $tasksOffset = 0;
            $usersOffset = 0;
            $elapsedOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
        } elseif ($mode === self::MODE_FULL && $phase === 3) {
            $phase = 0;
            $tasksOffset = 0;
            $usersOffset = 0;
            $elapsedOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
        }

        $client = new Client($url);
        $taskSelect = ['ID', 'TITLE', 'RESPONSIBLE_ID', 'DEADLINE', 'TIME_ESTIMATE', 'TIME_SPENT', 'STATUS', 'GROUP_ID', 'START_DATE_PLAN', 'END_DATE_PLAN', 'CREATED_DATE'];
        $userSelect = ['ID', 'NAME', 'EMAIL'];
        $tasksCount = 0;
        $usersCount = 0;

        if ($phase === 0) {
            [$dateFrom, $dateTo] = $this->getSyncDateRange();
            $filter = ['>=CREATED_DATE' => $dateFrom];
            if ($dateTo !== null) {
                $filter['<=CREATED_DATE'] = $dateTo;
            }
            $responsibleIds = $this->getSyncResponsibleIds();
            if ($responsibleIds !== []) {
                $filter['RESPONSIBLE_ID'] = implode(',', $responsibleIds);
            }
            $tasksResult = $client->callOnePage('tasks.task.list', 'tasks', $tasksOffset, self::PAGE_SIZE, $taskSelect, $filter);
            if (!empty($tasksResult['error'])) {
                return [
                    'success' => false,
                    'message' => $tasksResult['error_description'] ?? $tasksResult['error'],
                    'tasks_count' => 0,
                    'users_count' => 0,
                    'elapsed_count' => 0,
                    'has_more' => false,
                ];
            }
            $tasks = $tasksResult['data'] ?? [];
            if ($tasks === [] && $tasksOffset === 0) {
                $tasksResultV2 = $client->callOnePage('task.list', 'result', $tasksOffset, self::PAGE_SIZE, $taskSelect, $filter);
                if (empty($tasksResultV2['error']) && !empty($tasksResultV2['data'])) {
                    $tasks = $tasksResultV2['data'];
                }
            }
            $tasksCount = count($tasks);
            $this->upsertTasks($tasks);
            if ($tasksCount >= self::PAGE_SIZE) {
                $tasksOffset += $tasksCount;
            } else {
                $tasksOffset = 0;
                $phase = ($mode === self::MODE_TASKS) ? 2 : 1;
            }
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
            return [
                'success' => true,
                'message' => $phase === 2 ? 'Задачи загружены, далее учёт времени' : ($phase === 1 ? 'Задачи загружены, далее пользователи' : 'Чанк задач'),
                'tasks_count' => $tasksCount,
                'users_count' => 0,
                'elapsed_count' => 0,
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
                    'elapsed_count' => 0,
                    'has_more' => true,
                ];
            }
            $users = $usersResult['data'] ?? [];
            $usersCount = count($users);
            $this->upsertUsers($users);
            if ($usersCount >= self::PAGE_SIZE) {
                $usersOffset += $usersCount;
                $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
                return [
                    'success' => true,
                    'message' => 'Чанк пользователей',
                    'tasks_count' => 0,
                    'users_count' => $usersCount,
                    'elapsed_count' => 0,
                    'has_more' => true,
                ];
            }
            if ($mode === self::MODE_USERS) {
                $this->updateLastSyncAt(date('Y-m-d H:i:s'));
                $this->setSyncState(3, 0, 0, 0);
                return [
                    'success' => true,
                    'message' => 'Синхронизация сотрудников завершена',
                    'tasks_count' => 0,
                    'users_count' => $usersCount,
                    'elapsed_count' => 0,
                    'has_more' => false,
                ];
            }
            $phase = 2;
            $usersOffset = 0;
            $elapsedOffset = 0;
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
            // fall through to phase 2 (elapsed) in same run
        }

        if ($phase === 2) {
            $taskIds = $this->getTaskIdsForElapsedSync($elapsedOffset, self::ELAPSED_TASKS_PER_CHUNK);
            $elapsedCount = 0;
            $syncedAt = date('Y-m-d H:i:s');
            foreach ($taskIds as $taskId) {
                $itemsResult = $client->getTaskElapsedItems($taskId);
                if (!empty($itemsResult['error'])) {
                    continue;
                }
                $items = $itemsResult['data'] ?? [];
                $byKey = [];
                foreach ($items as $item) {
                    $userId = $item['USER_ID'] ?? $item['userId'] ?? null;
                    $minutes = isset($item['MINUTES']) ? (int) $item['MINUTES'] : (isset($item['SECONDS']) ? (int) floor((int) $item['SECONDS'] / 60) : 0);
                    $dateStr = $item['CREATED_DATE'] ?? $item['DATE_START'] ?? $item['createdDate'] ?? $item['dateStart'] ?? null;
                    $day = $this->parseDateToDay($dateStr);
                    if ($userId === null || $day === null || $minutes <= 0) {
                        continue;
                    }
                    $key = (string) $userId . '|' . $day;
                    $byKey[$key] = ($byKey[$key] ?? 0) + $minutes;
                }
                foreach ($byKey as $key => $mins) {
                    [$uid, $day] = explode('|', $key, 2);
                    $this->upsertTaskElapsed($taskId, $uid, $day, $mins, $syncedAt);
                    $elapsedCount++;
                }
                usleep(600000);
            }
            $elapsedOffset += count($taskIds);
            if (count($taskIds) < self::ELAPSED_TASKS_PER_CHUNK) {
                $phase = 3;
                $elapsedOffset = 0;
                $this->updateLastSyncAt($syncedAt);
                $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
                return [
                    'success' => true,
                    'message' => 'Синхронизация завершена (задачи, пользователи, учёт времени)',
                    'tasks_count' => 0,
                    'users_count' => 0,
                    'elapsed_count' => $elapsedCount,
                    'has_more' => false,
                ];
            }
            $this->setSyncState($phase, $tasksOffset, $usersOffset, $elapsedOffset);
            return [
                'success' => true,
                'message' => 'Чанк учёта времени по задачам',
                'tasks_count' => 0,
                'users_count' => 0,
                'elapsed_count' => $elapsedCount,
                'has_more' => true,
            ];
        }

        return ['success' => true, 'message' => 'OK', 'tasks_count' => 0, 'users_count' => 0, 'elapsed_count' => 0, 'has_more' => false];
    }

    private function getSyncState(): array
    {
        $v = $this->settings->getValues(['sync_phase', 'sync_tasks_offset', 'sync_users_offset', 'sync_elapsed_task_offset']);
        return [
            'sync_phase' => (int) ($v['sync_phase'] ?? 3),
            'sync_tasks_offset' => (int) ($v['sync_tasks_offset'] ?? 0),
            'sync_users_offset' => (int) ($v['sync_users_offset'] ?? 0),
            'sync_elapsed_task_offset' => (int) ($v['sync_elapsed_task_offset'] ?? 0),
        ];
    }

    private function setSyncState(int $phase, int $tasksOffset, int $usersOffset, int $elapsedOffset = 0): void
    {
        $this->settings->setValue('sync_phase', (string) $phase);
        $this->settings->setValue('sync_tasks_offset', (string) $tasksOffset);
        $this->settings->setValue('sync_users_offset', (string) $usersOffset);
        $this->settings->setValue('sync_elapsed_task_offset', (string) $elapsedOffset);
    }

    private function getWebhookFromSettings(): ?string
    {
        $v = $this->settings->getValues(['portal_url', 'webhook_token']);
        $token = trim((string) ($v['webhook_token'] ?? ''));
        $portal = trim((string) ($v['portal_url'] ?? ''));
        if ($token !== '' && $portal !== '') {
            return rtrim($portal, '/') . '/rest/' . trim($token, '/') . '/';
        }
        return getenv('BITRIX24_WEBHOOK_URL') ?: null;
    }

    /**
     * Диапазон дат для фильтра задач по CREATED_DATE из настроек.
     * @return array{0: string, 1: string|null} [dateFrom Y-m-d, dateTo Y-m-d или null]
     */
    private function getSyncDateRange(): array
    {
        $v = $this->settings->getValues(['sync_date_range_type', 'sync_date_from', 'sync_date_to']);
        $type = trim((string) ($v['sync_date_range_type'] ?? ''));
        $customFrom = isset($v['sync_date_from']) && $v['sync_date_from'] !== '' ? trim($v['sync_date_from']) : null;
        $customTo = isset($v['sync_date_to']) && $v['sync_date_to'] !== '' ? trim($v['sync_date_to']) : null;

        if ($type === 'custom' && $customFrom !== null && $customFrom !== '') {
            return [$customFrom, $customTo ?: $customFrom];
        }

        $today = date('Y-m-d');
        switch ($type) {
            case 'week':
                return [date('Y-m-d', strtotime('-7 days')), $today];
            case 'month':
                return [date('Y-m-d', strtotime('-1 month')), $today];
            case 'half_year':
                return [date('Y-m-d', strtotime('-6 months')), $today];
            case 'year':
                return [date('Y-m-d', strtotime('-1 year')), $today];
            default:
                $daysBack = (int) ($_ENV['TASKS_DAYS_BACK'] ?? getenv('TASKS_DAYS_BACK') ?: self::TASKS_DAYS_BACK_DEFAULT);
                if ($daysBack < 1) {
                    $daysBack = self::TASKS_DAYS_BACK_DEFAULT;
                }
                return [date('Y-m-d', strtotime('-' . $daysBack . ' days')), null];
        }
    }

    /**
     * Список bitrix24_user_id выбранных специалистов для фильтра задач (пустой = не фильтровать).
     * @return list<string>
     */
    private function getSyncResponsibleIds(): array
    {
        $raw = $this->settings->getValue('sync_specialist_ids');
        if ($raw === null || $raw === '') {
            return [];
        }
        $ids = json_decode($raw, true);
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT bitrix24_user_id FROM specialists WHERE id IN (' . $placeholders . ') AND bitrix24_user_id IS NOT NULL AND bitrix24_user_id != ""');
        $stmt->execute(array_map('intval', $ids));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
        $this->settings->setValue('last_sync_at', $at);
    }

    private function upsertTasks(array $tasks): void
    {
        $syncedAt = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_tasks_cache (bitrix24_task_id, title, responsible_user_id, deadline, time_estimate, time_spent, status, group_id, start_date_plan, end_date_plan, created_date, synced_at, raw_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), responsible_user_id = VALUES(responsible_user_id), deadline = VALUES(deadline),
             time_estimate = VALUES(time_estimate), time_spent = VALUES(time_spent), status = VALUES(status), group_id = VALUES(group_id),
             start_date_plan = VALUES(start_date_plan), end_date_plan = VALUES(end_date_plan), created_date = VALUES(created_date), synced_at = VALUES(synced_at), raw_json = VALUES(raw_json)'
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
            $startDatePlan = $this->parseDate($t['startDatePlan'] ?? $t['START_DATE_PLAN'] ?? null);
            $endDatePlan = $this->parseDate($t['endDatePlan'] ?? $t['END_DATE_PLAN'] ?? null);
            $createdDate = $this->parseDate($t['createdDate'] ?? $t['CREATED_DATE'] ?? null);
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
                $startDatePlan,
                $endDatePlan,
                $createdDate,
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

    /** @return string Y-m-d или null */
    private function parseDateToDay(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = is_numeric($v) ? (int) $v : strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** @return list<string> bitrix24_task_id */
    private function getTaskIdsForElapsedSync(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT bitrix24_task_id FROM bitrix24_tasks_cache ORDER BY id LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function upsertTaskElapsed(string $taskId, string $userId, string $elapsedDate, int $minutes, string $syncedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_task_elapsed (bitrix24_task_id, bitrix24_user_id, elapsed_date, minutes, synced_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE minutes = VALUES(minutes), synced_at = VALUES(synced_at)'
        );
        $stmt->execute([$taskId, $userId, $elapsedDate, $minutes, $syncedAt]);
    }
}
