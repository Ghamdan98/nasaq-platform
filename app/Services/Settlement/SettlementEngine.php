<?php

namespace App\Services\Settlement;

use App\Models\Employee;
use App\Models\TerminationType;
use Carbon\Carbon;

class SettlementEngine
{
    public function __construct(
        protected ServicePeriodCalculator $servicePeriodCalculator = new ServicePeriodCalculator(),
        protected SalaryBaseCalculator $salaryBaseCalculator = new SalaryBaseCalculator(),
        protected EndOfServiceCalculator $endOfServiceCalculator = new EndOfServiceCalculator(),
        protected SalaryDueCalculator $salaryDueCalculator = new SalaryDueCalculator(),
        protected LeaveBalanceCalculator $leaveBalanceCalculator = new LeaveBalanceCalculator(),
        protected NoticePeriodCalculator $noticePeriodCalculator = new NoticePeriodCalculator(),
        protected DeductionCalculator $deductionCalculator = new DeductionCalculator(),
    ) {
    }

    public static function calculate(array $data): array
    {
        return (new self())->evaluate($data);
    }

    public function evaluate(array $data): array
    {
        $employeeId = $data['employee_id'] ?? null;
        $contractId = $data['contract_id'] ?? null;
        $terminationType = $this->resolveTerminationType($data);
        $hireDate = $data['hire_date'] ?? null;
        $lastWorkingDate = $data['last_working_date'] ?? $data['termination_date'] ?? null;

        if (! $hireDate) {
            $hireDate = $this->resolveHireDate($employeeId, $contractId);
        }

        if (! $lastWorkingDate) {
            $lastWorkingDate = $data['termination_date'] ?? null;
        }

        $servicePeriod = $this->servicePeriodCalculator->calculate([
            'hire_date' => $hireDate,
            'last_working_date' => $lastWorkingDate,
        ]);

        $salaryBase = $this->salaryBaseCalculator->calculate($data);

        $salaryDue = $this->salaryDueCalculator->calculate([
            'monthly_wage' => $data['salary_due_monthly_wage'] ?? $salaryBase['gross_monthly_wage'],
            'pay_period_start' => $data['pay_period_start'] ?? Carbon::parse($lastWorkingDate)->copy()->subMonth()->toDateString(),
            'last_working_date' => $lastWorkingDate,
            'already_paid_amount' => $data['already_paid_amount'] ?? '0',
            'unpaid_days' => $data['unpaid_days'] ?? null,
            'salary_days_source' => $data['salary_days_source'] ?? 'manual',
        ]);

        $leave = $this->leaveBalanceCalculator->calculate(array_merge($data, [
            'hire_date' => $hireDate,
            'salary_base' => $salaryBase,
            'remaining_leave_days' => $data['remaining_leave_days'] ?? null,
            'accrued_leave_days' => $data['accrued_leave_days'] ?? null,
            'used_leave_days' => $data['used_leave_days'] ?? null,
            'adjusted_leave_days' => $data['adjusted_leave_days'] ?? null,
            'monthly_wage' => $salaryBase['leave_calculation_wage'],
        ]));

        $eos = $this->endOfServiceCalculator->calculate(
            $salaryBase,
            $servicePeriod,
            $terminationType,
            $data,
        );

        $notice = $this->noticePeriodCalculator->calculate([
            'termination_type' => $terminationType,
            'monthly_wage' => $salaryBase['notice_calculation_wage'],
            'contract_notice_days' => $data['contract_notice_days'] ?? null,
            'actual_notice_days' => $data['actual_notice_days'] ?? $data['notice_days'] ?? 0,
            'required_notice_days' => $data['required_notice_days'] ?? null,
            'employee_initiated' => ($terminationType === 'resignation') ? true : ($data['employee_initiated'] ?? false),
            'employer_initiated' => ($terminationType === 'termination') ? true : ($data['employer_initiated'] ?? false),
            'contract_type' => $data['contract_type'] ?? (is_array($data['contract'] ?? null) ? ($data['contract']['contract_type'] ?? 'indefinite') : 'indefinite'),
            'daily_rate' => SettlementMath::divide($salaryBase['notice_calculation_wage'], (string) config('settlement.monthly_wage_days', 30)),
        ]);

        $deductions = $this->deductionCalculator->calculate($data);
        $entitlements = [
            [
                'code' => 'salary_due',
                'name_ar' => 'راتب مستحق',
                'quantity' => $salaryDue['days'],
                'unit' => 'day',
                'rate' => $salaryDue['daily_rate'],
                'amount' => $salaryDue['net_due'],
                'status' => 'entitled',
                'calculation' => $salaryDue['calculation'],
                'calculation_details' => $salaryDue,
                'legal_references' => $salaryDue['legal_references'],
            ],
            [
                'code' => 'leave_balance',
                'name_ar' => 'بدل رصيد الإجازة',
                'quantity' => $leave['remaining_days'],
                'unit' => 'day',
                'rate' => $leave['daily_rate'],
                'amount' => $leave['amount'],
                'status' => 'entitled',
                'calculation' => $leave['calculation'],
                'legal_references' => $leave['legal_references'],
            ],
            [
                'code' => 'end_of_service',
                'name_ar' => 'مكافأة نهاية الخدمة',
                'quantity' => $eos['service_years'],
                'unit' => 'year',
                'rate' => $eos['rate'],
                'amount' => $eos['final_award'],
                'status' => $eos['status'],
                'calculation' => $eos['calculation'],
                'legal_references' => $eos['legal_references'],
            ],
            [
                'code' => 'notice_compensation',
                'name_ar' => 'بدل الإشعار',
                'quantity' => $notice['missing_days'],
                'unit' => 'day',
                'rate' => $notice['daily_rate'],
                'amount' => $notice['compensation'],
                'status' => $notice['status'],
                'calculation' => $notice['calculation'],
                'legal_references' => $notice['legal_references'],
            ],
        ];

        $additional = (new AdditionalFinancialCalculator())->calculate($data);
        $entitlements = array_merge($entitlements, $additional['entitlements']);
        $deductions['items'] = array_merge($deductions['items'], $additional['deductions']);
        $deductions['total'] = SettlementMath::add($deductions['total'], $additional['deduction_total']);
        $warnings = array_merge($eos['warnings'], $additional['warnings']);
        $warnings = array_merge($warnings, $notice['warnings']);
        $warnings = array_merge($warnings, $leave['warnings']);

        $legalReferences = $this->deduplicateLegalReferences(
            array_merge(
                $salaryDue['legal_references'],
                $leave['legal_references'],
                $eos['legal_references'],
                $notice['legal_references'],
            )
        );

        $grossEntitlements = '0';
        foreach ($entitlements as $item) {
            $grossEntitlements = SettlementMath::add($grossEntitlements, $item['amount']);
        }

        $totalDeductions = $deductions['total'];
        $netSettlement = SettlementMath::subtract($grossEntitlements, $totalDeductions);

        $reviewStatus = 'valid';
        if (! empty($warnings)) {
            $reviewStatus = in_array('requires_legal_review', $warnings, true) ? 'requires_legal_review' : 'warning';
        }

        $employee = null;
        if ($employeeId) {
            $employeeModel = Employee::find($employeeId);
            if ($employeeModel) {
                $employee = [
                    'id' => $employeeModel->id,
                    'employee_number' => $employeeModel->employee_number,
                    'full_name' => $employeeModel->full_name,
                ];
            }
        }

        return [
            'employee' => $employee,
            'contract' => $data['contract'] ?? null,
            'termination' => [
                'type' => $terminationType,
                'date' => $data['termination_date'] ?? $lastWorkingDate,
            ],
            'service_period' => [
                'years' => $servicePeriod['years'],
                'months' => $servicePeriod['months'],
                'days' => $servicePeriod['days'],
                'total_days' => $servicePeriod['total_days'],
            ],
            'salary_base' => [
                'basic_salary' => $salaryBase['basic_salary'],
                'gross_monthly_wage' => $salaryBase['gross_monthly_wage'],
                'eos_wage' => $salaryBase['eos_calculation_wage'],
            ],
            'entitlements' => $entitlements,
            'leave_calculation' => $leave,
            'salary_due' => $salaryDue,
            'deductions' => $deductions['items'],
            'gross_entitlements' => SettlementMath::roundMoney($grossEntitlements),
            'total_deductions' => SettlementMath::roundMoney($totalDeductions),
            'net_settlement' => SettlementMath::roundMoney($netSettlement),
            'review_status' => $reviewStatus,
            'warnings' => $warnings,
            'legal_references' => $legalReferences,
        ];
    }

    protected function resolveTerminationType(array $data): string
    {
        if (! empty($data['termination_type'])) {
            return (string) $data['termination_type'];
        }

        if (! empty($data['termination_type_code'])) {
            return (string) $data['termination_type_code'];
        }

        if (! empty($data['termination_type_id'])) {
            $terminationType = TerminationType::find($data['termination_type_id']);

            return $terminationType ? $terminationType->code : 'termination';
        }

        return 'termination';
    }

    protected function resolveHireDate(?int $employeeId, ?int $contractId): ?string
    {
        if ($employeeId) {
            $employee = Employee::find($employeeId);

            if ($employee && $employee->hire_date) {
                return $employee->hire_date;
            }
        }

        return null;
    }

    protected function deduplicateLegalReferences(array $references): array
    {
        $seen = [];
        $result = [];

        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $key = ($reference['rule_code'] ?? $reference['article_number'] ?? 'reference').'-'.($reference['source_name'] ?? '');

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $result[] = $reference;
        }

        return $result;
    }
}
