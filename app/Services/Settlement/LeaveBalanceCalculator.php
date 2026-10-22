<?php

namespace App\Services\Settlement;

use Illuminate\Support\Facades\Config;

class LeaveBalanceCalculator
{
    public function calculate(array $data): array
    {
        $salaryBase = $data['salary_base'] ?? [];
        $monthlyWage = $data['monthly_wage'] ?? ($salaryBase['leave_calculation_wage'] ?? $salaryBase['gross_monthly_wage'] ?? '0');
        $remainingDays = $data['remaining_leave_days'] ?? null;
        $accrual = null;
        if (! empty($data['last_return_from_leave_date'])
            || ! empty($data['last_settlement_date'])
            || ($data['leave_balance_method'] ?? '') === 'date_accrual') {
            $accrual = (new LeaveAccrualCalculator())->calculate(array_merge($data, [
                'used_days' => $data['used_leave_days'] ?? 0,
                'adjustment_days' => $data['adjusted_leave_days'] ?? 0,
                'previously_settled_days' => $data['previously_settled_days'] ?? 0,
            ]));
            $remainingDays = $accrual['available_days'];
        }

        if ($remainingDays === null) {
            $accruedLeaveDays = (float) ($data['accrued_leave_days'] ?? 0);
            $usedLeaveDays = (float) ($data['used_leave_days'] ?? 0);
            $adjustedLeaveDays = (float) ($data['adjusted_leave_days'] ?? 0);
            $remainingDays = $accruedLeaveDays - $usedLeaveDays + $adjustedLeaveDays;
        }

        $remainingDays = $accrual ? SettlementMath::maxValue($remainingDays, '0') : max(0, (float) $remainingDays);
        $dailyRate = SettlementMath::divide((string) $monthlyWage, (string) config('settlement.monthly_wage_days', 30), 12);
        $amount = SettlementMath::multiply((string) $dailyRate, (string) $remainingDays);
        $source = $accrual ? 'date_accrual' : (isset($data['remaining_leave_days']) ? 'manual' : 'calculated');
        $warnings = [];

        return [
            'remaining_days' => $remainingDays,
            'daily_rate' => $dailyRate,
            'amount' => SettlementMath::roundMoney($amount),
            'source' => $source,
            'legal_reference' => 'Article 111',
            'legal_references' => array_values(array_filter([Config::get('settlement.legal_references.article_111'), $accrual ? Config::get('settlement.legal_references.article_109') : null])),
            'warnings' => $warnings,
            'accrual_details' => $accrual,
            'calculation' => 'تم حساب بدل رصيد الإجازة بقيمة الراتب اليومي مضروباً في الرصيد المتبقي.',
        ];
    }
}
