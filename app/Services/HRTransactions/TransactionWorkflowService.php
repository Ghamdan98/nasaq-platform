<?php

namespace App\Services\HRTransactions;

use App\Models\AuditLog;
use App\Models\HrTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransactionWorkflowService
{
    public function recordCreated(HrTransaction $transaction, User $user): void
    {
        $this->recordTransition($transaction, null, $transaction->status, null, $user, 'transaction_created');
    }

    public function submit(HrTransaction $transaction, User $user): HrTransaction
    {
        Gate::forUser($user)->authorize('submit_transactions');
        abort_if($user->isHr() && $transaction->created_by !== $user->id, 403);

        return DB::transaction(function () use ($transaction, $user) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['calculated', 'changes_requested']);
            if (empty($locked->calculation_snapshot_json) || $locked->items()->count() === 0) {
                $this->invalid('لا يمكن إرسال المعاملة قبل اكتمال بيانات الحساب.');
            }
            $this->assertNotBlocked($locked);
            app(\App\Services\Advances\AdvanceSettlementService::class)->assertDecision($locked);
            $locked->financial_stage = 'pending_financial_review';

            $locked->submitted_by = $user->id;
            $locked->submitted_at = now();
            $this->transition($locked, 'pending_review', null, $user, 'submitted_for_review');

            return $locked->fresh();
        });
    }

    public function approve(HrTransaction $transaction, User $user): HrTransaction
    {
        Gate::forUser($user)->authorize('approve_transactions');

        return DB::transaction(function () use ($transaction, $user) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['pending_review']);
            app(\App\Services\Advances\AdvanceSettlementService::class)->beforeApprove($locked);
            if ($locked->review_status === 'blocked') {
                $this->invalid('لا يمكن اعتماد المعاملة لأنها تحتوي على مانع نظامي.');
            }
            if ($locked->review_status === 'requires_legal_review') {
                Gate::forUser($user)->authorize('approve_legal_review_transaction');
            }

            $locked->approved_by = $user->id;
            $locked->financial_stage = 'approved';
            $locked->approved_at = now();
            $locked->reviewed_by = $user->id;
            $locked->reviewed_at = now();
            $this->transition($locked, 'approved', null, $user, 'transaction_approved');

            return $locked->fresh();
        });
    }

    public function reject(HrTransaction $transaction, User $user, string $reason): HrTransaction
    {
        Gate::forUser($user)->authorize('reject_transactions');

        return DB::transaction(function () use ($transaction, $user, $reason) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['pending_review']);
            $locked->rejected_by = $user->id;
            app(\App\Services\Advances\AdvanceSettlementService::class)->release($locked);
            $locked->financial_stage = 'draft';
            $locked->rejected_at = now();
            $locked->rejection_reason = $reason;
            $this->transition($locked, 'rejected', $reason, $user, 'transaction_rejected');

            return $locked->fresh();
        });
    }

    public function requestChanges(HrTransaction $transaction, User $user, string $reason): HrTransaction
    {
        Gate::forUser($user)->authorize('request_transaction_changes');

        return DB::transaction(function () use ($transaction, $user, $reason) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['pending_review']);
            $locked->requested_changes_by = $user->id;
            $locked->requested_changes_at = now();
            $locked->requested_changes_reason = $reason;
            $this->transition($locked, 'changes_requested', $reason, $user, 'changes_requested');

            return $locked->fresh();
        });
    }

    public function cancel(HrTransaction $transaction, User $user, string $reason): HrTransaction
    {
        Gate::forUser($user)->authorize('cancel_transactions');

        return DB::transaction(function () use ($transaction, $user, $reason) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['draft', 'calculated', 'changes_requested']);
            $locked->cancelled_by = $user->id;
            app(\App\Services\Advances\AdvanceSettlementService::class)->release($locked);
            $locked->financial_stage = 'cancelled';
            $locked->cancelled_at = now();
            $locked->cancel_reason = $reason;
            $this->transition($locked, 'cancelled', $reason, $user, 'transaction_cancelled');

            return $locked->fresh();
        });
    }

    public function reopen(HrTransaction $transaction, User $user, string $reason): HrTransaction
    {
        Gate::forUser($user)->authorize('reopen_approved_transaction');

        return DB::transaction(function () use ($transaction, $user, $reason) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($transaction->employee_id);
            $locked = $this->lock($transaction);
            $this->requireStatus($locked, ['approved']);
            if (in_array($locked->financial_stage, ['posted', 'paid'], true)) $this->invalid('لا يمكن إعادة فتح مخالصة مرحلة أو مدفوعة؛ استخدم العكس المالي.');
            $currentVersion = max(1, (int) $locked->version_number);
            $snapshot = [
                'transaction' => $locked->toArray(),
                'items' => $locked->items()->get()->toArray(),
                'deductions' => $locked->deductions()->get()->toArray(),
                'adjustments' => $locked->adjustments()->get()->toArray(),
            ];

            $locked->versions()->create([
                'version_number' => $currentVersion,
                'status' => 'approved',
                'snapshot_json' => $snapshot,
                'created_by' => $user->id,
                'reason' => $reason,
            ]);
            $this->audit($locked, $user, 'transaction_version_created', null, [
                'version_number' => $currentVersion,
                'status' => 'approved',
                'reason' => $reason,
            ]);

            $locked->version_number = $currentVersion + 1;
            app(\App\Services\Advances\AdvanceSettlementService::class)->release($locked);
            $locked->financial_stage = 'draft';
            $locked->financial_reviewed_at = null;
            $locked->approved_by = null;
            $locked->approved_at = null;
            $locked->reviewed_by = null;
            $locked->reviewed_at = null;
            $this->transition($locked, 'calculated', $reason, $user, 'transaction_reopened');

            return $locked->fresh();
        });
    }

    public function assertEditable(HrTransaction $transaction, User $user): void
    {
        Gate::forUser($user)->authorize('edit_transactions');
        $this->requireStatus($transaction, ['draft', 'calculated', 'changes_requested']);

        if ($user->role === 'hr' && (int) $transaction->created_by !== (int) $user->id) {
            abort(403);
        }
    }

    public function assertAdjustable(HrTransaction $transaction): void
    {
        $this->requireStatus($transaction, ['draft', 'calculated', 'changes_requested']);
    }

    private function lock(HrTransaction $transaction): HrTransaction
    {
        return HrTransaction::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertNotBlocked(HrTransaction $transaction): void
    {
        if ($transaction->review_status === 'blocked'
            || ($transaction->calculation_snapshot_json['status'] ?? null) === 'blocked') {
            $this->invalid('لا يمكن إرسال المعاملة لأنها تحتوي على مانع نظامي.');
        }
    }

    private function requireStatus(HrTransaction $transaction, array $allowed): void
    {
        if (! in_array($transaction->status, $allowed, true)) {
            $this->invalid('لا يسمح بإجراء هذا الانتقال من الحالة الحالية للمعاملة.');
        }
    }

    private function transition(
        HrTransaction $transaction,
        string $toStatus,
        ?string $reason,
        User $user,
        string $auditAction,
    ): void {
        $fromStatus = $transaction->status;
        $transaction->status = $toStatus;
        $transaction->save();
        $this->recordTransition($transaction, $fromStatus, $toStatus, $reason, $user, $auditAction);
    }

    private function recordTransition(
        HrTransaction $transaction,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason,
        User $user,
        string $auditAction,
    ): void {
        $transaction->statusHistory()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'changed_by' => $user->id,
        ]);
        $this->audit($transaction, $user, $auditAction, ['status' => $fromStatus], [
            'status' => $toStatus,
            'reason' => $reason,
        ]);
    }

    private function audit(HrTransaction $transaction, User $user, string $action, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => HrTransaction::class,
            'entity_id' => $transaction->id,
            'old_values_json' => $old,
            'new_values_json' => $new,
            'ip_address' => request()->ip(),
        ]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['status' => $message]);
    }
}
