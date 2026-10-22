<?php

namespace App\Services\Settlement;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class LeavePeriodAccrualCalculator
{
    public function calculate(array $data): array
    {
        foreach (['hire_date', 'last_working_date'] as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException('يجب إدخال تاريخ التوظيف وآخر يوم عمل لحساب رصيد الإجازة.');
            }
        }
        $hire = CarbonImmutable::parse($data['hire_date'])->startOfDay();
        $last = CarbonImmutable::parse($data['last_working_date'])->startOfDay();
        $returnDate = ! empty($data['last_return_from_leave_date'])
            ? CarbonImmutable::parse($data['last_return_from_leave_date'])->startOfDay()
            : null;
        $settlementDate = ! empty($data['last_settlement_date'])
            ? CarbonImmutable::parse($data['last_settlement_date'])->startOfDay()
            : null;
        $start = $returnDate ?? $hire;
        if ($settlementDate && $settlementDate->addDay()->gt($start)) {
            $start = $settlementDate->addDay();
        }
        if ($start->lt($hire) || $last->lt($start)) {
            throw new InvalidArgumentException('ترتيب تواريخ استحقاق الإجازة غير صحيح.');
        }

        $yearDays = (int) config('settlement.leave_accrual.year_days', 360);
        $monthDays = (int) config('settlement.leave_accrual.month_days', 30);
        if ($yearDays <= 0 || $monthDays <= 0) {
            throw new InvalidArgumentException('أساس أيام السنة والشهر لاستحقاق الإجازة يجب أن يكون أكبر من صفر.');
        }

        $fiveYearAnniversary = $hire->addYearsNoOverflow(5);
        $total = '0';
        $segments = [];
        $contractDays = SettlementMath::asDecimal($data['contractual_annual_leave_days'] ?? 0);

        $boundaries = [$start];
        if ($start->lt($fiveYearAnniversary) && $last->gte($fiveYearAnniversary)) {
            $boundaries[] = $fiveYearAnniversary;
        }
        $boundaries[] = $last;

        $originalServiceDays = $this->thirtyE360Days($start, $last, $yearDays, $monthDays);
        // Preserve old saved calculations when the option is absent.
        $deductFromPeriod = (bool) ($data['deduct_settled_days_from_accrual_period'] ?? false);
        $periodDeduction = $deductFromPeriod ? SettlementMath::minValue(
            SettlementMath::maxValue(SettlementMath::asDecimal($data['previously_settled_days'] ?? 0), '0'),
            (string) $originalServiceDays,
        ) : '0';
        $netServiceDays = SettlementMath::subtract((string) $originalServiceDays, $periodDeduction);

        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            $segmentStart = $boundaries[$index];
            $segmentEnd = $boundaries[$index + 1];
            $days = $this->thirtyE360Days($segmentStart, $segmentEnd, $yearDays, $monthDays);
            // No leave dates are supplied, so distribute exclusions proportionally across rate segments.
            $eligibleDays = $originalServiceDays > 0
                ? SettlementMath::divide(SettlementMath::multiply((string) $days, $netServiceDays), (string) $originalServiceDays)
                : '0';
            $annualDays = SettlementMath::maxValue(
                $segmentStart->lt($fiveYearAnniversary) ? '21' : '30',
                $contractDays,
            );
            $accrued = SettlementMath::divide(
                SettlementMath::multiply($eligibleDays, $annualDays),
                (string) $yearDays,
            );
            $total = SettlementMath::add($total, $accrued);
            $segments[] = [
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'service_days' => $days,
                'eligible_service_days' => $eligibleDays,
                'year_days' => $yearDays,
                'annual_entitlement' => $annualDays,
                'accrued_days' => $accrued,
            ];
        }

        return [
            'method' => 'date_accrual',
            'hire_date' => $hire->toDateString(),
            'last_return_from_leave_date' => $returnDate?->toDateString(),
            'last_settlement_date' => $settlementDate?->toDateString(),
            'period_from' => $start->toDateString(),
            'period_to' => $last->toDateString(),
            'service_days' => array_sum(array_column($segments, 'service_days')),
            'deduct_settled_days_from_accrual_period' => $deductFromPeriod,
            'excluded_service_days' => $periodDeduction,
            'eligible_service_days' => $netServiceDays,
            'year_days' => $yearDays,
            'year_basis' => $monthDays.'/'.$yearDays,
            'day_count_convention' => config('settlement.leave_accrual.day_count_convention', '30E/360 ISDA'),
            'includes_start_day' => false,
            'includes_last_working_day' => false,
            'segments' => $segments,
            'accrued_days' => $total,
        ];
    }

    private function thirtyE360Days(
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $yearDays,
        int $monthDays,
    ): int
    {
        $startDay = $start->day;
        $endDay = $end->day;

        if ($start->isLastOfMonth()) {
            $startDay = 30;
        }
        if ($end->isLastOfMonth() || $endDay === 31) {
            $endDay = 30;
        }

        return (($end->year - $start->year) * $yearDays)
            + (($end->month - $start->month) * $monthDays)
            + ($endDay - $startDay);
    }
}
