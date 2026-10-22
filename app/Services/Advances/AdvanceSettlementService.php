<?php

namespace App\Services\Advances;

use App\Models\AdvanceTransaction;
use App\Models\EmployeeAdvance;
use App\Models\HrTransaction;
use App\Models\SettlementAdvanceAllocation;
use App\Models\User;
use App\Services\Settlement\SettlementMath as Money;
use Illuminate\Support\Facades\DB;

class AdvanceSettlementService
{
    public function __construct(private AdvanceLedger $ledger, private AdvanceDeductionPolicy $policy) {}

    public function preview(array $data, array $result): array
    {
        if (empty($data['employee_id'])) {
            return $result;
        }
        $summary = $this->ledger->summary((int) $data['employee_id'], $data['_advance_transaction_id'] ?? null);
        if ($summary['items']) {
            $this->ledger->ensure(Money::compare($data['employee_loan'] ?? 0, '0') === 0 && Money::compare($data['advance'] ?? 0, '0') === 0, 'استخدم قرار السلف المرتبطة بدل إدخال قرض أو سلفة يدويًا حتى لا يتكرر الخصم.');
        }
        $decision = $data['advance_decision'] ?? [];
        $choice = $decision['choice'] ?? '';
        $deductions = array_values(array_filter($result['deductions'] ?? [], fn ($item) => ($item['code'] ?? '') !== 'employee_advances'));
        $otherDeductions = Money::add(...array_column($deductions, 'amount'));
        $gross = $result['gross_entitlements'] ?? $result['gross_leave_amount'] ?? '0';
        $capacity = Money::maxValue(Money::subtract($gross, $otherDeductions), '0');
        $allocations = [];
        $total = '0';
        if ($choice === 'full') {
            $remaining = $capacity;
            foreach ($summary['items'] as $advance) {
                $amount = Money::minValue($advance['available'], $remaining);
                if (Money::compare($amount, '0') > 0) {
                    $allocations[] = ['advance_id' => $advance['id'], 'amount' => $amount];
                }
                $remaining = Money::subtract($remaining, $amount);
                $total = Money::add($total, $amount);
            }
        } elseif ($choice === 'partial') {
            $items = collect($summary['items'])->keyBy('id');
            $seen = [];
            foreach ($decision['allocations'] ?? [] as $allocation) {
                $id = $allocation['advance_id'];
                $amount = Money::roundMoney($allocation['amount']);
                $this->ledger->ensure(! isset($seen[$id]) && isset($items[$id]), 'سلفة غير متاحة لهذا الموظف أو مكررة في القرار.');
                $this->ledger->ensure(Money::compare($amount, '0') > 0 && Money::compare($amount, $items[$id]['available']) <= 0, 'التخصيص يتجاوز رصيد السلفة المتاح.');
                $seen[$id] = true;
                $allocations[] = ['advance_id' => (int) $id, 'amount' => $amount];
                $total = Money::add($total, $amount);
            }
            $this->ledger->ensure($allocations && Money::compare($total, $capacity) <= 0, 'حدد سلفة ومبلغًا جزئيًا لا يتجاوز المستحقات القابلة للحسم.');
        }
        if (Money::compare($total, '0') > 0) {
            $deductions[] = ['code' => 'employee_advances', 'name_ar' => 'السلف المرتبطة بالمخالصة', 'amount' => $total, 'quantity' => 1,
                'rate' => $total, 'status' => 'requires_review', 'calculation' => 'تخصيص مقترح؛ لا يُعد سدادًا إلا بعد المراجعة والترحيل المالي.', 'calculation_details' => ['allocations' => $allocations]];
        }
        $result['deductions'] = $deductions;
        $result['total_deductions'] = Money::add($otherDeductions, $total);
        $result['net_settlement'] = Money::subtract($gross, $result['total_deductions']);
        $result['advance_summary'] = array_merge($summary, ['choice' => $choice, 'allocations' => $allocations, 'proposed_deduction' => $total,
            'deductible_capacity' => $capacity, 'remaining_debt' => Money::subtract($summary['total_balance'], $total), 'decision_required' => count($summary['items']) > 0]);

        return $result;
    }

