<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveSettlementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'hr_transaction_id',
        'leave_transaction_type',
        'leave_period_from',
        'leave_period_to',
        'leave_start_date',
        'leave_end_date',
        'opening_balance',
        'accrued_days',
        'used_days',
        'adjustment_days',
        'available_days',
        'requested_leave_days',
        'days_to_compensate',
        'balance_source',
        'calculation_wage',
        'daily_rate',
        'gross_leave_amount',
        'remaining_balance',
        'notes',
    ];

    protected $casts = [
        'leave_period_from' => 'date',
        'leave_period_to' => 'date',
        'leave_start_date' => 'date',
        'leave_end_date' => 'date',
        'opening_balance' => 'decimal:2',
        'accrued_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'adjustment_days' => 'decimal:2',
        'available_days' => 'decimal:2',
        'requested_leave_days' => 'decimal:2',
        'days_to_compensate' => 'decimal:2',
        'calculation_wage' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'gross_leave_amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
    ];
}
