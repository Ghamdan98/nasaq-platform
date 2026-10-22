<?php

namespace App\Enums;

enum LeaveTransactionType: string
{
    case ANNUAL_LEAVE_PAY = 'annual_leave_pay';
    case TERMINATION_LEAVE_COMPENSATION = 'termination_leave_compensation';
    case ACTIVE_SERVICE_CASH_ENCASHMENT = 'active_service_cash_encashment';

    public function label(): string
    {
        return match ($this) {
            self::ANNUAL_LEAVE_PAY => 'تصفية / أجر إجازة',
            self::TERMINATION_LEAVE_COMPENSATION => 'تعويض رصيد الإجازة عند انتهاء الخدمة',
            self::ACTIVE_SERVICE_CASH_ENCASHMENT => 'استبدال رصيد الإجازة بمقابل نقدي أثناء الخدمة',
        };
    }
}
