<?php

namespace App\Services\Settlement;

use App\Services\Legal\LegalRuleEngine;

class FinalSettlementEngine
{
    public static function calculate(array $data): array
    {
        $result = SettlementEngine::calculate($data);
        $legal = LegalRuleEngine::evaluateFinalSettlement($data, $result);

        $warnings = array_merge($result['warnings'] ?? [], $legal['warnings'] ?? []);
        $reviewStatus = $legal['review_status'] ?? $result['review_status'] ?? 'valid';
        if ($reviewStatus === 'valid' && ! empty($warnings)) {
            $reviewStatus = 'warning';
        }

        $result['warnings'] = $warnings;
        $result['review_status'] = $reviewStatus;
        $result['transaction_type'] = 'final_settlement';

        return $result;
    }
}
