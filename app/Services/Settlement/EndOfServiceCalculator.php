<?php

namespace App\Services\Settlement;

use Illuminate\Support\Facades\Config;

class EndOfServiceCalculator
{
    public function calculate(array $salaryBase, array $servicePeriod, string $terminationType, array $data = []): array
    {
        $baseWage = $salaryBase['eos_calculation_wage'] ?? $salaryBase['gross_monthly_wage'] ?? '0';
        $totalYears = $this->serviceYears($servicePeriod);
        $firstFiveYears = SettlementMath::minValue($totalYears, '5');
        $afterFiveYears = SettlementMath::maxValue(SettlementMath::subtract($totalYears, '5'), '0');

        $firstFiveAmount = SettlementMath::multiply($baseWage, SettlementMath::multiply($firstFiveYears, '0.5'));
        $afterFiveAmount = SettlementMath::multiply($baseWage, $afterFiveYears);
        $baseAward = SettlementMath::add($firstFiveAmount, $afterFiveAmount);

        $legalReferences = $this->legalReferences($terminationType, $data);
        $warnings = [];

        if (in_array($terminationType, ['article_80', 'article_81'], true)) {
            $verification = $this->isArticleVerificationComplete($data, $terminationType);
            if (! $verification) {
                return [
                    'calculation_wage' => $baseWage,
                    'first_five_years' => [
                        'service' => $firstFiveYears,
                        'rate' => '0.5',
                        'amount' => $firstFiveAmount,
                    ],
                    'after_five_years' => [
                        'service' => $afterFiveYears,
                        'rate' => '1',
                        'amount' => $afterFiveAmount,
                    ],
                    'base_award' => $baseAward,
                    'termination_factor' => '0',
                    'final_award' => '0',
                    'status' => 'requires_legal_review',
                    'warnings' => ['requires_legal_review', 'تم اختيار المادة '.$terminationType.' ولكن لم يتم تأكيد متطلبات تطبيقها.'],
                    'legal_references' => $legalReferences,
                    'service_years' => $totalYears,
                    'rate' => '0',
                    'calculation' => 'تعتمد النتيجة على التحقق القانوني لتطبيق المادة '.$terminationType.'.',
                ];
            }
        }

        $terminationFactor = '1';
        $terminationFactorLabel = '1';
        $calculationLabel = 'تطبق قاعدة مكافأة نهاية الخدمة وفق المادة 84.';

        if ($terminationType === 'resignation') {
            $terminationFactor = $this->resignationFactor($totalYears);
            $terminationFactorLabel = $terminationFactor;
            $calculationLabel = 'تُطبق نسبة الاستحقاق حسب المادة 85.';
        }

        if ($terminationType === 'resignation' && $this->article87ExceptionApplicable($data)) {
            $terminationFactor = '1';
            $terminationFactorLabel = '1';
            $calculationLabel = 'تُطبق استثناء المادة 87 مع تحقق الشروط النظامية.';
        }

        $finalAward = SettlementMath::multiply($baseAward, $this->fractionToDecimal($terminationFactor));
        $status = 'entitled';

        if ($terminationType === 'resignation' && SettlementMath::compare($this->fractionToDecimal($terminationFactor), '0') === 0) {
            $status = 'not_entitled';
        }

        return [
            'calculation_wage' => $baseWage,
            'first_five_years' => [
                'service' => $firstFiveYears,
                'rate' => '0.5',
                'amount' => $firstFiveAmount,
            ],
            'after_five_years' => [
                'service' => $afterFiveYears,
                'rate' => '1',
                'amount' => $afterFiveAmount,
            ],
            'base_award' => $baseAward,
            'termination_factor' => $terminationFactorLabel,
            'final_award' => SettlementMath::roundMoney($finalAward),
            'status' => $status,
            'warnings' => $warnings,
            'legal_references' => $legalReferences,
            'service_years' => $totalYears,
            'rate' => $this->fractionToDecimal($terminationFactor),
            'calculation' => $calculationLabel,
        ];
    }

    protected function serviceYears(array $servicePeriod): string
    {
        $years = (string) ($servicePeriod['years'] ?? 0);
        $months = (string) ($servicePeriod['months'] ?? 0);
        $days = (string) ($servicePeriod['days'] ?? 0);

        return SettlementMath::add(
            $years,
            SettlementMath::divide($months, '12', 12),
            SettlementMath::divide($days, '360', 12),
        );
    }

    protected function resignationFactor(string $serviceYears): string
    {
        if (SettlementMath::compare($serviceYears, '2') < 0) {
            return '0';
        }

        if (SettlementMath::compare($serviceYears, '5') < 0) {
            return '1/3';
        }

        if (SettlementMath::compare($serviceYears, '10') < 0) {
            return '2/3';
        }

        return '1';
    }

    protected function fractionToDecimal(string $value): string
    {
        if (str_contains($value, '/')) {
            [$numerator, $denominator] = explode('/', $value, 2);

            return SettlementMath::divide($numerator, $denominator, 12);
        }

        return SettlementMath::asDecimal($value);
    }

    protected function article87ExceptionApplicable(array $data): bool
    {
        return ! empty($data['force_majeure_confirmed'])
            || ! empty($data['marriage_exception_applicable'])
            || ! empty($data['childbirth_exception_applicable']);
    }

    protected function isArticleVerificationComplete(array $data, string $terminationType): bool
    {
        $requiredFields = [
            'article_80' => ['article_80_reason', 'employee_given_opportunity_to_object', 'investigation_completed', 'warning_required', 'warning_given', 'supporting_documents_confirmed'],
            'article_81' => ['article_81_reason', 'employee_allowed_to_continue', 'justified_termination_verified'],
        ];

        $fields = $requiredFields[$terminationType] ?? [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                return false;
            }
        }

        return true;
    }

    protected function legalReferences(string $terminationType, array $data = []): array
    {
        $base = [
            Config::get('settlement.legal_references.article_84'),
        ];

        if ($terminationType === 'resignation') {
            $base[] = Config::get('settlement.legal_references.article_85');
            if ($this->article87ExceptionApplicable($data)) {
                $base[] = Config::get('settlement.legal_references.article_87');
            }
        }

        if (in_array($terminationType, ['article_80', 'article_81'], true)) {
            $base[] = Config::get('settlement.legal_references.article_87');
        }

        return $base;
    }
}
