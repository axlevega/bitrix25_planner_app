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

    /** Системные UF_ поля B24 — не сохраняем в каталог и не пишем значения (вложения, почта, CRM и т.д.). */
    private const SYSTEM_UF_FIELDS = ['UF_TASK_WEBDAV_FILES', 'UF_MAIL_MESSAGE', 'UF_CRM_TASK'];

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
        $taskSelect = ['ID', 'TITLE', 'RESPONSIBLE_ID', 'DEADLINE', 'TIME_ESTIMATE', 'TIME_SPENT', 'DURATION_FACT', 'TIME_SPENT_IN_LOGS', 'STATUS', 'GROUP_ID', 'START_DATE_PLAN', 'END_DATE_PLAN', 'CREATED_DATE'];
        // Пользовательские поля: каталог обновляем только при первой странице; в select добавляем при каждой странице, иначе задачи со 2+ страницы приходят без UF и не попадают в bitrix24_task_custom_field.
        if ($phase === 0) {
            $ufRaw = $client->getTaskUserFieldListRaw();
            if (empty($ufRaw['error']) && !empty($ufRaw['items'])) {
                if ($tasksOffset === 0) {
                    $this->refreshTaskUfCatalog($ufRaw['items']);
                }
                $names = [];
                foreach ($ufRaw['items'] as $item) {
                    $name = $item['FIELD_NAME'] ?? $item['fieldName'] ?? null;
                    if ($name !== null && $name !== '' && (str_starts_with((string) $name, 'UF_') || str_starts_with((string) $name, 'uf_'))) {
                        $names[] = (string) $name;
                    }
                }
                if ($names !== []) {
                    $taskSelect = array_merge($taskSelect, $names);
                }
            }
        }
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
                $filter['RESPONSIBLE_ID'] = $responsibleIds;
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
            [$dateFrom, $dateTo] = $this->getSyncDateRange();
            $taskIds = $this->getTaskIdsForElapsedSync($elapsedOffset, self::ELAPSED_TASKS_PER_CHUNK, $dateFrom, $dateTo);
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
        $allowedUf = $this->getAllowedTaskUfFieldCodes();
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
            // В БД храним минуты; в Bitrix24 REST API TIME_ESTIMATE приходит в секундах — конвертируем
            $timeEst = $t['timeEstimate'] ?? $t['TIME_ESTIMATE'] ?? null;
            if ($timeEst !== null) {
                $timeEst = (int) round((int) $timeEst / 60);
            }
            // B24: TIME_SPENT не всегда в ответе; DURATION_FACT — минуты, TIME_SPENT_IN_LOGS — секунды
            $timeSpent = $t['timeSpent'] ?? $t['TIME_SPENT'] ?? $t['durationFact'] ?? $t['DURATION_FACT'] ?? null;
            if ($timeSpent === null) {
                $logs = $t['timeSpentInLogs'] ?? $t['TIME_SPENT_IN_LOGS'] ?? null;
                $timeSpent = $logs !== null ? (int) round((int) $logs / 60) : null;
            }
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
            $this->upsertTaskCustomFields((string) $id, $t, $syncedAt, $allowedUf);
        }
    }

    /**
     * Обновить каталог пользовательских полей (без системных); только поля из каталога пишутся в bitrix24_task_custom_field.
     * @param list<array> $items элементы из task.item.userfield.getlist
     */
    private function refreshTaskUfCatalog(array $items): void
    {
        $syncedAt = date('Y-m-d H:i:s');
        $existingLabels = [];
        try {
            $rows = $this->pdo->query('SELECT field_code, label FROM bitrix24_task_uf_catalog')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $l = trim((string) ($r['label'] ?? ''));
                if ($l !== '') {
                    $existingLabels[$r['field_code']] = $r['label'];
                }
            }
        } catch (\Throwable $e) {
            // таблица может отсутствовать до миграции 013
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_task_uf_catalog (field_code, label, synced_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), synced_at = VALUES(synced_at)'
        );
        foreach ($items as $item) {
            $fieldName = $item['FIELD_NAME'] ?? $item['fieldName'] ?? null;
            if ($fieldName === null || $fieldName === '') {
                continue;
            }
            $fieldName = (string) $fieldName;
            if (strtoupper($fieldName) !== $fieldName || strpos($fieldName, 'UF_') !== 0) {
                continue;
            }
            if (in_array($fieldName, self::SYSTEM_UF_FIELDS, true)) {
                continue;
            }
            $code = $this->fieldNameToCamelCase($fieldName);
            $label = null;
            if (isset($item['LIST_COLUMN_LABEL']) && is_array($item['LIST_COLUMN_LABEL'])) {
                $label = $item['LIST_COLUMN_LABEL']['ru'] ?? $item['LIST_COLUMN_LABEL']['en'] ?? reset($item['LIST_COLUMN_LABEL']) ?: null;
            } elseif (isset($item['LIST_COLUMN_LABEL']) && is_string($item['LIST_COLUMN_LABEL'])) {
                $label = $item['LIST_COLUMN_LABEL'];
            }
            $label = $label !== null ? trim((string) $label) : '';
            if ($label === '' && isset($existingLabels[$code])) {
                $label = $existingLabels[$code];
            }
            $stmt->execute([$code, $label !== '' ? $label : null, $syncedAt]);
        }
    }

    /** Преобразовать FIELD_NAME (UF_XXX_YYY) в camelCase, как в ответе tasks.task.list. */
    private function fieldNameToCamelCase(string $fieldName): string
    {
        $parts = array_map('strtolower', explode('_', $fieldName));
        if ($parts === []) {
            return $fieldName;
        }
        $parts = array_map('ucfirst', $parts);
        return lcfirst(implode('', $parts));
    }

    /**
     * Сохранить в bitrix24_task_custom_field только пользовательские поля из каталога (без системных).
     * @param array<string, true> $allowedUf field_code => true
     */
    private function upsertTaskCustomFields(string $bitrix24TaskId, array $taskData, string $syncedAt, array $allowedUf): void
    {
        if ($allowedUf === []) {
            return;
        }
        $deleteStmt = $this->pdo->prepare('DELETE FROM bitrix24_task_custom_field WHERE bitrix24_task_id = ?');
        $deleteStmt->execute([$bitrix24TaskId]);
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO bitrix24_task_custom_field (bitrix24_task_id, field_code, value_text, synced_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($taskData as $key => $value) {
            $keyStr = (string) $key;
            if (strlen($keyStr) < 2 || strtoupper(substr($keyStr, 0, 2)) !== 'UF') {
                continue;
            }
            if (!isset($allowedUf[$keyStr])) {
                continue;
            }
            if ($value === null || $value === '') {
                $valueStr = '';
            } elseif (is_bool($value)) {
                $valueStr = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $valueStr = json_encode($value);
            } else {
                $valueStr = (string) $value;
            }
            $insertStmt->execute([$bitrix24TaskId, $keyStr, $valueStr, $syncedAt]);
        }
    }

    /** @return array<string, true> field_code => true для полей из каталога */
    private function getAllowedTaskUfFieldCodes(): array
    {
        $rows = $this->pdo->query('SELECT field_code FROM bitrix24_task_uf_catalog')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $code) {
            $out[(string) $code] = true;
        }
        return $out;
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

    /**
     * ID задач для запроса учёта времени — только задачи в выбранном диапазоне (по created_date).
     * @param string|null $dateTo Y-m-d или null (без верхней границы)
     * @return list<string> bitrix24_task_id
     */
    private function getTaskIdsForElapsedSync(int $offset, int $limit, string $dateFrom, ?string $dateTo): array
    {
        $sql = 'SELECT bitrix24_task_id FROM bitrix24_tasks_cache WHERE created_date >= ?';
        $params = [$dateFrom . ' 00:00:00'];
        if ($dateTo !== null && $dateTo !== '') {
            $sql .= ' AND created_date <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql .= ' ORDER BY id LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
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
