<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Расчёт загрузки специалиста/отдела за период: часы по задачам из кэша B24.
 */
final class LoadService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Загрузка по специалисту за период (только часы по задачам B24).
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
        $hoursTasks = $b24UserId !== '' ? $this->getTaskHoursTotal($b24UserId, $dateFrom, $dateTo) : 0.0;
        $hoursPlan = 0.0;
        $hoursPlanRegular = 0.0;
        $hoursPlanFlight = 0.0;
        $hoursTasksRegular = $hoursTasks;
        $hoursTasksFlight = 0.0;

        $hoursFlight = 0.0;
        $hoursRegular = round($hoursTasks, 2);
        $hoursTotal = round($hoursTasks, 2);

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
     * Суммарные часы по задачам B24 за период.
     */
    private function getTaskHoursTotal(string $bitrix24UserId, string $dateFrom, string $dateTo): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT deadline, time_estimate, time_spent FROM bitrix24_tasks_cache
             WHERE responsible_user_id = ? AND deadline IS NOT NULL AND deadline >= ? AND deadline <= ?'
        );
        $stmt->execute([$bitrix24UserId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $est = (int) ($r['time_estimate'] ?? 0);
            $spent = (int) ($r['time_spent'] ?? 0);
            $deadline = $r['deadline'] ?? '';
            $toHours = static function (int $v): float {
                return $v / 60.0;
            };
            $total += ($deadline !== '' && $deadline < $now)
                ? $toHours($spent)
                : max(0.0, $toHours($est) - $toHours($spent));
        }
        return $total;
    }
}
