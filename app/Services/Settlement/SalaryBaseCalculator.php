<?php

namespace App\Services\Settlement;

class SalaryBaseCalculator
{
    public function calculate(array $data): array
    {
        $basicSalary = SettlementMath::asDecimal($data['basic_salary'] ?? 0);
        $housingAllowance = SettlementMath::asDecimal($data['housing_allowance'] ?? 0);
        $transportAllowance = SettlementMath::asDecimal($data['transport_allowance'] ?? 0);
        $fixedAllowances = SettlementMath::asDecimal($data['fixed_allowances'] ?? 0);
        $commissions = SettlementMath::asDecimal($data['commissions'] ?? 0);
        $otherAllowances = SettlementMath::asDecimal($data['other_allowances'] ?? 0);

        $components = [
            'basic_salary' => [
                'amount' => $basicSalary,
                'include_in_eos' => true,
                'include_in_leave' => true,
                'include_in_notice' => true,
                'reason' => 'Basic salary is included in the monthly wage base.',
            ],
            'housing_allowance' => [
                'amount' => $housingAllowance,
                'include_in_eos' => true,
                'include_in_leave' => true,
                'include_in_notice' => true,
                'reason' => 'Housing allowance is included unless a specific contractual exclusion applies.',
            ],
            'transport_allowance' => [
                'amount' => $transportAllowance,
                'include_in_eos' => true,
                'include_in_leave' => true,
                'include_in_notice' => true,
                'reason' => 'Transport allowance is included unless specifically excluded.',
            ],
            'fixed_allowances' => [
                'amount' => $fixedAllowances,
                'include_in_eos' => true,
                'include_in_leave' => true,
                'include_in_notice' => true,
                'reason' => 'Fixed allowances remain part of the monthly wage base.',
            ],
            'commissions' => [
                'amount' => $commissions,
                'include_in_eos' => (bool) ($data['commissions_included_in_eos'] ?? true),
                'include_in_leave' => (bool) ($data['commissions_included_in_leave'] ?? true),
                'include_in_notice' => (bool) ($data['commissions_included_in_notice'] ?? true),
                'reason' => 'Commission inclusion is evaluated according to the applicable contractual rules and Article 86.',
            ],
            'other_allowances' => [
                'amount' => $otherAllowances,
                'include_in_eos' => (bool) ($data['other_allowances_included_in_eos'] ?? true),
                'include_in_leave' => (bool) ($data['other_allowances_included_in_leave'] ?? true),
                'include_in_notice' => (bool) ($data['other_allowances_included_in_notice'] ?? true),
                'reason' => 'Other allowances require a documented contractual or legal basis before inclusion.',
            ],
        ];

        if (! empty($data['salary_components'])) {
            foreach ($data['salary_components'] as $component) {
                $code = (string) ($component['code'] ?? $component['name'] ?? 'custom_component');
                $amount = SettlementMath::asDecimal($component['amount'] ?? 0);
                $components[$code] = [
                    'amount' => $amount,
                    'include_in_eos' => (bool) ($component['include_in_eos'] ?? true),
                    'include_in_leave' => (bool) ($component['include_in_leave'] ?? true),
                    'include_in_notice' => (bool) ($component['include_in_notice'] ?? true),
                    'reason' => $component['reason'] ?? 'Salary component included based on the documented HR record.',
                ];
            }
        }

        $grossMonthlyWage = '0';
        $eosCalculationWage = '0';
        $leaveCalculationWage = '0';
        $noticeCalculationWage = '0';
        $exclusions = [];

        foreach ($components as $code => $component) {
            $grossMonthlyWage = SettlementMath::add($grossMonthlyWage, $component['amount']);

            if (! ($component['include_in_eos'] ?? true)) {
                $exclusions[] = [
                    'component' => $code,
                    'amount' => $component['amount'],
                    'included' => false,
                    'reason' => $component['reason'],
                    'legal_reference' => 'Article 86',
                ];
            } else {
                $eosCalculationWage = SettlementMath::add($eosCalculationWage, $component['amount']);
            }

            if (! ($component['include_in_leave'] ?? true)) {
                $exclusions[] = [
                    'component' => $code,
                    'amount' => $component['amount'],
                    'included' => false,
                    'reason' => $component['reason'],
                    'legal_reference' => 'Article 111',
                ];
            } else {
                $leaveCalculationWage = SettlementMath::add($leaveCalculationWage, $component['amount']);
            }

            if (! ($component['include_in_notice'] ?? true)) {
                $exclusions[] = [
                    'component' => $code,
                    'amount' => $component['amount'],
                    'included' => false,
                    'reason' => $component['reason'],
                    'legal_reference' => 'Article 75',
                ];
            } else {
                $noticeCalculationWage = SettlementMath::add($noticeCalculationWage, $component['amount']);
            }
        }

        return [
            'basic_salary' => $basicSalary,
            'housing_allowance' => $housingAllowance,
            'transport_allowance' => $transportAllowance,
            'fixed_allowances' => $fixedAllowances,
            'commissions' => $commissions,
            'other_allowances' => $otherAllowances,
            'gross_monthly_wage' => $grossMonthlyWage,
            'eos_calculation_wage' => $eosCalculationWage,
            'leave_calculation_wage' => $leaveCalculationWage,
            'notice_calculation_wage' => $noticeCalculationWage,
            'exclusions' => $exclusions,
        ];
    }
}
