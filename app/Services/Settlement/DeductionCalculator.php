<?php

namespace App\Services\Settlement;

class DeductionCalculator
{
    public function calculate(array $data): array
    {
        $items = [];
        $total = '0';

        $deductionFields = [
            'employee_loan' => 'قرض الموظف',
            'advance' => 'سلفة',
            'absence_adjustment' => 'تعديل الغياب',
            'unreturned_assets' => 'أصول غير مسترجعة',
            'other' => 'خصم آخر',
        ];

        foreach ($deductionFields as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $amount = SettlementMath::asDecimal($data[$field] ?? 0);
            if (SettlementMath::compare($amount, '0') <= 0) {
                continue;
            }

            $items[] = [
                'code' => $field,
                'description' => $label,
                'amount' => SettlementMath::roundMoney($amount),
                'source' => 'manual',
                'is_verified' => (bool) ($data[$field.'_is_verified'] ?? true),
                'requires_review' => false,
            ];
            $total = SettlementMath::add($total, SettlementMath::roundMoney($amount));
        }

        if (! empty($data['deductions']) && is_array($data['deductions'])) {
            foreach ($data['deductions'] as $deduction) {
                $amount = SettlementMath::asDecimal($deduction['amount'] ?? 0);
                if (SettlementMath::compare($amount, '0') <= 0) {
                    continue;
                }

                $items[] = [
                    'code' => $deduction['code'] ?? 'custom_deduction',
                    'description' => $deduction['description'] ?? 'خصم إضافي',
                    'amount' => SettlementMath::roundMoney($amount),
                    'source' => $deduction['source'] ?? 'manual',
                    'is_verified' => (bool) ($deduction['is_verified'] ?? true),
                    'requires_review' => (bool) ($deduction['requires_review'] ?? false),
                ];
                $total = SettlementMath::add($total, SettlementMath::roundMoney($amount));
            }
        }

        return [
            'items' => $items,
            'total' => SettlementMath::roundMoney($total),
        ];
    }
}
