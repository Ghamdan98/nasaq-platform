<?php

namespace App\Services\Advances;

use App\Models\AdvanceTransaction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\User;
use App\Services\Settlement\SettlementMath as Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceLedger
{
    public function lockEmployee(int $id): Employee
    {
        return Employee::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function balance(EmployeeAdvance $advance): string
    {
        $balance = '0';
        foreach ($advance->transactions()->get(['amount', 'direction']) as $entry) {
            $balance = Money::add($balance, Money::multiply($entry->amount, (string) $entry->direction));
        }

        return Money::roundMoney($balance);
    }

    public function reserved(EmployeeAdvance $advance, ?int $except = null): string
    {
        return Money::roundMoney($advance->allocations()->where('status', 'reserved')->when($except, fn ($q) => $q->where('hr_transaction_id', '!=', $except))->sum('allocated_amount'));
    }

    public function summary(int $employeeId, ?int $except = null): array
    {
        $items = [];
        $total = '0';
        $available = '0';
        $reserved = '0';
        foreach (EmployeeAdvance::where('employee_id', $employeeId)->orderBy('id')->get() as $advance) {
            if (! $advance->disbursed_at || $advance->status === 'cancelled') {
                continue;
            }
            $balance = $this->balance($advance);
            if (Money::compare($balance, '0') <= 0) {
                continue;
            }
            $held = $this->reserved($advance, $except);
            $free = Money::maxValue(Money::subtract($balance, $held), '0');
            $items[] = ['id' => $advance->id, 'reference_number' => $advance->reference_number, 'company' => $advance->company,
                'balance' => $balance, 'reserved' => $held, 'available' => $free, 'status' => $advance->status,
                'is_disputed' => $advance->is_disputed, 'evidence_reference' => $advance->evidence_reference, 'approval_reference' => $advance->approval_reference];
            $total = Money::add($total, $balance);
            $available = Money::add($available, $free);
            $reserved = Money::add($reserved, $held);
        }

        return ['items' => $items, 'total_balance' => $total, 'available_balance' => $available, 'reserved_balance' => $reserved,
            'fingerprint' => hash('sha256', json_encode($items))];
    }

    public function serialize(EmployeeAdvance $advance): array
    {
        $balance = $this->balance($advance);
        $reserved = $this->reserved($advance);

        return array_merge($advance->toArray(), ['balance' => $balance, 'reserved_balance' => $reserved,
            'available_balance' => Money::maxValue(Money::subtract($balance, $reserved), '0'),
            'effective_status' => $advance->disbursed_at && in_array($advance->status, ['active', 'suspended']) && Money::compare($balance, '0') === 0 ? 'settled' : $advance->status]);
    }

    public function audit(User $user, string $action, string $entity, int $id, array $details = []): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'new_values_json' => $details, 'ip_address' => request()->ip()]);
    }

    public function approve(EmployeeAdvance $advance, User $user, string $reference): EmployeeAdvance
    {
        abort_unless($user->hasPermission('approve_advances'), 403);

        return DB::transaction(function () use ($advance, $user, $reference) {
            $this->lockEmployee($advance->employee_id);
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advance->id);
            $this->ensure($advance->status === 'requested', 'السلفة ليست طلبًا قابلًا للاعتماد.');
            $advance->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'approval_reference' => $reference]);
            $this->audit($user, 'advance_approved', EmployeeAdvance::class, $advance->id, ['reference' => $reference]);

            return $advance->fresh();
        });
    }

    public function movement(EmployeeAdvance $advance, User $user, string $type, array $data): AdvanceTransaction
    {
        abort_unless($user->hasPermission('post_advance_movements'), 403);

        return DB::transaction(function () use ($advance, $user, $type, $data) {
            $this->lockEmployee($advance->employee_id);
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advance->id);
            $amount = $type === 'disbursement' ? $advance->original_amount : Money::roundMoney($data['amount']);
            if ($existing = AdvanceTransaction::where('idempotency_key', $data['idempotency_key'])->first()) {
                $this->ensure($existing->advance_id === $advance->id && $existing->transaction_type === $type && Money::compare($existing->amount, $amount) === 0 && $existing->reference_number === $data['reference_number'], 'مفتاح العملية مستخدم ببيانات مختلفة.');
                $this->ensure($existing->transaction_date === $data['transaction_date'] && (! $existing->payment_method || $existing->payment_method === $data['method']), 'مفتاح العملية مستخدم بتاريخ أو طريقة دفع مختلفة.');

                return $existing;
            }
            $this->ensure(! AdvanceTransaction::where('advance_id', $advance->id)->where('transaction_type', $type)->where('reference_number', $data['reference_number'])->exists(), 'سبق تسجيل مستند الحركة لهذه السلفة، حتى لو تغير مفتاح الإرسال.');
            if ($type === 'disbursement') {
                $this->ensure($advance->status === 'approved' && ! $advance->disbursed_at, 'يجب اعتماد السلفة قبل صرفها، ولا يمكن صرفها مرتين.');
                $advance->update(['status' => 'active', 'disbursed_at' => $data['transaction_date'], 'payment_method' => $data['method'], 'evidence_reference' => $data['reference_number']]);
            } else {
                $this->ensure($advance->disbursed_at && $advance->status !== 'cancelled', 'السلفة غير مصروفة.');
                $this->ensure($data['transaction_date'] >= $advance->disbursed_at, 'تاريخ السداد لا يسبق تاريخ الصرف.');
                $this->ensure(Money::compare($amount, $this->balance($advance)) <= 0, 'السداد يتجاوز رصيد السلفة.');
            }
            $entry = AdvanceTransaction::create(['advance_id' => $advance->id, 'transaction_type' => $type, 'amount' => $amount,
                'direction' => $type === 'disbursement' ? 1 : -1, 'transaction_date' => $data['transaction_date'], 'reference_number' => $data['reference_number'],
                'idempotency_key' => $data['idempotency_key'], 'payment_method' => $data['method'], 'notes' => $data['notes'] ?? null, 'created_by' => $user->id]);
            $this->audit($user, 'advance_'.$type, EmployeeAdvance::class, $advance->id, ['entry_id' => $entry->id, 'amount' => $amount]);

            return $entry;
        });
    }

    public function reverse(EmployeeAdvance $advance, AdvanceTransaction $entry, User $user, array $data): AdvanceTransaction
    {
        abort_unless($user->hasPermission('reverse_advance_movements'), 403);

        return DB::transaction(function () use ($advance, $entry, $user, $data) {
            $this->lockEmployee($advance->employee_id);
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advance->id);
            $this->ensure($entry->advance_id === $advance->id && ! $entry->hr_transaction_id && $entry->transaction_type !== 'reversal', 'اعكس حركات المخالصات من مسار إلغاء المخالصة، ولا تعكس مستندًا عكسيًا.');
            if ($existing = AdvanceTransaction::where('reversal_of_id', $entry->id)->first()) {
                return $existing;
            }
            if ($entry->direction > 0) {
                $this->ensure(Money::compare($this->balance($advance), $entry->amount) === 0 && Money::compare($this->reserved($advance), '0') === 0, 'لا يمكن عكس الصرف قبل عكس السداد والتخصيصات المرتبطة.');
                $advance->update(['status' => 'cancelled']);
            }
            $reversal = AdvanceTransaction::create(['advance_id' => $advance->id, 'transaction_type' => 'reversal', 'amount' => $entry->amount,
                'direction' => -$entry->direction, 'transaction_date' => $data['transaction_date'], 'reference_number' => $data['reference_number'],
                'idempotency_key' => 'reverse:'.$entry->id, 'reversal_of_id' => $entry->id, 'notes' => $data['notes'], 'created_by' => $user->id]);
            $this->audit($user, 'advance_reversed', EmployeeAdvance::class, $advance->id, ['entry_id' => $reversal->id, 'original_entry_id' => $entry->id, 'reason' => $data['notes']]);

            return $reversal;
        });
    }

    public function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['advances' => $message]);
        }
    }
}
