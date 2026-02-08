<?php
/**
 * CRON: запуск синхронизации по интервалу из настроек (sync_interval_minutes).
 * Используется инкрементальный режим (только изменённые с прошлой синхронизации).
 * Запускать из cron каждые 1-5 минут (пример: раз в 5 мин — см. README).
 */
declare(strict_types=1);

$backendDir = dirname(__DIR__);
chdir($backendDir);

if (!is_file($backendDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "cron_sync: vendor/autoload.php not found in {$backendDir}. Run: composer install\n");
    exit(1);
}

require $backendDir . '/bootstrap.php';

use App\Database;
use App\Repository\IntegrationSettingsRepository;
use App\Service\SyncService;

set_time_limit(0);

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "cron_sync: DB connection failed: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}

$repo = new IntegrationSettingsRepository($pdo);
$intervalMinutes = (int) ($repo->getValue('sync_interval_minutes') ?: 30);
$lastSyncAt = $repo->getValue('last_sync_at');
$run = false;
if ($lastSyncAt === null || $lastSyncAt === '') {
    $run = true;
} else {
    $lastTs = strtotime($lastSyncAt);
    if ($lastTs !== false && time() - $lastTs >= $intervalMinutes * 60) {
        $run = true;
    }
}

if (!$run) {
    $lastTs = strtotime($lastSyncAt ?? '');
    $nextIn = $lastTs !== false ? max(0, $intervalMinutes * 60 - (time() - $lastTs)) : 0;
    echo "skip: next sync in {$nextIn}s\n";
    exit(0);
}

$logPath = $backendDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'sync.log';
$logDir = dirname($logPath);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$appendLog = static function (string $line) use ($logPath): void {
    $entry = date('Y-m-d H:i:s') . ' | ' . $line . "\n";
    @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
};
$appendLog('cron | started');

try {
    $service = new SyncService($pdo);
    $incremental = true;
    $totalTasks = 0;
    $totalUsers = 0;
    $totalElapsed = 0;

    do {
        $result = $service->runChunk(SyncService::MODE_FULL, $incremental);
        $totalTasks += $result['tasks_count'] ?? 0;
        $totalUsers += $result['users_count'] ?? 0;
        $totalElapsed += $result['elapsed_count'] ?? 0;
        if (!($result['success'] ?? false)) {
            $appendLog('cron | error | ' . ($result['message'] ?? 'unknown'));
            fwrite(STDERR, "sync error: " . ($result['message'] ?? 'unknown') . "\n");
            exit(1);
        }
    } while ($result['has_more'] ?? false);

    $appendLog('cron | completed | tasks=' . $totalTasks . ' users=' . $totalUsers . ' elapsed=' . $totalElapsed);
    echo "ok: tasks={$totalTasks} users={$totalUsers} elapsed={$totalElapsed}\n";
} catch (Throwable $e) {
    $appendLog('cron | exception | ' . $e->getMessage());
    fwrite(STDERR, "cron_sync: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
