<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Расчёт нагрузки специалиста/отдела за период: план из task_plan_* и кэша задач, факт из учёта по дням (bitrix24_task_elapsed).
 */
final class LoadService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Нагрузка по специалисту за период: план (task_plan_daily/intervals/кэш) и факт (учёт по дням).
     */
    public function getSpecialistLoad(int $specialistId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, bitrix24_user_id, norm_hours_per_day, norm_hours_per_week, flight_hours_limit_per_day, flight_hours_limit_per_week FROM specialists WHERE id = ?');
        $stmt->execute([$specialistId]);
        $spec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$spec) {
            return ['error' => 'Specialist not found'];
        }

        $b24UserId = $spec['bitrix24_user_id'] ?? '';
        $hoursPlan = $b24UserId !== '' ? $this->getPlanHoursTotal($b24UserId, $dateFrom, $dateTo) : 0.0;
        $hoursPlanRegular = $hoursPlan;
        $hoursPlanFlight = 0.0;
        $hoursTasks = $b24UserId !== '' ? $this->getTaskElapsedHoursTotal($b24UserId, $dateFrom, $dateTo) : 0.0;
        $hoursTasksRegular = $hoursTasks;
        $hoursTasksFlight = 0.0;

        $hoursFlight = 0.0;
        $hoursRegular = round($hoursTasks, 2);
        $hoursTotal = round($hoursPlan + $hoursTasks, 2);

        $days = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);
        $weeks = $days / 7;
        $normPerWeek = $spec['norm_hours_per_week'] !== null ? (float) $spec['norm_hours_per_week'] : null;
        $normPerDay = $spec['norm_hours_per_day'] !== null ? (float) $spec['norm_hours_per_day'] : null;
        $normHours = $normPerWeek !== null ? round($normPerWeek * $weeks, 2) : ($normPerDay !== null ? round($normPerDay * $days, 2) : null);

        $flightLimit = null;
        $flightLimitExceeded = false;
        $perWeek = $spec['flight_hours_limit_per_week'] !== null ? (float) $spec['flight_hours_limit_per_week'] : null;
        $perDay = $spec['flight_hours_limit_per_day'] !== null ? (float) $spec['flight_hours_limit_per_day'] : null;
        if ($perWeek !== null) {
            $flightLimit = round($perWeek * $weeks, 2);
            $flightLimitExceeded = $hoursFlight > $flightLimit;
        } elseif ($perDay !== null) {
            $flightLimit = round($perDay * $days, 2);
            $flightLimitExceeded = $hoursFlight > $flightLimit;
        }

        $status = 'normal';
        if ($normHours !== null && $normHours > 0) {
            if ($hoursTotal > $normHours * 1.05) {
                $status = 'overload';
            } elseif ($hoursTotal < $normHours * 0.95) {
                $status = 'underload';
            }
        }

        return [
            'specialist_id' => $specialistId,
            'specialist_name' => $spec['name'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'hours_plan' => round($hoursPlan, 2),
            'hours_plan_regular' => round($hoursPlanRegular, 2),
            'hours_plan_flight' => round($hoursPlanFlight, 2),
            'hours_tasks' => round($hoursTasks, 2),
            'hours_tasks_regular' => round($hoursTasksRegular, 2),
            'hours_tasks_flight' => round($hoursTasksFlight, 2),
            'hours_regular' => $hoursRegular,
            'hours_flight' => $hoursFlight,
            'hours_total' => $hoursTotal,
            'norm_hours' => $normHours,
            'flight_limit' => $flightLimit,
            'flight_limit_exceeded' => $flightLimitExceeded,
            'status' => $status,
        ];
    }

    /**
     * Загрузка по отделу за период: сумма по активным специалистам отдела.
     */
    public function getDepartmentLoad(int $departmentId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM departments WHERE id = ?');
        $stmt->execute([$departmentId]);
        $dep = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dep) {
            return ['error' => 'Department not found'];
        }

        $stmt = $this->pdo->prepare('SELECT id FROM specialists WHERE department_id = ? AND is_active = 1');
        $stmt->execute([$departmentId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hoursPlan = 0.0;
        $hoursTasks = 0.0;
        $specialists = [];
        foreach ($ids as $sid) {
            $load = $this->getSpecialistLoad((int) $sid, $dateFrom, $dateTo);
            if (isset($load['error'])) {
                continue;
            }
            $hoursPlan += $load['hours_plan'];
            $hoursTasks += $load['hours_tasks'];
            $specialists[] = $load;
        }
        $hoursTotal = round($hoursPlan + $hoursTasks, 2);

        $status = 'normal';
        $normSum = 0.0;
        foreach ($specialists as $s) {
            if ($s['norm_hours'] !== null) {
                $normSum += $s['norm_hours'];
            }
        }
        if ($normSum > 0) {
            if ($hoursTotal > $normSum * 1.05) {
                $status = 'overload';
            } elseif ($hoursTotal < $normSum * 0.95) {
                $status = 'underload';
            }
        }

        return [
            'department_id' => $departmentId,
            'department_name' => $dep['name'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'hours_plan' => round($hoursPlan, 2),
            'hours_tasks' => round($hoursTasks, 2),
            'hours_total' => $hoursTotal,
            'norm_hours' => $normSum > 0 ? round($normSum, 2) : null,
            'status' => $status,
            'specialists' => $specialists,
        ];
    }

    /**
     * Факт: сумма учёта времени по дням (bitrix24_task_elapsed) за период по задачам ответственного.
     */
    private function getTaskElapsedHoursTotal(string $bitrix24UserId, string $dateFrom, string $dateTo): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(e.minutes), 0) AS total_minutes
             FROM bitrix24_task_elapsed e
             INNER JOIN bitrix24_tasks_cache t ON t.bitrix24_task_id = e.bitrix24_task_id
             WHERE t.responsible_user_id = ? AND e.elapsed_date >= ? AND e.elapsed_date <= ?'
        );
        $stmt->execute([$bitrix24UserId, $dateFrom, $dateTo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $minutes = (int) ($row['total_minutes'] ?? 0);
        return round($minutes / 60.0, 2);
    }

    /**
     * План: сумма плановых часов за период по задачам ответственного (task_plan_daily, intervals, кэш).
     */
    private function getPlanHoursTotal(string $bitrix24UserId, string $dateFrom, string $dateTo): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT bitrix24_task_id, time_estimate, start_date_plan, end_date_plan, created_date, deadline
             FROM bitrix24_tasks_cache WHERE responsible_user_id = ?'
        );
        $stmt->execute([$bitrix24UserId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($tasks === []) {
            return 0.0;
        }
        $taskIds = array_column($tasks, 'bitrix24_task_id');
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $overrides = [];
        $overrideStmt = $this->pdo->prepare("SELECT bitrix24_task_id, plan_start_date, plan_end_date, original_plan_start, original_plan_end, original_time_estimate FROM task_plan_override WHERE bitrix24_task_id IN ($ph)");
        $overrideStmt->execute($taskIds);
        while ($row = $overrideStmt->fetch(PDO::FETCH_ASSOC)) {
            $overrides[$row['bitrix24_task_id']] = $row;
        }
        $dailyByTask = [];
        $dailyStmt = $this->pdo->prepare("SELECT bitrix24_task_id, plan_date, planned_hours FROM task_plan_daily WHERE bitrix24_task_id IN ($ph) AND plan_date >= ? AND plan_date <= ?");
        $dailyStmt->execute(array_merge($taskIds, [$dateFrom, $dateTo]));
        while ($row = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
            $tid = $row['bitrix24_task_id'];
            $dailyByTask[$tid][$row['plan_date']] = (float) $row['planned_hours'];
        }
        $intervalsByTask = [];
        $intStmt = $this->pdo->prepare("SELECT bitrix24_task_id, date_from, date_to, hours_per_day, sort_order FROM task_plan_intervals WHERE bitrix24_task_id IN ($ph) ORDER BY bitrix24_task_id, sort_order");
        $intStmt->execute($taskIds);
        while ($row = $intStmt->fetch(PDO::FETCH_ASSOC)) {
            $tid = $row['bitrix24_task_id'];
            $intervalsByTask[$tid][] = [
                'date_from' => $row['date_from'],
                'date_to' => $row['date_to'],
                'hours_per_day' => (float) $row['hours_per_day'],
            ];
        }
        $total = 0.0;
        foreach ($tasks as $task) {
            $tid = $task['bitrix24_task_id'];
            $override = $overrides[$tid] ?? null;
            $dailyMap = $dailyByTask[$tid] ?? [];
            $intervals = $intervalsByTask[$tid] ?? [];
            if ($intervals !== []) {
                $byDate = $this->computePlanHoursByDateFromIntervals($intervals, $dateFrom, $dateTo);
            } else {
                if ($override !== null) {
                    $replanStart = $override['plan_start_date'] ?? $override['original_plan_start'] ?? null;
                    $replanEnd = $override['plan_end_date'] ?? $override['original_plan_end'] ?? null;
                    $byDate = $this->computePlanHoursByDateWithRange(
                        $replanStart,
                        $replanEnd,
                        (int) ($task['time_estimate'] ?? 0),
                        $dateFrom,
                        $dateTo
                    );
                } else {
                    $byDate = $this->computePlanHoursByDate($task, $dateFrom, $dateTo);
                }
                foreach ($dailyMap as $d => $h) {
                    $byDate[$d] = round($h, 3);
                }
            }
            $total += array_sum($byDate);
        }
        return round($total, 2);
    }

    private function computePlanHoursByDate(array $task, string $periodFrom, string $periodTo): array
    {
        $planStart = $this->dateOnly($task['start_date_plan'] ?? null) ?? $this->dateOnly($task['created_date'] ?? null);
        $planEnd = $this->dateOnly($task['end_date_plan'] ?? null) ?? $this->dateOnly($task['deadline'] ?? null);
        if ($planStart === null || $planEnd === null) {
            return [];
        }
        $estimate = (int) ($task['time_estimate'] ?? 0);
        if ($estimate <= 0) {
            return [];
        }
        return $this->computePlanHoursByDateWithRange($planStart, $planEnd, $estimate, $periodFrom, $periodTo);
    }

    private function computePlanHoursByDateWithRange(?string $planStart, ?string $planEnd, int $estimateMinutes, string $periodFrom, string $periodTo): array
    {
        if ($planStart === null || $planEnd === null || $estimateMinutes <= 0) {
            return [];
        }
        $totalHours = $estimateMinutes / 60.0;
        $start = new \DateTimeImmutable($planStart);
        $end = new \DateTimeImmutable($planEnd);
        if ($start > $end) {
            $start = new \DateTimeImmutable($planEnd);
            $end = new \DateTimeImmutable($planStart);
        }
        $workingDays = [];
        $cursor = $start;
        while ($cursor <= $end) {
            if (!$this->isWeekend($cursor)) {
                $workingDays[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }
        $workingDaysCount = count($workingDays);
        if ($workingDaysCount === 0) {
            return [];
        }
        $hoursPerDay = $totalHours / $workingDaysCount;
        $periodStart = new \DateTimeImmutable($periodFrom);
        $periodEnd = new \DateTimeImmutable($periodTo);
        $result = [];
        foreach ($workingDays as $dayStr) {
            $d = new \DateTimeImmutable($dayStr);
            if ($d >= $periodStart && $d <= $periodEnd) {
                $result[$dayStr] = round($hoursPerDay, 3);
            }
        }
        return $result;
    }

    /** @param array<int, array{date_from: string, date_to: string, hours_per_day: float}> $intervals */
    private function computePlanHoursByDateFromIntervals(array $intervals, string $periodFrom, string $periodTo): array
    {
        $result = [];
        $periodStart = new \DateTimeImmutable($periodFrom);
        $periodEnd = new \DateTimeImmutable($periodTo);
        foreach ($intervals as $int) {
            $from = $this->dateOnly($int['date_from'] ?? null);
            $to = $this->dateOnly($int['date_to'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }
            $h = (float) ($int['hours_per_day'] ?? 0);
            if ($h < 0) {
                continue;
            }
            $start = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            $cursor = $start;
            while ($cursor <= $end) {
                if (!$this->isWeekend($cursor)) {
                    $dayStr = $cursor->format('Y-m-d');
                    if ($cursor >= $periodStart && $cursor <= $periodEnd) {
                        $result[$dayStr] = round($h, 3);
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }
        }
        return $result;
    }

    private function dateOnly(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function isWeekend(\DateTimeImmutable $d): bool
    {
        $w = (int) $d->format('w');
        return $w === 0 || $w === 6;
    }
}
