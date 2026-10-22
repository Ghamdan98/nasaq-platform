<?php

namespace App\Services\Settlement;

class LeaveSettlementCalculator
{
    public function calculate(array $data): array
    {
        $accrual = (new LeaveAccrualCalculator())->calculate($data);
        $availableDays = $accrual['available_days'];
        $isTermination = ($data['leave_transaction_type'] ?? '') === 'termination_leave_compensation';
        $requestedDays = SettlementMath::asDecimal($data['requested_leave_days'] ?? $data['days_to_compensate'] ?? ($isTermination ? $availableDays : 0));
        $daysToCompensate = SettlementMath::asDecimal($data['days_to_compensate'] ?? $requestedDays);
        if ($isTermination && (
            ! empty($data['last_return_from_leave_date'])
            || ! empty($data['last_settlement_date'])
            || ($data['leave_balance_method'] ?? '') === 'date_accrual'
        )) {
            // Full balance compensation is authoritative here, even if an old form sends five days.
            $requestedDays = $availableDays;
            $daysToCompensate = $availableDays;
        }

        if (SettlementMath::compare($availableDays, '0') < 0) {
            return [
                'available_days' => $availableDays,
                'requested_leave_days' => $requestedDays,
                'days_to_compensate' => $daysToCompensate,
                'remaining_balance' => $availableDays,
                'applicable_days' => '0',
                'validation_errors' => ['negative leave balance is not allowed'],
                'status' => 'blocked',
            ];
        }

        if (SettlementMath::compare($requestedDays, $availableDays) > 0 || SettlementMath::compare($daysToCompensate, $availableDays) > 0) {
            return [
                'available_days' => $availableDays,
                'requested_leave_days' => $requestedDays,
                'days_to_compensate' => $daysToCompensate,
                'remaining_balance' => $availableDays,
                'applicable_days' => '0',
                'validation_errors' => ['requested leave exceeds available balance'],
                'status' => 'blocked',
            ];
        }

        $applicableDays = $requestedDays;
        if (($data['leave_transaction_type'] ?? null) === 'termination_leave_compensation' && SettlementMath::compare($daysToCompensate, $availableDays) <= 0) {
            $applicableDays = $daysToCompensate;
        }

        $remainingBalance = SettlementMath::subtract($availableDays, $applicableDays);

        return [
            'accrual_details' => $accrual,
            'available_days' => $availableDays,
            'requested_leave_days' => $requestedDays,
            'days_to_compensate' => $daysToCompensate,
            'remaining_balance' => $remainingBalance,
            'applicable_days' => $applicableDays,
            'validation_errors' => [],
            'status' => 'valid',
        ];
    }
}