    public function reserve(HrTransaction $settlement): void
    {
        $this->ledger->lockEmployee($settlement->employee_id);
        $preview = $settlement->calculation_snapshot_json['advance_summary'] ?? null;
        $current = $this->ledger->summary($settlement->employee_id, $settlement->id);
        if ($preview) {
            $this->ledger->ensure($preview['fingerprint'] === $current['fingerprint'], 'تغيرت أرصدة السلف منذ الحساب. أعد احتساب المخالصة.');
        }
        $this->release($settlement);
        foreach ($preview['allocations'] ?? [] as $allocation) {
            SettlementAdvanceAllocation::updateOrCreate(
                ['hr_transaction_id' => $settlement->id, 'advance_id' => $allocation['advance_id']],
                ['allocated_amount' => $allocation['amount'], 'status' => 'reserved', 'approved_by' => null, 'posted_at' => null],
            );
        }
        $settlement->update(['advance_decision_json' => $settlement->input_snapshot_json['advance_decision'] ?? null,
            'financial_stage' => 'draft', 'financial_review_json' => null, 'advance_legal_review_json' => null,
            'financial_reviewed_by' => null, 'financial_reviewed_at' => null]);
    }

    public function release(HrTransaction $settlement): void
    {
        $settlement->advanceAllocations()->where('status', 'reserved')->update(['status' => 'released']);
    }

    public function assertDecision(HrTransaction $settlement): void
    {
        $summary = $this->ledger->summary($settlement->employee_id, $settlement->id);
        $decision = $settlement->advance_decision_json ?? [];
        if (! $summary['items'] && empty($decision['choice'])) {
            return;
        }
        $this->ledger->ensure(in_array($decision['choice'] ?? '', ['full', 'partial', 'none', 'hold'], true), 'يجب اتخاذ قرار صريح لمعالجة السلف قبل إرسال المخالصة.');
        if (in_array($decision['choice'], ['partial', 'none', 'hold'])) {
            $this->ledger->ensure(mb_strlen(trim($decision['reason'] ?? '')) >= 3, 'يلزم توثيق سبب الخصم الجزئي أو عدم الخصم أو التعليق.');
        }
        $allocated = $settlement->advanceAllocations()->where('status', 'reserved')->sum('allocated_amount');
        if (Money::compare($summary['total_balance'], $allocated) > 0 && $decision['choice'] !== 'hold') {
            $this->ledger->ensure(mb_strlen(trim($decision['remaining_debt_reason'] ?? '')) >= 3, 'وثق قرار إبقاء المديونية المتبقية؛ لا تعتبر مسددة أو مشطوبة.');
        }
        $this->assertBalances($settlement);
    }

    public function assertBalances(HrTransaction $settlement): void
    {
        $summary = collect($this->ledger->summary($settlement->employee_id, $settlement->id)['items'])->keyBy('id');
        $total = '0';
        foreach ($settlement->advanceAllocations()->where('status', 'reserved')->get() as $allocation) {
            $this->ledger->ensure(isset($summary[$allocation->advance_id]) && Money::compare($allocation->allocated_amount, $summary[$allocation->advance_id]['available']) <= 0, 'تغير رصيد سلفة مخصصة. حدّث قرار السلف ثم أعد المراجعة.');
            $total = Money::add($total, $allocation->allocated_amount);
        }
        $deduction = $settlement->deductions()->where('code', 'employee_advances')->sum('approved_amount');
        $this->ledger->ensure(Money::compare($total, $deduction) === 0, 'تخصيصات السلف لا تطابق خصم المخالصة. أعد حفظ القرار.');
        $this->ledger->ensure(Money::compare($total, Money::maxValue(Money::subtract($settlement->gross_amount, $settlement->deductions()->where('code', '!=', 'employee_advances')->sum('approved_amount')), '0')) <= 0, 'خصم السلف يتجاوز المستحقات القابلة للصرف.');
    }

    private function fingerprint(HrTransaction $settlement): string
    {
        return hash('sha256', json_encode([$this->ledger->summary($settlement->employee_id, $settlement->id)['fingerprint'],
            $settlement->advance_decision_json, $settlement->gross_amount, $settlement->total_deductions,
            $settlement->deductions()->orderBy('id')->get(['code', 'approved_amount'])->toArray()]));
    }

