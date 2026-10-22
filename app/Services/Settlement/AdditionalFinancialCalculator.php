<?php

namespace App\Services\Settlement;

use App\Services\Legal\LegalReviewService;

class AdditionalFinancialCalculator
{
    public function calculate(array $data): array
    {
        $result = ['entitlements' => [], 'deductions' => [], 'entitlement_total' => '0', 'deduction_total' => '0', 'warnings' => []];
        foreach (['entitlements' => 'additional_entitlements', 'deductions' => 'additional_deductions'] as $type => $field) {
            foreach (array_values($data[$field] ?? []) as $index => $item) {
                $amount = SettlementMath::roundMoney($item['amount']);
                $result[$type][] = [
                    'code' => ($type === 'entitlements' ? 'additional_entitlement_' : 'additional_deduction_').($index + 1),
                    'name_ar' => $item['name'], 'description' => $item['name'], 'amount' => $amount,
                    'quantity' => 1, 'rate' => $amount, 'status' => 'warning',
                    'source' => 'manual', 'reason' => $item['reason'], 'calculation' => $item['reason'],
                    'calculation_details' => ['source' => 'manual', 'reason' => $item['reason']],
                    'legal_references' => [], 'is_verified' => false, 'requires_review' => true,
                ];
                $total = $type === 'entitlements' ? 'entitlement_total' : 'deduction_total';
                $result[$total] = SettlementMath::add($result[$total], $amount);
            }
        }
        if ($result['entitlements'] || $result['deductions']) {
            $result['warnings'][] = LegalReviewService::warning('ADDITIONAL_FINANCIAL_ITEMS', 'warning',
                'تحتوي المعاملة على مكافآت أو خصومات مدخلة يدوياً؛ يجب مراجعة أسبابها واعتمادها.', 'additional_financial_items');
        }
        return $result;
    }
}
