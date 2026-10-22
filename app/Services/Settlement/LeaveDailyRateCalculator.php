<?php

namespace App\Services\Settlement;

class LeaveDailyRateCalculator
{
    public function calculate(mixed $monthlyWage, ?int $monthlyWageDays = null): string
    {
        $monthlyDays = $monthlyWageDays ?? (int) config('settlement.monthly_wage_days', 30);
        $wage = SettlementMath::asDecimal($monthlyWage ?? 0);

        return SettlementMath::divide($wage, (string) $monthlyDays, 12);
    }
}
