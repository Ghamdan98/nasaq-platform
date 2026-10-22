<?php

namespace App\Enums;

enum HRTransactionType: string
{
    case FINAL_SETTLEMENT = 'final_settlement';
    case LEAVE_SETTLEMENT = 'leave_settlement';

    public function label(): string
    {
        return match ($this) {
            self::FINAL_SETTLEMENT => 'مخالصة نهاية خدمة',
            self::LEAVE_SETTLEMENT => 'تصفية إجازة',
        };
    }
}
