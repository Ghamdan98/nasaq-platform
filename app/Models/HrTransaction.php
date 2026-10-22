<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrTransaction extends Model
{
    use HasFactory;

    public function advanceAllocations() { return $this->hasMany(SettlementAdvanceAllocation::class); }
    public function payments() { return $this->hasMany(SettlementPayment::class); }

    protected $table = 'hr_transactions';

    protected $fillable = [
        'financial_stage', 'advance_decision_json', 'financial_review_json', 'advance_legal_review_json',
        'financial_reviewed_by', 'financial_reviewed_at', 'posting_key', 'posted_by', 'posted_at', 'paid_at',
        'employee_id',
        'contract_id',
        'transaction_type',
        'transaction_date',
        'reference_number',
        'status',
        'review_status',
        'gross_amount',
        'total_deductions',
        'net_amount',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'version_number',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'requested_changes_by',
        'requested_changes_at',
        'requested_changes_reason',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'employee_snapshot_json',
        'contract_snapshot_json',
        'salary_snapshot_json',
        'input_snapshot_json',
        'calculation_snapshot_json',
        'legal_rules_snapshot_json',
        'warnings_snapshot_json',
    ];

    protected $casts = [
        'advance_decision_json' => 'array', 'financial_review_json' => 'array', 'advance_legal_review_json' => 'array',
        'financial_reviewed_at' => 'datetime', 'posted_at' => 'datetime', 'paid_at' => 'datetime',
        'transaction_date' => 'date',
        'reviewed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'requested_changes_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'gross_amount' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'employee_snapshot_json' => 'array',
        'contract_snapshot_json' => 'array',
        'salary_snapshot_json' => 'array',
        'input_snapshot_json' => 'array',
        'calculation_snapshot_json' => 'array',
        'legal_rules_snapshot_json' => 'array',
        'warnings_snapshot_json' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrTransactionItem::class, 'hr_transaction_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(HrTransactionDeduction::class, 'hr_transaction_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TransactionAdjustment::class, 'hr_transaction_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(HrTransactionStatusHistory::class, 'hr_transaction_id')->orderBy('created_at');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrTransactionVersion::class, 'hr_transaction_id')->orderBy('version_number');
    }

    public function finalSettlementDetail(): HasOne
    {
        return $this->hasOne(FinalSettlementDetail::class, 'hr_transaction_id');
    }

    public function leaveSettlementDetail(): HasOne
    {
        return $this->hasOne(LeaveSettlementDetail::class, 'hr_transaction_id');
    }
}
