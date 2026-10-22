<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrTransactionStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'hr_transaction_status_history';

    protected $fillable = [
        'hr_transaction_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'hr_transaction_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
