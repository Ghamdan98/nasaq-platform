<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrTransactionItem extends Model
{
    use HasFactory;

    protected $table = 'hr_transaction_items';

    protected $fillable = [
        'hr_transaction_id',
        'type',
        'code',
        'name',
        'name_ar',
        'quantity',
        'rate',
        'calculated_amount',
        'approved_amount',
        'difference_amount',
        'was_manually_adjusted',
        'adjustment_reason',
        'adjusted_by',
        'adjusted_at',
        'legal_reference_json',
        'calculation_details_json',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'calculated_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'was_manually_adjusted' => 'boolean',
        'adjusted_at' => 'datetime',
        'legal_reference_json' => 'array',
        'calculation_details_json' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'hr_transaction_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
