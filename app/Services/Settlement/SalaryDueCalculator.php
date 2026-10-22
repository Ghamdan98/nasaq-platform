<?php

namespace App\Services\Settlement;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class SalaryDueCalculator
{
    public function calculate(array $data): array
    {
        $monthlyWage = SettlementMath::asDecimal($data['monthly_wage'] ?? 0);
        $payPeriodStart = $data['pay_period_start'] ?? null;
        $lastWorkingDate = $data['last_working_date'] ?? null;
        $alreadyPaidAmount = SettlementMath::asDecimal($data['already_paid_amount'] ?? 0);
        $unpaidDays = $data['unpaid_days'] ?? null;

        $monthlyWageDays = (int) config('settlement.monthly_wage_days', 30);
        $dailyRate = SettlementMath::divide($monthlyWage, (string) $monthlyWageDays, 12);
        $source = $data['salary_days_source'] ?? 'manual';
        $note = '';

        if ($source === 'last_working_date' && $lastWorkingDate) {
            $last = Carbon::parse($lastWorkingDate)->startOfDay();
            $payPeriodStart = $last->copy()->startOfMonth()->toDateString();
            $days = min($last->day, $monthlyWageDays);
        } elseif ($source === 'paid') {
            $days = 0;
        } elseif ($unpaidDays !== null) {
            $days = max(0, (int) $unpaidDays);
        } elseif ($payPeriodStart && $lastWorkingDate) {
            $start = Carbon::parse($payPeriodStart)->startOfDay();
            $end = Carbon::parse($lastWorkingDate)->startOfDay();
            $days = max(0, (int) $start->diffInDays($end) + 1);
        } else {
            $days = $monthlyWageDays;
        }
        if ($days > 0 && $lastWorkingDate) {
            $note = 'راتب '.$days.' يوماً من شهر '.Carbon::parse($lastWorkingDate)->format('m/Y');
        }

        $grossDue = SettlementMath::multiply($dailyRate, (string) $days);
        $netDue = SettlementMath::subtract($grossDue, $alreadyPaidAmount);
        if (SettlementMath::compare($netDue, '0') < 0) {
            $netDue = '0';
        }

        return [
            'period' => $payPeriodStart ? Carbon::parse($payPeriodStart)->toDateString() : null,
            'days' => $days,
            'daily_rate' => $dailyRate,
            'gross_due' => $grossDue,
            'already_paid' => $alreadyPaidAmount,
            'days_source' => $source,
            'note' => $note,
            'net_due' => SettlementMath::roundMoney($netDue),
            'calculation' => ($note ? $note.'. ' : '').'تُحسب قيمة الراتب المستحق من خلال تقسيم الأجر الشهري على '.config('settlement.monthly_wage_days', 30).' يوماً.',
            'legal_references' => [],
        ];
    }
}
