<?php

namespace App\Services\Settlement;

class LeaveAccrualCalculator
{
    public function calculate(array $data): array
    {
        $openingBalance = SettlementMath::asDecimal($data['opening_balance'] ?? 0);
        $period = ! empty($data['last_return_from_leave_date'])
            || ! empty($data['last_settlement_date'])
            || ($data['leave_balance_method'] ?? '') === 'date_accrual'
            ? (new LeavePeriodAccrualCalculator())->calculate($data) : null;
        $accruedDays = $period['accrued_days'] ?? SettlementMath::asDecimal($data['accrued_days'] ?? 0);
        $usedDays = SettlementMath::asDecimal($data['used_days'] ?? 0);
        $adjustmentDays = SettlementMath::asDecimal($data['adjustment_days'] ?? 0);
        $previouslySettledDays = SettlementMath::asDecimal($data['previously_settled_days'] ?? 0);

        $balanceBeforeSettlement = SettlementMath::subtract(
            SettlementMath::add($openingBalance, $accruedDays, $adjustmentDays),
            $usedDays,
        );
        $settledDays = SettlementMath::minValue(
            SettlementMath::maxValue($previouslySettledDays, '0'),
            SettlementMath::maxValue($balanceBeforeSettlement, '0'),
        );
        $availableDays = SettlementMath::subtract($balanceBeforeSettlement, $settledDays);

        return [
            'opening_balance' => $openingBalance,
            'accrued_days' => $accruedDays,
            'used_days' => $usedDays,
            'adjustment_days' => $adjustmentDays,
            'previously_settled_days' => $settledDays,
            'requested_previously_settled_days' => $previouslySettledDays,
            'available_days' => $availableDays,
            'accrual_details' => $period,
        ];
    }
}