    public function review(HrTransaction $settlement, User $user, array $review): HrTransaction
    {
        abort_unless($user->hasPermission('review_financial_settlements'), 403);

        return DB::transaction(function () use ($settlement, $user, $review) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            $this->ledger->ensure($settlement->status === 'pending_review', 'أرسل المخالصة للمراجعة أولًا.');
            $this->assertDecision($settlement);
            $this->ledger->ensure(($settlement->advance_decision_json['choice'] ?? '') !== 'hold', 'المخالصة معلقة بقرار مالي. حدّث القرار قبل اعتماد المراجعة.');
            $policy = $this->policy->evaluate($settlement, $this->ledger->summary($settlement->employee_id, $settlement->id), $review);
            $settlement->update(['financial_reviewed_by' => $user->id, 'financial_reviewed_at' => now(),
                'financial_review_json' => array_merge($review, ['policy' => $policy, 'fingerprint' => $this->fingerprint($settlement)]), 'advance_legal_review_json' => null,
                'financial_stage' => $policy['status'] === 'clear' ? 'pending_approval' : 'pending_legal_review']);
            $this->ledger->audit($user, 'settlement_financial_reviewed', HrTransaction::class, $settlement->id, ['policy' => $policy, 'reason' => $review['reason']]);
            $settlement->statusHistory()->create(['from_status' => 'pending_financial_review', 'to_status' => $settlement->financial_stage, 'reason' => $review['reason'], 'changed_by' => $user->id]);

            return $settlement->fresh();
        });
    }

    public function legalReview(HrTransaction $settlement, User $user, array $decision): HrTransaction
    {
        abort_unless($user->hasPermission('review_advance_legal'), 403);

        return DB::transaction(function () use ($settlement, $user, $decision) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            $this->ledger->ensure($settlement->financial_stage === 'pending_legal_review', 'المعاملة ليست بانتظار المراجعة القانونية.');
            $this->assertFreshReview($settlement);
            $this->assertBalances($settlement);
            $amount = $settlement->deductions()->where('code', 'employee_advances')->sum('approved_amount');
            $this->ledger->ensure(Money::compare($decision['approved_limit'], $amount) >= 0, 'الحد القانوني المعتمد أقل من التخصيص. عدّل قرار السلف أولًا.');
            $settlement->update(['advance_legal_review_json' => array_merge($decision, ['reviewed_by' => $user->id, 'reviewed_at' => now()->toIso8601String()]), 'financial_stage' => 'pending_approval']);
            $this->ledger->audit($user, 'settlement_advance_legal_reviewed', HrTransaction::class, $settlement->id, $decision);
            $settlement->statusHistory()->create(['from_status' => 'pending_legal_review', 'to_status' => 'pending_approval', 'reason' => $decision['reason'], 'changed_by' => $user->id]);

            return $settlement->fresh();
        });
    }

    public function assertFreshReview(HrTransaction $settlement): void
    {
        $this->ledger->ensure($settlement->financial_reviewed_at && ($settlement->financial_review_json['fingerprint'] ?? '') === $this->fingerprint($settlement), 'المراجعة المالية مفقودة أو تغيرت الأرصدة بعدها. أعد المراجعة المالية.');
    }

    public function beforeApprove(HrTransaction $settlement): void
    {
        $this->assertDecision($settlement);
        if ($this->ledger->summary($settlement->employee_id, $settlement->id)['items'] || $settlement->advance_decision_json || $settlement->financial_reviewed_at || ($settlement->input_snapshot_json['financial_process_required'] ?? false)) {
            $this->assertFreshReview($settlement);
            $this->ledger->ensure($settlement->financial_stage === 'pending_approval', 'أكمل المراجعة المالية والقانونية أولًا.');
        }
    }

    public function changeDecision(HrTransaction $settlement, User $user, array $decision): HrTransaction
    {
        abort_unless($user->hasPermission('edit_transactions') || $user->hasPermission('review_financial_settlements'), 403);
        abort_if($user->isHr() && $settlement->created_by !== $user->id, 403);

        return DB::transaction(function () use ($settlement, $user, $decision) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            $this->ledger->ensure(in_array($settlement->status, ['calculated', 'changes_requested', 'pending_review']), 'لا يمكن تغيير القرار بعد الاعتماد.');
            $snapshot = $settlement->calculation_snapshot_json;
            $snapshot['gross_entitlements'] = $settlement->gross_amount;
            $snapshot['deductions'] = $settlement->deductions()->where('code', '!=', 'employee_advances')->get()->map(fn ($item) => ['code' => $item->code, 'amount' => $item->approved_amount])->all();
            $input = array_merge($settlement->input_snapshot_json ?? [], ['employee_id' => $settlement->employee_id, 'advance_decision' => $decision, '_advance_transaction_id' => $settlement->id]);
            $result = $this->preview($input, $snapshot);
            $settlement->deductions()->where('code', 'employee_advances')->delete();
            foreach ($result['deductions'] as $item) {
                if ($item['code'] === 'employee_advances') {
                    $settlement->deductions()->create(['code' => 'employee_advances', 'name' => 'السلف المرتبطة', 'name_ar' => 'السلف المرتبطة', 'calculated_amount' => $item['amount'], 'approved_amount' => $item['amount'], 'difference_amount' => 0, 'was_manually_adjusted' => false, 'calculation_details_json' => $item['calculation_details']]);
                }
            }
            $oldStatus = $settlement->status;
            $settlement->update(['status' => 'calculated', 'input_snapshot_json' => $input, 'calculation_snapshot_json' => $result, 'total_deductions' => $result['total_deductions'], 'net_amount' => $result['net_settlement']]);
            $this->reserve($settlement);
            $settlement->statusHistory()->create(['from_status' => $oldStatus, 'to_status' => 'calculated', 'reason' => 'تحديث قرار السلف وإلغاء المراجعة السابقة', 'changed_by' => $user->id]);
            $this->ledger->audit($user, 'settlement_advance_decision_changed', HrTransaction::class, $settlement->id, $decision);

            return $settlement->fresh();
        });
    }

    public function post(HrTransaction $settlement, User $user, string $key): HrTransaction
    {
        abort_unless($user->hasPermission('post_settlements'), 403);

        return DB::transaction(function () use ($settlement, $user, $key) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            if (in_array($settlement->financial_stage, ['posted', 'paid'])) {
                $this->ledger->ensure($settlement->posting_key === $key, 'سبق ترحيل المخالصة بمفتاح مختلف.');

                return $settlement;
            }
            $this->ledger->ensure($settlement->status === 'approved', 'يجب اعتماد المخالصة قبل الترحيل.');
            $this->ledger->ensure(! HrTransaction::where('posting_key', $key)->whereKeyNot($settlement->id)->exists(), 'مفتاح الترحيل مستخدم لمخالصة أخرى.');
            $this->assertDecision($settlement);
            $this->assertFreshReview($settlement);
            $this->ledger->ensure($settlement->financial_stage === 'approved', 'حالة المراجعة لا تسمح بالترحيل.');
            $this->ledger->ensure(Money::compare($settlement->net_amount, '0') >= 0, 'لا يمكن ترحيل صافي صرف سالب.');
            foreach ($settlement->advanceAllocations()->where('status', 'reserved')->orderBy('advance_id')->get() as $allocation) {
                $advance = EmployeeAdvance::lockForUpdate()->findOrFail($allocation->advance_id);
                $this->ledger->ensure(Money::compare($allocation->allocated_amount, $this->ledger->balance($advance)) <= 0, 'تغير الرصيد قبل الترحيل.');
                AdvanceTransaction::create(['advance_id' => $advance->id, 'transaction_type' => 'settlement', 'amount' => $allocation->allocated_amount, 'direction' => -1,
                    'transaction_date' => now()->toDateString(), 'reference_number' => $settlement->reference_number, 'idempotency_key' => 'allocation:'.$allocation->id,
                    'hr_transaction_id' => $settlement->id, 'created_by' => $user->id]);
                $allocation->update(['status' => 'posted', 'approved_by' => $settlement->approved_by, 'posted_at' => now()]);
            }
            $settlement->update(['financial_stage' => 'posted', 'posting_key' => $key, 'posted_by' => $user->id, 'posted_at' => now()]);
            $this->event($settlement, $user, 'settlement_posted', 'posted', ['posting_key' => $key]);

            return $settlement->fresh();
        });
    }

    public function pay(HrTransaction $settlement, User $user, array $data): HrTransaction
    {
        abort_unless($user->hasPermission('pay_settlements'), 403);

        return DB::transaction(function () use ($settlement, $user, $data) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->financial_stage === 'paid') {
                $this->ledger->ensure($settlement->payments()->where('reference_number', $data['reference_number'])->exists(), 'سبق توثيق دفع المخالصة.');

                return $settlement;
            }
            $this->ledger->ensure($settlement->financial_stage === 'posted', 'يجب الترحيل المالي قبل توثيق الصرف.');
            $this->ledger->ensure(($data['method'] === 'no_cash') === (Money::compare($settlement->net_amount, '0') === 0), 'استخدم دون صرف نقدي للصافي الصفري فقط، وحوالة أو نقدًا للصافي الموجب.');
            $settlement->payments()->create(['amount' => $settlement->net_amount, 'reference_number' => $data['reference_number'], 'payment_date' => $data['payment_date'], 'method' => $data['method'], 'notes' => $data['notes'] ?? null, 'created_by' => $user->id]);
            $settlement->update(['financial_stage' => 'paid', 'paid_at' => now()]);
            $this->event($settlement, $user, 'settlement_paid', 'paid', $data);

            return $settlement->fresh();
        });
    }

    public function cancel(HrTransaction $settlement, User $user, array $data): HrTransaction
    {
        abort_unless($user->hasPermission('reverse_settlements'), 403);

        return DB::transaction(function () use ($settlement, $user, $data) {
            $this->ledger->lockEmployee($settlement->employee_id);
            $settlement = HrTransaction::lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->financial_stage === 'cancelled') {
                return $settlement;
            }
            if ($settlement->financial_stage === 'paid') {
                $this->ledger->ensure(! empty($data['payment_reversal_reference']), 'وثق مستند عكس الصرف أو استرداده قبل إلغاء مخالصة مدفوعة.');
                $payment = $settlement->payments()->whereNull('reversal_of_id')->firstOrFail();
                $settlement->payments()->create(['amount' => $payment->amount, 'reference_number' => $data['payment_reversal_reference'], 'payment_date' => now()->toDateString(), 'method' => 'reversal', 'reversal_of_id' => $payment->id, 'notes' => $data['reason'], 'created_by' => $user->id]);
            }
            foreach ($settlement->advanceAllocations()->where('status', 'posted')->get() as $allocation) {
                $original = AdvanceTransaction::where('idempotency_key', 'allocation:'.$allocation->id)->firstOrFail();
                AdvanceTransaction::firstOrCreate(['reversal_of_id' => $original->id], ['advance_id' => $allocation->advance_id, 'transaction_type' => 'reversal', 'amount' => $original->amount,
                    'direction' => 1, 'transaction_date' => now()->toDateString(), 'reference_number' => 'REV-'.$settlement->reference_number, 'idempotency_key' => 'reverse-allocation:'.$allocation->id,
                    'hr_transaction_id' => $settlement->id, 'notes' => $data['reason'], 'created_by' => $user->id]);
                $allocation->update(['status' => 'reversed']);
            }
            $this->release($settlement);
            $old = $settlement->status;
            $settlement->update(['status' => 'cancelled', 'financial_stage' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $data['reason']]);
            $this->event($settlement, $user, 'settlement_financial_cancelled', 'cancelled', $data, $old);

            return $settlement->fresh();
        });
    }

    private function event(HrTransaction $settlement, User $user, string $action, string $stage, array $details, ?string $old = null): void
    {
        $settlement->statusHistory()->create(['from_status' => $old ?? $settlement->status, 'to_status' => $stage, 'reason' => $details['reason'] ?? $action, 'changed_by' => $user->id]);
        $this->ledger->audit($user, $action, HrTransaction::class, $settlement->id, $details);
    }
}
