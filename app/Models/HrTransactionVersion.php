<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrTransactionVersion extends Model
{
    public $timestamps = false;

    protected $table = 'hr_transaction_versions';

    protected $fillable = [
        'hr_transaction_id',
        'version_number',
        'status',
        'snapshot_json',
        'created_by',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'hr_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
