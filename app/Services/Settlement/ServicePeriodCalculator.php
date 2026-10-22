<?php

namespace App\Services\Settlement;

use Carbon\Carbon;
use InvalidArgumentException;

class ServicePeriodCalculator
{
    public function calculate(array $data): array
    {
        $hireDate = $data['hire_date'] ?? null;
        $endDate = $data['last_working_date'] ?? $data['termination_date'] ?? null;

        if (! $hireDate || ! $endDate) {
            throw new InvalidArgumentException('يجب توفير تاريخ المباشرة وتاريخ آخر يوم عمل.');
        }

        $start = Carbon::parse($hireDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('تاريخ آخر يوم عمل لا يمكن أن يكون قبل تاريخ التوظيف.');
        }

        $diff = $start->diff($end);
        $totalDays = (int) $start->diffInDays($end);

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'years' => (int) $diff->y,
            'months' => (int) $diff->m,
            'days' => (int) $diff->d,
            'total_days' => $totalDays,
            'display_ar' => sprintf('%s سنوات و%s أشهر و%s يوماً', $diff->y, $diff->m, $diff->d),
        ];
    }
}
