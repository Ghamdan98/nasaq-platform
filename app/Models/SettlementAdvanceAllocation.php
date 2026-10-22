<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementAdvanceAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2', 'posted_at' => 'datetime'];
    }

    public function advance()
    {
        return $this->belongsTo(EmployeeAdvance::class, 'advance_id');
    }

    public function settlement()
    {
        return $this->belongsTo(HrTransaction::class, 'hr_transaction_id');
    }
}
