<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementItem extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'settlement_id',
        'type',
        'code',
        'name',
        'quantity',
        'rate',
        'amount',
        'calculation_details_json',
        'legal_rule_id',
    ];

    protected $casts = [
        'calculation_details_json' => 'array',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
