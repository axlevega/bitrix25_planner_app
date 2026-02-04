<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Расчёт загрузки специалиста/отдела за период: плановые записи + часы по задачам из кэша B24.
 * Разбивка по типам работ (регулярка/флайт), проверка лимита часов по флайтам.
 */
final class LoadService
{
    private PDO $pdo;

    /** @var array<string, int> group_id → work_type_id (1=regular, 2=flight), кэш для расчёта задач B24 */
    private ?array $projectWorkTypeMap = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Загрузка по специалисту за период.
     * Часы по плану и по задачам B24 с разбивкой regular/flight; проверка лимита флайтов.
     */
    public function getSpecialistLoad(int $specialistId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, bitrix24_user_id, norm_hours_per_day, norm_hours_per_week, flight_hours_limit_per_day, flight_hours_limit_per_week FROM specialists WHERE id = ?');
        $stmt->execute([$specialistId]);
        $spec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$spec) {
            return ['error' => 'Specialist not found'];
        }

        $planByType = $this->getPlanHoursByType($specialistId, $dateFrom, $dateTo);
        $hoursPlanRegular = $planByType['regular'];
        $hoursPlanFlight = $planByType['flight'];
        $hoursPlan = $hoursPlanRegular + $hoursPlanFlight;

        $b24UserId = $spec['bitrix24_user_id'] ?? '';
        $tasksByType = $b24UserId !== '' ? $this->getTaskHoursByType($b24UserId, $dateFrom, $dateTo) : ['regular' => 0.0, 'flight' => 0.0];
        $hoursTasksRegular = $tasksByType['regular'];
        $hoursTasksFlight = $tasksByType['flight'];
        $hoursTasks = $hoursTasksRegular + $hoursTasksFlight;

        $hoursFlight = round($hoursPlanFlight + $hoursTasksFlight, 2);
        $hoursRegular = round($hoursPlanRegular + $hoursTasksRegular, 2);
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
     * Часы по плановым записям специалиста за период, разбивка по work_type_id (1=regular, 2=flight).
     * @return array{regular: float, flight: float}
     */
    private function getPlanHoursByType(int $specialistId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT work_type_id, COALESCE(SUM(hours), 0) as h FROM plan_entries
             WHERE specialist_id = ? AND date_from <= ? AND date_to >= ?
             GROUP BY work_type_id'
        );
        $stmt->execute([$specialistId, $dateTo, $dateFrom]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $regular = 0.0;
        $flight = 0.0;
        foreach ($rows as $r) {
            $h = (float) $r['h'];
            if ((int) $r['work_type_id'] === 2) {
                $flight += $h;
            } else {
                $regular += $h;
            }
        }
        return ['regular' => $regular, 'flight' => $flight];
    }

    /**
     * Маппинг group_id → work_type_id из project_work_type (только flight=2; остальные regular).
     * @return array<string, int>
     */
    private function getProjectWorkTypeMap(): array
    {
        if ($this->projectWorkTypeMap !== null) {
            return $this->projectWorkTypeMap;
        }
        $stmt = $this->pdo->query('SELECT bitrix24_group_id, work_type_id FROM project_work_type');
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(string) $row['bitrix24_group_id']] = (int) $row['work_type_id'];
        }
        $this->projectWorkTypeMap = $map;
        return $map;
    }

    /**
     * Часы по задачам B24 за период, разбивка по типу работы (по group_id → project_work_type).
     * @return array{regular: float, flight: float}
     */
    private function getTaskHoursByType(string $bitrix24UserId, string $dateFrom, string $dateTo): array
    {
        $map = $this->getProjectWorkTypeMap();
        $stmt = $this->pdo->prepare(
            'SELECT group_id, deadline, time_estimate, time_spent FROM bitrix24_tasks_cache
             WHERE responsible_user_id = ? AND deadline IS NOT NULL AND deadline >= ? AND deadline <= ?'
        );
        $stmt->execute([$bitrix24UserId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $regular = 0.0;
        $flight = 0.0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $est = (int) ($r['time_estimate'] ?? 0);
            $spent = (int) ($r['time_spent'] ?? 0);
            $deadline = $r['deadline'] ?? '';
            $toHours = static function (int $v): float {
                return $v / 60.0;
            };
            $hours = ($deadline !== '' && $deadline < $now)
                ? $toHours($spent)
                : max(0.0, $toHours($est) - $toHours($spent));
            $groupId = $r['group_id'] ?? '';
            $workTypeId = $groupId !== '' && isset($map[$groupId]) ? $map[$groupId] : 1;
            if ($workTypeId === 2) {
                $flight += $hours;
            } else {
                $regular += $hours;
            }
        }
        return ['regular' => $regular, 'flight' => $flight];
    }
}
