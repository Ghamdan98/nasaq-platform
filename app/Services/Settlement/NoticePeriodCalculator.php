<?php

namespace App\Services\Settlement;

use Illuminate\Support\Facades\Config;

class NoticePeriodCalculator
{
    public function calculate(array $data): array
    {
        $terminationType = $data['termination_type'] ?? 'termination';
        $monthlyWage = SettlementMath::asDecimal($data['monthly_wage'] ?? 0);
        $actualNoticeDays = (int) ($data['actual_notice_days'] ?? $data['notice_days'] ?? 0);
        $contractNoticeDays = $data['contract_notice_days'] ?? null;
        $contractType = $data['contract_type'] ?? 'indefinite';
        $isMonthly = str_contains(strtolower((string) $contractType), 'monthly') || str_contains(strtolower((string) $contractType), 'indefinite');

        $requiredDays = 30;
        if ($isMonthly) {
            $requiredDays = $terminationType === 'resignation' ? 30 : 60;
        }

        if ($contractNoticeDays !== null && (int) $contractNoticeDays > $requiredDays) {
            $requiredDays = (int) $contractNoticeDays;
        }

        $missingDays = max(0, $requiredDays - $actualNoticeDays);
        $dailyRate = SettlementMath::divide($monthlyWage, (string) config('settlement.monthly_wage_days', 30), 12);
        $compensation = SettlementMath::multiply($dailyRate, (string) $missingDays);

        $status = 'not_entitled';
        $warnings = [];

        if ($missingDays > 0 && ! in_array($terminationType, ['resignation'], true)) {
            $status = 'entitled';
        }

        if ($terminationType === 'resignation') {
            $status = 'not_entitled';
            // An employee's unserved notice is never an employer-paid entitlement.
            $compensation = '0';
            if ($missingDays > 0) {
                $warnings[] = 'requires_legal_review';
            }
        }

        if (! $isMonthly || ! in_array($terminationType, ['termination', 'resignation'], true)) {
            $compensation = '0';
            $status = 'requires_legal_review';
            $warnings[] = 'requires_legal_review';
        }

        return [
            'required_days' => $requiredDays,
            'completed_days' => $actualNoticeDays,
            'missing_days' => $missingDays,
            'daily_rate' => $dailyRate,
            'compensation' => SettlementMath::roundMoney($compensation),
            'payer' => 'employer',
            'beneficiary' => 'employee',
            'status' => $status,
            'warnings' => $warnings,
            'calculation' => 'يُحسب بدل الإشعار على أساس الراتب اليومي للفترة غير الملتزم بها وفق المواد 75 و76.',
            'legal_references' => [
                Config::get('settlement.legal_references.article_75'),
                Config::get('settlement.legal_references.article_76'),
            ],
        ];
    }
}
