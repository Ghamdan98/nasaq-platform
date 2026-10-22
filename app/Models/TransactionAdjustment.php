<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionAdjustment extends Model
{
    use HasFactory;

    protected $table = 'transaction_adjustments';

    public $timestamps = true;

    protected $fillable = [
        'hr_transaction_id',
        'item_type',
        'item_id',
        'calculated_amount',
        'old_approved_amount',
        'new_approved_amount',
        'difference_amount',
        'reason',
        'adjusted_by',
    ];

    protected $casts = [
        'calculated_amount' => 'decimal:2',
        'old_approved_amount' => 'decimal:2',
        'new_approved_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
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
