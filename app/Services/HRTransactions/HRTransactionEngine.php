<?php

namespace App\Services\HRTransactions;

use App\Enums\HRTransactionType;
use App\Services\Settlement\FinalSettlementEngine;
use App\Services\Settlement\LeaveSettlementEngine;
use InvalidArgumentException;

class HRTransactionEngine
{
    public static function calculate(array $data): array
    {
        $transactionType = $data['transaction_type'] ?? HRTransactionType::FINAL_SETTLEMENT->value;

        $result = match ($transactionType) {
            HRTransactionType::FINAL_SETTLEMENT->value => FinalSettlementEngine::calculate($data),
            HRTransactionType::LEAVE_SETTLEMENT->value => LeaveSettlementEngine::calculate($data),
            default => throw new InvalidArgumentException('Unsupported HR transaction type: '.$transactionType),
        };
        return app(\App\Services\Advances\AdvanceSettlementService::class)->preview($data, $result);
    }
}
