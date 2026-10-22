<?php

namespace App\Services\Settlement;

use App\Enums\LeaveTransactionType;
use App\Services\Legal\LegalReviewService;
use App\Services\Legal\LegalRuleEngine;

class LeaveSettlementEngine
{
    public static function calculate(array $data): array
    {
        $leaveType = $data['leave_transaction_type'] ?? LeaveTransactionType::ANNUAL_LEAVE_PAY->value;
        $payable = true;
        $warnings = [];

        $salaryBase = (new SalaryBaseCalculator())->calculate($data);
        $monthlyWage = $data['monthly_wage'] ?? $salaryBase['leave_calculation_wage'] ?? '0';
        $dailyRateCalculator = new LeaveDailyRateCalculator();
        $dailyRate = $dailyRateCalculator->calculate($monthlyWage);

        $calculator = new LeaveSettlementCalculator();
        $balance = $calculator->calculate($data);
        $data['available_days'] = $balance['available_days'];

        if (($balance['validation_errors'] ?? []) !== []) {
            return [
                'transaction_type' => 'leave_settlement',
                'leave_transaction_type' => $leaveType,
                'status' => 'blocked',
                'review_status' => 'requires_legal_review',
                'warnings' => [
                    LegalReviewService::warning(
                        'LEAVE_BALANCE_INVALID',
                        'requires_legal_review',
                        $balance['validation_errors'][0] ?? 'رصيد الإجازة غير صالح.',
                        'leave_balance',
                        [['rule_code' => 'leave_balance', 'source_name' => 'HRSD']]
                    ),
                ],
                'legal_references' => [
                    ['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD'],
                ],
                'available_days' => $balance['available_days'],
                'remaining_balance' => $balance['remaining_balance'],
                'gross_leave_amount' => '0',
                'daily_rate' => $dailyRate,
            ];
        }

        if (($leaveType) === LeaveTransactionType::ACTIVE_SERVICE_CASH_ENCASHMENT->value) {
            $payable = false;
            $block = LegalReviewService::warning(
                'LEAVE_CASH_ENCASHMENT_DURING_SERVICE',
                'blocked',
                'لا يجوز استبدال رصيد الإجازة بمقابل نقدي أثناء الخدمة.',
                'leave_transaction_type',
                [['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD']]
            );
            $warnings[] = $block;

            return [
                'transaction_type' => 'leave_settlement',
                'leave_transaction_type' => $leaveType,
                'status' => 'blocked',
                'review_status' => 'blocked',
                'warnings' => $warnings,
                'legal_references' => [
                    ['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD'],
                ],
                'available_days' => $balance['available_days'],
                'remaining_balance' => $balance['remaining_balance'],
                'gross_leave_amount' => '0',
                'daily_rate' => $dailyRate,
            ];
        }

        $applicableDays = $balance['applicable_days'];
        $grossLeaveAmount = SettlementMath::multiply($dailyRate, $applicableDays);
        $grossLeaveAmount = SettlementMath::roundMoney($grossLeaveAmount);
        $remainingBalance = $balance['remaining_balance'];

        $legal = LegalRuleEngine::evaluateLeaveSettlement($data, [
            'warnings' => $warnings,
            'legal_references' => [
                ['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD'],
            ],
            'review_status' => 'valid',
        ]);

        $additional = (new AdditionalFinancialCalculator())->calculate($data);
        $salaryWage = $data['salary_due_monthly_wage'] ?? $monthlyWage;
        $salaryDue = (new SalaryDueCalculator())->calculate([
            'monthly_wage' => $salaryWage,
            'unpaid_days' => $data['unpaid_days'] ?? 0,
            'salary_days_source' => $data['salary_days_source'] ?? 'manual',
            'last_working_date' => $data['last_working_date'] ?? null,
        ]);
        $salaryEntitlements = [];
        if ($salaryDue['days'] > 0) {
            $salaryEntitlements[] = [
                'code' => 'salary_due', 'name_ar' => 'راتب أيام شهر التصفية',
                'quantity' => $salaryDue['days'], 'unit' => 'day', 'rate' => $salaryDue['daily_rate'],
                'amount' => $salaryDue['net_due'], 'status' => 'entitled',
                'calculation' => $salaryDue['calculation'],
                'calculation_details' => array_merge($salaryDue, ['monthly_wage' => $salaryWage]),
                'legal_references' => $salaryDue['legal_references'],
            ];
        }
        $legacyDeductions = (new DeductionCalculator())->calculate($data);
        $deductions = array_merge($legacyDeductions['items'], $additional['deductions']);
        $totalDeductions = SettlementMath::add($legacyDeductions['total'], $additional['deduction_total']);
        $grossEntitlements = SettlementMath::add($grossLeaveAmount, $salaryDue['net_due'], $additional['entitlement_total']);
        $netSettlement = SettlementMath::subtract($grossEntitlements, $totalDeductions);
        $warnings = array_merge($warnings, $legal['warnings'], $additional['warnings']);
        $reviewStatus = LegalReviewService::finalizeStatus($warnings, 'valid');

        return [
            'transaction_type' => 'leave_settlement',
            'leave_transaction_type' => $leaveType,
            'status' => $payable ? 'entitled' : 'blocked',
            'accrual_details' => $balance['accrual_details'] ?? null,
            'review_status' => $reviewStatus,
            'warnings' => $warnings,
            'legal_references' => [
                config('settlement.legal_references.article_109'),
                ['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD'],
            ],
            'available_days' => $balance['available_days'],
            'remaining_balance' => $remainingBalance,
            'requested_leave_days' => $balance['requested_leave_days'],
            'payable_days' => $applicableDays,
            'days_to_compensate' => $balance['days_to_compensate'],
            'gross_leave_amount' => $grossLeaveAmount,
            'entitlements' => array_merge($salaryEntitlements, $additional['entitlements']),
            'salary_due' => array_merge($salaryDue, ['monthly_wage' => $salaryWage]),
            'deductions' => $deductions,
            'gross_entitlements' => $grossEntitlements,
            'total_deductions' => $totalDeductions,
            'net_settlement' => $netSettlement,
            'daily_rate' => $dailyRate,
            'calculation_wage' => $monthlyWage,
            'balance_source' => $data['balance_source'] ?? 'calculated',
        ];
    }
}
