<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveSettlementCalculationRequest;
use App\Models\AuditLog;
use App\Models\HrTransaction;
use App\Models\HrTransactionDeduction;
use App\Models\HrTransactionItem;
use App\Services\HRTransactions\HRTransactionEngine;
use App\Services\HRTransactions\TransactionWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrTransactionController extends Controller
{
    public function __construct(private readonly TransactionWorkflowService $workflow)
    {
    }

    public function calculate(Request $request)
    {
        $transactionType = $request->input('transaction_type', 'final_settlement');

        $request->validate(['transaction_type' => ['sometimes', 'in:final_settlement,leave_settlement']]);
        $form = $transactionType === 'leave_settlement'
            ? LeaveSettlementCalculationRequest::createFrom($request)
            : \App\Http\Requests\SettlementCalculationRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();
        $payload = $form->validated();
        $payload['transaction_type'] = $transactionType;

        return response()->json(\App\Http\Requests\CalculationContextInput::describe($payload, HRTransactionEngine::calculate($payload)));
    }

    public function index(Request $request)
    {
        $request->user()->can('view_transactions') || abort(403);
        $query = HrTransaction::with(['employee', 'contract', 'items', 'deductions', 'adjustments'])->whereNull('archived_at')->orderByDesc('transaction_date')->orderByDesc('id');

        if ($request->user()->isHr()) {
            $query->where('created_by', $request->user()->id);
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('review_status')) {
            $query->where('review_status', $request->review_status);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('employee')) {
            $query->whereHas('employee', function ($employeeQuery) use ($request) {
                $employeeQuery->where('full_name', 'like', '%'.$request->employee.'%')
                    ->orWhere('employee_number', 'like', '%'.$request->employee.'%');
            });
        }

        if ($request->filled('reference_number')) {
            $query->where('reference_number', 'like', '%'.$request->reference_number.'%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

        return response()->json($query->paginate(app(\App\Services\SystemSettingsService::class)->pageSize()));
    }

    public function show(HrTransaction $hrTransaction)
    {
        request()->user()->can('view_transactions') || abort(403);
        abort_if(request()->user()->isHr() && $hrTransaction->created_by !== request()->user()->id, 403);
        $transaction = $hrTransaction->load([
            'employee',
            'contract',
            'items',
            'deductions',
            'adjustments.adjustedBy',
            'statusHistory.changedBy',
            'versions.createdBy',
        ]);
        $auditTimeline = AuditLog::with('user')
            ->where('entity_type', HrTransaction::class)
            ->where('entity_id', $transaction->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'transaction' => $transaction,
            'employee_snapshot' => $transaction->employee_snapshot_json ?? ($transaction->employee ? $transaction->employee->toArray() : null),
            'contract_snapshot' => $transaction->contract_snapshot_json ?? ($transaction->contract ? $transaction->contract->toArray() : null),
            'salary_snapshot' => $transaction->salary_snapshot_json,
            'financial_items' => $transaction->items,
            'deductions' => $transaction->deductions,
            'manual_adjustments' => $transaction->adjustments,
            'status_history' => $transaction->statusHistory,
            'versions' => $transaction->versions,
            'audit_timeline' => $auditTimeline,
            'warnings' => $transaction->warnings_snapshot_json ?? [],
            'legal_references' => $transaction->legal_rules_snapshot_json ?? [],
            'calculated_totals' => [
                'gross_amount' => $transaction->calculation_snapshot_json['gross_entitlements']
                    ?? $transaction->calculation_snapshot_json['gross_leave_amount'] ?? 0,
                'total_deductions' => $transaction->calculation_snapshot_json['total_deductions'] ?? 0,
                'net_amount' => $transaction->calculation_snapshot_json['net_settlement']
                    ?? $transaction->calculation_snapshot_json['gross_leave_amount']
                    ?? 0,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->user()->can('create_transactions') || abort(403);
        $validated = $this->validateSaveInput($request, $request->input('transaction_type', 'final_settlement'));
        $calculation = HRTransactionEngine::calculate($validated);

        $transaction = DB::transaction(function () use ($request, $validated, $calculation) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($validated['employee_id']);
            $user = $request->user();
            $transaction = HrTransaction::create([
                'employee_id' => $validated['employee_id'] ?? null,
                'contract_id' => $validated['contract_id'] ?? null,
                'transaction_type' => $calculation['transaction_type'] ?? $validated['transaction_type'] ?? 'final_settlement',
                'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
                'reference_number' => $this->generateReferenceNumber($calculation['transaction_type'] ?? $validated['transaction_type'] ?? 'final_settlement', $validated['transaction_date'] ?? now()->toDateString()),
                'status' => 'calculated',
                'review_status' => $calculation['review_status'] ?? ($validated['review_status'] ?? 'valid'),
                'gross_amount' => $calculation['gross_entitlements'] ?? $calculation['gross_leave_amount'] ?? 0,
                'total_deductions' => $calculation['total_deductions'] ?? 0,
                'net_amount' => $calculation['net_settlement'] ?? ($calculation['gross_leave_amount'] ?? 0),
                'created_by' => $user?->id,
                'notes' => $validated['notes'] ?? null,
                'employee_snapshot_json' => $this->resolveEmployeeSnapshot($validated),
                'contract_snapshot_json' => $this->resolveContractSnapshot($validated),
                'salary_snapshot_json' => $calculation['salary_base'] ?? null,
                'input_snapshot_json' => $validated,
                'calculation_snapshot_json' => $calculation,
                'legal_rules_snapshot_json' => $calculation['legal_references'] ?? [],
                'warnings_snapshot_json' => $calculation['warnings'] ?? [],
            ]);

            $this->persistTransactionDetails($transaction, $validated, $calculation);
            app(\App\Services\Advances\AdvanceSettlementService::class)->reserve($transaction);
            $this->recalculateTransactionTotals($transaction);
            $this->logAudit($transaction, $user, 'transaction_created', null, $transaction->toArray());
            $this->workflow->recordCreated($transaction, $user);

            return $transaction->load(['employee', 'contract', 'items', 'deductions']);
        });

        return response()->json($transaction, 201);
    }

    public function update(Request $request, HrTransaction $hrTransaction)
    {
        $this->workflow->assertEditable($hrTransaction, $request->user());
        if ($request->has('status')) {
            throw ValidationException::withMessages(['status' => 'Status changes must use the dedicated workflow actions.']);
        }
        $validated = $this->validateSaveInput($request, $request->input('transaction_type', $hrTransaction->transaction_type));
        $validated['_advance_transaction_id'] = $hrTransaction->id;
        $calculation = HRTransactionEngine::calculate($validated);

        $transaction = DB::transaction(function () use ($request, $hrTransaction, $validated, $calculation) {
            app(\App\Services\Advances\AdvanceLedger::class)->lockEmployee($hrTransaction->employee_id);
            abort_unless((int) $validated['employee_id'] === (int) $hrTransaction->employee_id, 422, 'لا يمكن تغيير الموظف في مخالصة محفوظة. أنشئ مخالصة مستقلة.');
            $user = $request->user();
            $hrTransaction = HrTransaction::whereKey($hrTransaction->id)->lockForUpdate()->firstOrFail();
            $this->workflow->assertEditable($hrTransaction, $user);
            $oldValues = $hrTransaction->toArray();
            $hrTransaction->fill([
                'employee_id' => $validated['employee_id'] ?? $hrTransaction->employee_id,
                'contract_id' => $validated['contract_id'] ?? $hrTransaction->contract_id,
                'transaction_type' => $calculation['transaction_type'] ?? $validated['transaction_type'] ?? $hrTransaction->transaction_type,
                'transaction_date' => $validated['transaction_date'] ?? $hrTransaction->transaction_date,
                'status' => 'calculated',
                'review_status' => $calculation['review_status'] ?? ($validated['review_status'] ?? $hrTransaction->review_status),
                'notes' => $validated['notes'] ?? $hrTransaction->notes,
                'employee_snapshot_json' => $this->resolveEmployeeSnapshot($validated),
                'contract_snapshot_json' => $this->resolveContractSnapshot($validated),
                'salary_snapshot_json' => $calculation['salary_base'] ?? $hrTransaction->salary_snapshot_json,
                'input_snapshot_json' => $validated,
                'calculation_snapshot_json' => $calculation,
                'legal_rules_snapshot_json' => $calculation['legal_references'] ?? $hrTransaction->legal_rules_snapshot_json,
                'warnings_snapshot_json' => $calculation['warnings'] ?? $hrTransaction->warnings_snapshot_json,
            ]);

            if (empty($hrTransaction->reference_number)) {
                $hrTransaction->reference_number = $this->generateReferenceNumber(
                    $hrTransaction->transaction_type,
                    $validated['transaction_date'] ?? now()->toDateString(),
                );
            }

            $hrTransaction->gross_amount = $calculation['gross_entitlements'] ?? $calculation['gross_leave_amount'] ?? $hrTransaction->gross_amount;
            $hrTransaction->total_deductions = $calculation['total_deductions'] ?? 0;
            $hrTransaction->net_amount = $calculation['net_settlement'] ?? ($calculation['gross_leave_amount'] ?? $hrTransaction->net_amount);
            $hrTransaction->save();

            $this->persistTransactionDetails($hrTransaction, $validated, $calculation);
            app(\App\Services\Advances\AdvanceSettlementService::class)->reserve($hrTransaction);
            $this->recalculateTransactionTotals($hrTransaction);
            $this->logAudit($hrTransaction, $user, 'transaction_updated', $oldValues, $hrTransaction->fresh()->toArray());

            return $hrTransaction->load(['employee', 'contract', 'items', 'deductions']);
        });

        return response()->json($transaction);
    }

    public function adjustItem(Request $request, HrTransaction $hrTransaction, $item)
    {
        $request->user()->can('adjust_transaction_values') || abort(403);
        $validated = $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3'],
            'item_type' => ['nullable', 'in:entitlement,deduction'],
        ]);

        return DB::transaction(function () use ($request, $hrTransaction, $item, $validated) {
            $hrTransaction = HrTransaction::whereKey($hrTransaction->id)->lockForUpdate()->firstOrFail();
            $this->workflow->assertAdjustable($hrTransaction);
            $record = ($validated['item_type'] ?? 'entitlement') === 'deduction'
                ? $hrTransaction->deductions()->whereKey($item)->first()
                : $hrTransaction->items()->whereKey($item)->first();

            if (! $record) {
                abort(404, 'Transaction item not found.');
            }

            abort_if($record->code === 'employee_advances', 422, 'عدّل السلف من قرار السلف المرتبطة، وليس كتعديل مبلغ يدوي.');

            $oldApproved = (float) $record->approved_amount;
            $newApproved = (float) $validated['approved_amount'];
            $calculatedAmount = (float) $record->calculated_amount;
            $record->approved_amount = $newApproved;
            $record->difference_amount = $newApproved - $calculatedAmount;
            $record->was_manually_adjusted = $newApproved != $calculatedAmount;
            $record->adjustment_reason = $validated['reason'];
            $record->adjusted_by = $request->user()->id;
            $record->adjusted_at = now();
            $record->save();

            $hrTransaction->adjustments()->create([
                'item_type' => $record instanceof HrTransactionItem ? HrTransactionItem::class : HrTransactionDeduction::class,
                'item_id' => $record->id,
                'calculated_amount' => $calculatedAmount,
                'old_approved_amount' => $oldApproved,
                'new_approved_amount' => $newApproved,
                'difference_amount' => $newApproved - $calculatedAmount,
                'reason' => $validated['reason'],
                'adjusted_by' => $request->user()->id,
            ]);
            $this->logAudit($hrTransaction, $request->user(), 'item_adjusted', [
                'item_id' => $record->id,
                'old_approved_amount' => $oldApproved,
            ], [
                'item_id' => $record->id,
                'new_approved_amount' => $newApproved,
                'reason' => $validated['reason'],
            ]);
            $this->recalculateTransactionTotals($hrTransaction);

            return response()->json([
                'message' => 'Adjustment recorded successfully.',
                'item' => $record->fresh(),
                'transaction' => $hrTransaction->fresh(['items', 'deductions']),
            ]);
        });
    }

    public function revertAdjustment(Request $request, HrTransaction $hrTransaction, $item)
    {
        $request->user()->can('adjust_transaction_values') || abort(403);
        $validated = $request->validate([
            'item_type' => ['nullable', 'in:entitlement,deduction'],
        ]);
        return DB::transaction(function () use ($request, $hrTransaction, $item, $validated) {
            $hrTransaction = HrTransaction::whereKey($hrTransaction->id)->lockForUpdate()->firstOrFail();
            $this->workflow->assertAdjustable($hrTransaction);
            $record = ($validated['item_type'] ?? 'entitlement') === 'deduction'
                ? $hrTransaction->deductions()->whereKey($item)->first()
                : $hrTransaction->items()->whereKey($item)->first();

            if (! $record) {
                abort(404, 'Transaction item not found.');
            }
            abort_if($record->code === 'employee_advances', 422, 'Use the linked advance decision.');

            $oldApproved = (float) $record->approved_amount;
            $calculatedAmount = (float) $record->calculated_amount;
            $record->approved_amount = $calculatedAmount;
            $record->difference_amount = 0;
            $record->was_manually_adjusted = false;
            $record->adjustment_reason = 'Adjustment reverted';
            $record->adjusted_by = $request->user()->id;
            $record->adjusted_at = now();
            $record->save();

            $hrTransaction->adjustments()->create([
                'item_type' => $record instanceof HrTransactionItem ? HrTransactionItem::class : HrTransactionDeduction::class,
                'item_id' => $record->id,
                'calculated_amount' => $calculatedAmount,
                'old_approved_amount' => $oldApproved,
                'new_approved_amount' => $calculatedAmount,
                'difference_amount' => $calculatedAmount - $oldApproved,
                'reason' => 'Adjustment reverted',
                'adjusted_by' => $request->user()->id,
            ]);
            $this->logAudit($hrTransaction, $request->user(), 'adjustment_reverted', [
                'item_id' => $record->id,
                'old_approved_amount' => $oldApproved,
            ], [
                'item_id' => $record->id,
                'new_approved_amount' => $calculatedAmount,
                'reason' => 'Adjustment reverted',
            ]);
            $this->recalculateTransactionTotals($hrTransaction);

            return response()->json([
                'message' => 'Adjustment reverted successfully.',
                'item' => $record->fresh(),
                'transaction' => $hrTransaction->fresh(['items', 'deductions']),
            ]);
        });
    }

    public function submitReview(Request $request, HrTransaction $hrTransaction)
    {
        return response()->json($this->workflow->submit($hrTransaction, $request->user()));
    }

    public function approve(Request $request, HrTransaction $hrTransaction)
    {
        return response()->json($this->workflow->approve($hrTransaction, $request->user()));
    }

    public function reject(Request $request, HrTransaction $hrTransaction)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        return response()->json($this->workflow->reject($hrTransaction, $request->user(), $validated['reason']));
    }

    public function requestChanges(Request $request, HrTransaction $hrTransaction)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        return response()->json($this->workflow->requestChanges($hrTransaction, $request->user(), $validated['reason']));
    }

    public function cancel(Request $request, HrTransaction $hrTransaction)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        return response()->json($this->workflow->cancel($hrTransaction, $request->user(), $validated['reason']));
    }

    public function reopen(Request $request, HrTransaction $hrTransaction)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        return response()->json($this->workflow->reopen($hrTransaction, $request->user(), $validated['reason']));
    }

    protected function validateSaveInput(Request $request, string $transactionType): array
    {
        if ($request->input('calculation_mode') === 'estimate') {
            throw ValidationException::withMessages(['calculation_mode' => 'التصفية التقديرية للمعاينة فقط. اختر موظفًا مسجلًا لإنشاء معاملة مالية.']);
        }
        $validated = $request->validate([
            'transaction_type' => ['required', 'in:final_settlement,leave_settlement'],
            'employee_id' => ['required', 'exists:employees,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'transaction_date' => ['sometimes', 'required', 'date'],
            'status' => ['prohibited'],
            'review_status' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'created_by' => ['nullable', 'exists:users,id'],
        ]);

        $form = $transactionType === 'leave_settlement'
            ? LeaveSettlementCalculationRequest::createFrom($request)
            : \App\Http\Requests\SettlementCalculationRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        return array_merge($validated, $form->validated());
    }

    protected function generateReferenceNumber(string $transactionType, string $date): string
    {
        $prefix = app(\App\Services\SystemSettingsService::class)->get($transactionType === 'leave_settlement' ? 'documents.leave_prefix' : 'documents.settlement_prefix');
        $year = Carbon::parse($date)->format('Y');
        $pattern = $prefix.'-'.$year.'-';

        $latest = HrTransaction::where('reference_number', 'like', $pattern.'%')
            ->orderByRaw('CAST(SUBSTR(reference_number, '.(strlen($pattern) + 1).') AS INTEGER) DESC')
            ->value('reference_number');

        $nextSequence = 1;
        if ($latest) {
            preg_match('/'.$prefix.'-'.$year.'-(\d{6})$/', $latest, $matches);
            $nextSequence = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        }

        return $prefix.'-'.$year.'-'.str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
    }

    protected function resolveEmployeeSnapshot(array $payload): ?array
    {
        if (empty($payload['employee_id'])) {
            return null;
        }

        $employee = \App\Models\Employee::find($payload['employee_id']);

        if (! $employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'job_title' => $employee->job_title,
            'department' => $employee->department,
            'hire_date' => $employee->hire_date,
            'status' => $employee->status,
            'company' => $employee->company,
            'nationality' => $employee->nationality,
            'last_leave_settlement_date' => $employee->last_leave_settlement_date,
        ];
    }

    protected function resolveContractSnapshot(array $payload): ?array
    {
        if (empty($payload['contract_id'])) {
            return null;
        }

        $contract = \App\Models\Contract::find($payload['contract_id']);

        if (! $contract) {
            return null;
        }

        return $contract->toArray();
    }

    protected function persistTransactionDetails(HrTransaction $transaction, array $validated, array $calculation): void
    {
        $transaction->items()->delete();
        $transaction->deductions()->delete();

        foreach ($calculation['entitlements'] ?? [] as $item) {
            $transaction->items()->create([
                'type' => 'entitlement',
                'code' => $item['code'] ?? 'entitlement',
                'name' => $item['name'] ?? ($item['name_ar'] ?? 'Entitlement'),
                'name_ar' => $item['name_ar'] ?? ($item['name'] ?? 'Entitlement'),
                'quantity' => $item['quantity'] ?? null,
                'rate' => $item['rate'] ?? null,
                'calculated_amount' => $item['amount'] ?? 0,
                'approved_amount' => $item['amount'] ?? 0,
                'difference_amount' => 0,
                'was_manually_adjusted' => false,
                'legal_reference_json' => $item['legal_references'] ?? null,
                'calculation_details_json' => $item['calculation_details'] ?? $item['calculation'] ?? null,
            ]);
        }

        if (($calculation['transaction_type'] ?? null) === 'leave_settlement' && ! empty($calculation['gross_leave_amount'])) {
            $transaction->items()->create([
                'type' => 'leave_settlement',
                'code' => 'gross_leave_amount',
                'name' => 'Leave Settlement',
                'name_ar' => 'بدل الإجازة',
                'quantity' => $calculation['requested_leave_days'] ?? null,
                'rate' => $calculation['daily_rate'] ?? null,
                'calculated_amount' => $calculation['gross_leave_amount'],
                'approved_amount' => $calculation['gross_leave_amount'],
                'difference_amount' => 0,
                'was_manually_adjusted' => false,
                'calculation_details_json' => [
                    'requested_leave_days' => $calculation['requested_leave_days'] ?? null,
                    'days_to_compensate' => $calculation['days_to_compensate'] ?? null,
                    'balance_source' => $calculation['balance_source'] ?? null,
                ],
            ]);
        }

        foreach ($calculation['deductions'] ?? [] as $deduction) {
            $record = $transaction->deductions()->create([
                'code' => $deduction['code'] ?? 'deduction',
                'name' => $deduction['name'] ?? ($deduction['description'] ?? 'Deduction'),
                'name_ar' => $deduction['name_ar'] ?? ($deduction['description'] ?? 'Deduction'),
                'quantity' => $deduction['quantity'] ?? null,
                'rate' => $deduction['rate'] ?? null,
                'calculated_amount' => $deduction['amount'] ?? 0,
                'approved_amount' => $deduction['amount'] ?? 0,
                'difference_amount' => 0,
                'was_manually_adjusted' => false,
                'calculation_details_json' => $deduction,
            ]);
        }

        if (($calculation['transaction_type'] ?? null) === 'final_settlement') {
            $transaction->finalSettlementDetail()->updateOrCreate([
                'hr_transaction_id' => $transaction->id,
            ], [
                'termination_type' => $validated['termination_type'] ?? $calculation['termination']['type'] ?? null,
                'termination_date' => $validated['termination_date'] ?? $calculation['termination']['date'] ?? null,
                'last_working_date' => $validated['last_working_date'] ?? null,
                'hire_date' => $validated['hire_date'] ?? null,
                'service_years' => $calculation['service_period']['years'] ?? 0,
                'service_months' => $calculation['service_period']['months'] ?? 0,
                'service_days' => $calculation['service_period']['days'] ?? 0,
                'contract_type' => $validated['contract_type'] ?? null,
                'basic_salary' => $calculation['salary_base']['basic_salary'] ?? 0,
                'gross_entitlements' => $calculation['gross_entitlements'] ?? 0,
                'total_deductions' => $calculation['total_deductions'] ?? 0,
                'net_settlement' => $calculation['net_settlement'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        if (($calculation['transaction_type'] ?? null) === 'leave_settlement') {
            $transaction->leaveSettlementDetail()->updateOrCreate([
                'hr_transaction_id' => $transaction->id,
            ], [
                'leave_transaction_type' => $calculation['leave_transaction_type'] ?? $validated['leave_transaction_type'] ?? null,
                'leave_period_from' => $calculation['accrual_details']['accrual_details']['period_from']
                    ?? $validated['last_return_from_leave_date'] ?? $validated['leave_period_from'] ?? null,
                'leave_period_to' => $validated['last_working_date'] ?? $validated['leave_period_to'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'opening_balance' => $validated['opening_balance'] ?? 0,
                'accrued_days' => $calculation['accrual_details']['accrued_days'] ?? $validated['accrued_days'] ?? 0,
                'used_days' => $validated['used_days'] ?? 0,
                'adjustment_days' => $validated['adjustment_days'] ?? 0,
                'available_days' => $calculation['available_days'] ?? 0,
                'requested_leave_days' => $calculation['requested_leave_days'] ?? 0,
                'days_to_compensate' => $calculation['days_to_compensate'] ?? 0,
                'balance_source' => $calculation['balance_source'] ?? ($validated['balance_source'] ?? null),
                'calculation_wage' => $calculation['calculation_wage'] ?? 0,
                'daily_rate' => $calculation['daily_rate'] ?? 0,
                'gross_leave_amount' => $calculation['gross_leave_amount'] ?? 0,
                'remaining_balance' => $calculation['remaining_balance'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);
        }
    }

    protected function recalculateTransactionTotals(HrTransaction $transaction): void
    {
        $grossAmount = \App\Services\Settlement\SettlementMath::roundMoney($transaction->items()->sum('approved_amount'));
        $totalDeductions = \App\Services\Settlement\SettlementMath::roundMoney($transaction->deductions()->sum('approved_amount'));
        $netAmount = \App\Services\Settlement\SettlementMath::subtract($grossAmount, $totalDeductions);

        $transaction->update([
            'gross_amount' => $grossAmount,
            'total_deductions' => $totalDeductions,
            'net_amount' => $netAmount,
        ]);
    }

    protected function logAudit(HrTransaction $transaction, $user, string $action, mixed $oldValues, mixed $newValues): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => HrTransaction::class,
            'entity_id' => $transaction->id,
            'old_values_json' => $oldValues,
            'new_values_json' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }
}
