<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAdvance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:2', 'monthly_installment' => 'decimal:2', 'repayment_plan' => 'array', 'is_disputed' => 'boolean', 'approved_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function transactions()
    {
        return $this->hasMany(AdvanceTransaction::class, 'advance_id');
    }

    public function allocations()
    {
        return $this->hasMany(SettlementAdvanceAllocation::class, 'advance_id');
    }

    public function documents()
    {
        return $this->hasMany(AdvanceDocument::class, 'advance_id');
    }
}
