<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'code',
        'name',
        'quantity',
        'amount',
        'notes',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
