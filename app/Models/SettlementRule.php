<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'termination_type_id',
        'rule_code',
        'rule_name',
        'category',
        'condition_json',
        'calculation_method',
        'legal_rule_id',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'condition_json' => 'array',
    ];

    public function terminationType(): BelongsTo
    {
        return $this->belongsTo(TerminationType::class);
    }
}
