<?php
declare(strict_types=1);

namespace App\Bitrix24;

use function sleep;

/**
 * Клиент Bitrix24 REST API по URL вебхука.
 * Пагинация (start), повтор при ошибках, учёт лимитов.
 */
final class Client
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_SECONDS = 2;
    private const RATE_LIMIT_DELAY_MS = 600;

    private string $webhookBase;

    public function __construct(string $webhookBase)
    {
        $this->webhookBase = rtrim($webhookBase, '/') . '/';
    }

    /**
     * Один вызов метода REST API.
     *
     * @return array{result?: mixed, total?: int, next?: int, error_description?: string}
     */
    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookBase . $method;
        $attempt = 0;

        while (true) {
            $attempt++;
            $query = http_build_query($params);
            $fullUrl = $query !== '' ? $url . '?' . $query : $url;

            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($fullUrl, false, $ctx);

            if ($raw === false) {
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY_SECONDS);
                    continue;
                }
                return ['error' => 'request_failed', 'error_description' => 'HTTP request failed'];
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return ['error' => 'invalid_json', 'error_description' => $raw ?: 'Empty response'];
            }

            if (!empty($data['error'])) {
                if (($data['error'] === 'QUERY_LIMIT_EXCEEDED' || ($data['error_description'] ?? '') === 'Rate limit exceeded') && $attempt < self::MAX_RETRIES) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                    continue;
                }
                return $data;
            }

            return $data;
        }
    }

    /**
     * Вызов с пагинацией: собирает элементы по pageSize.
     * Если задан maxItems > 0 — останавливаемся после сбора maxItems элементов (для теста).
     *
     * @param string $resultKey ключ в result с массивом (например tasks или result)
     * @param int|null $maxItems лимит для теста (null = без лимита)
     */
    public function callWithPagination(string $method, string $resultKey, array $select = [], int $pageSize = 50, ?int $maxItems = null): array
    {
        $all = [];
        $start = 0;

        do {
            $params = ['start' => $start];
            if ($select !== []) {
                $params['select'] = $select;
            }
            $response = $this->call($method, $params);

            if (!empty($response['error'])) {
                return ['error' => $response['error'], 'error_description' => $response['error_description'] ?? '', 'data' => $all];
            }

            $result = $response['result'] ?? null;
            $list = null;
            if (is_array($result) && isset($result[$resultKey])) {
                $list = $result[$resultKey];
            } elseif (is_array($result) && array_is_list($result)) {
                $list = $result;
            }
            if (!is_array($list)) {
                $list = [];
            }

            foreach ($list as $item) {
                $all[] = $item;
                if ($maxItems !== null && count($all) >= $maxItems) {
                    break 2;
                }
            }

            $total = $response['total'] ?? (isset($result['total']) ? (int) $result['total'] : null);
            $start += $pageSize;

            if (count($list) < $pageSize) {
                break;
            }
            if ($total !== null && $start >= $total) {
                break;
            }
            if ($maxItems !== null && count($all) >= $maxItems) {
                break;
            }
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        } while (true);

        return ['data' => $all, 'total' => count($all)];
    }

    /**
     * Один запрос — одна страница (для постраничной синхронизации).
     *
     * @param array<string, string|array<int, string>> $filter фильтр Bitrix24; для нескольких значений — массив, например ['RESPONSIBLE_ID' => ['1','2']]
     */
    public function callOnePage(string $method, string $resultKey, int $start, int $pageSize = 50, array $select = [], array $filter = []): array
    {
        $params = ['start' => $start];
        if ($select !== []) {
            $params['select'] = $select;
        }
        foreach ($filter as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $idx => $item) {
                    $params['filter[' . $k . '][' . $idx . ']'] = $item;
                }
            } else {
                $params['filter[' . $k . ']'] = $v;
            }
        }
        $response = $this->call($method, $params);
        if (!empty($response['error'])) {
            return ['error' => $response['error'], 'error_description' => $response['error_description'] ?? '', 'data' => []];
        }
        $result = $response['result'] ?? null;
        $list = $this->extractListFromResult($result, $resultKey);
        return ['data' => $list, 'total' => count($list)];
    }

    /**
     * Извлечь массив из result: result.tasks, result.result, или result как массив.
     */
    private function extractListFromResult(mixed $result, string $resultKey): array
    {
        if (!is_array($result)) {
            return [];
        }
        if (isset($result[$resultKey]) && is_array($result[$resultKey])) {
            return $result[$resultKey];
        }
        if (array_is_list($result)) {
            return $result;
        }
        foreach (['tasks', 'result', 'data'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return $result[$key];
            }
        }
        return [];
    }

    /**
     * Учёт времени по задаче (REST v2: task.elapseditem.getlist).
     * Фильтр по TASK_ID, возвращает массив записей с USER_ID, MINUTES, CREATED_DATE и т.д.
     *
     * @return array{data?: array, error?: string, error_description?: string}
     */
    public function getTaskElapsedItems(string $taskId): array
    {
        $params = ['TASK_ID' => $taskId];
        $response = $this->call('task.elapseditem.getlist', $params);
        if (!empty($response['error'])) {
            return ['error' => $response['error'], 'error_description' => $response['error_description'] ?? '', 'data' => []];
        }
        $result = $response['result'] ?? null;
        $list = [];
        if (is_array($result)) {
            $list = isset($result['result']) ? $result['result'] : (array_is_list($result) ? $result : []);
        }
        return ['data' => is_array($list) ? $list : []];
    }

    /**
     * Список пользовательских полей задач (UF_*) для передачи в select tasks.task.list.
     * Метод: task.item.userfield.getlist.
     *
     * @return array{list: list<string>, error?: string, error_description?: string}
     */
    public function getTaskUserFieldList(): array
    {
        $raw = $this->getTaskUserFieldListRaw();
        if (isset($raw['error'])) {
            return ['list' => [], 'error' => $raw['error'], 'error_description' => $raw['error_description'] ?? ''];
        }
        $names = [];
        foreach ($raw['items'] as $item) {
            $name = $item['FIELD_NAME'] ?? $item['fieldName'] ?? null;
            if ($name !== null && $name !== '' && (str_starts_with((string) $name, 'UF_') || str_starts_with((string) $name, 'uf_'))) {
                $names[] = (string) $name;
            }
        }
        return ['list' => $names];
    }

    /**
     * Сырой ответ task.item.userfield.getlist — массив элементов с FIELD_NAME, LIST_COLUMN_LABEL и т.д.
     * Для построения каталога пользовательских полей (исключая системные).
     *
     * @return array{items: list<array>, error?: string, error_description?: string}
     */
    public function getTaskUserFieldListRaw(): array
    {
        $response = $this->call('task.item.userfield.getlist', []);
        if (!empty($response['error'])) {
            return ['items' => [], 'error' => $response['error'], 'error_description' => $response['error_description'] ?? ''];
        }
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return ['items' => []];
        }
        $list = isset($result['result']) ? $result['result'] : (array_is_list($result) ? $result : []);
        return ['items' => is_array($list) ? $list : []];
    }
}
