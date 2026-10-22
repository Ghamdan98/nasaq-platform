<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'contract_id',
        'termination_type_id',
        'termination_date',
        'last_working_date',
        'resignation_date',
        'notice_completed',
        'notes',
        'gross_entitlements',
        'total_deductions',
        'net_settlement',
        'status',
        'created_by',
        'reviewed_by',
        'reviewed_at',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(SettlementDeduction::class);
    }
}
