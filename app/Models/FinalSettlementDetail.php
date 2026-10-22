<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalSettlementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'hr_transaction_id',
        'termination_type',
        'termination_date',
        'last_working_date',
        'hire_date',
        'service_years',
        'service_months',
        'service_days',
        'contract_type',
        'basic_salary',
        'gross_entitlements',
        'total_deductions',
        'net_settlement',
        'notes',
    ];

    protected $casts = [
        'termination_date' => 'date',
        'last_working_date' => 'date',
        'hire_date' => 'date',
        'basic_salary' => 'decimal:2',
        'gross_entitlements' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_settlement' => 'decimal:2',
    ];
}
