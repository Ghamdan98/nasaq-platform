<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveAccrualInput;
use App\Models\HrTransaction;
use App\Services\Advances\AdvanceLedger;
use App\Services\Advances\AdvanceSettlementService;
use Illuminate\Http\Request;

class SettlementFinanceController extends Controller
{
    public function __construct(private AdvanceSettlementService $finance, private AdvanceLedger $ledger) {}

    private function access(Request $request, HrTransaction $settlement): void
    {
        abort_unless($request->user()->hasPermission('view_transactions'), 403);
        abort_if($request->user()->isHr() && $settlement->created_by !== $request->user()->id, 403);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('view_transactions') && collect(['review_financial_settlements', 'review_advance_legal', 'post_settlements', 'pay_settlements', 'reverse_settlements'])->contains(fn ($permission) => $user->hasPermission($permission)), 403);
        $request->validate(['stage' => 'nullable|in:draft,pending_financial_review,pending_legal_review,pending_approval,approved,posted,paid,cancelled']);

        return HrTransaction::with('employee:id,full_name,employee_number,company')->whereNull('archived_at')->when($user->isHr(), fn ($q) => $q->where('created_by', $user->id))->when($request->filled('stage'), fn ($q) => $q->where('financial_stage', $request->input('stage')))->latest()->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());
    }

    public function show(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);

        return response()->json(['settlement' => $settlement, 'advances' => $this->ledger->summary($settlement->employee_id, $settlement->id), 'allocations' => $settlement->advanceAllocations()->with('advance:id,reference_number')->get(), 'payments' => $settlement->payments()->get()])->header('Cache-Control', 'no-store');
    }

    public function decision(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $rules = array_filter(LeaveAccrualInput::rules(), fn ($key) => str_starts_with($key, 'advance_decision'), ARRAY_FILTER_USE_KEY);
        $rules['advance_decision'] = ['required', 'array'];
        $rules['advance_decision.choice'] = ['required', 'in:full,partial,none,hold'];
        $data = $request->validate($rules);

        return response()->json($this->finance->changeDecision($settlement, $request->user(), $data['advance_decision']));
    }

    public function review(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $data = $request->validate(['deduction_source' => 'required|in:non_wage,wages,mixed', 'deductible_limit' => 'required|numeric|decimal:0,2|min:0',
            'debt_verified' => 'required|boolean', 'documents_verified' => 'required|boolean', 'protected_rights_reviewed' => 'required|boolean', 'written_consent' => 'sometimes|boolean', 'reason' => 'required|string|min:3|max:2000']);

        return response()->json($this->finance->review($settlement, $request->user(), $data));
    }

    public function legal(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $data = $request->validate(['approved_limit' => 'required|numeric|decimal:0,2|min:0', 'authority_reference' => 'required|string|min:3|max:255', 'reason' => 'required|string|min:10|max:4000']);

        return response()->json($this->finance->legalReview($settlement, $request->user(), $data));
    }

    public function post(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $data = $request->validate(['idempotency_key' => 'required|string|max:150']);

        return response()->json($this->finance->post($settlement, $request->user(), $data['idempotency_key']));
    }

    public function pay(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $data = $request->validate(['reference_number' => 'required|string|max:150', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'method' => 'required|in:bank,cash,no_cash', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->finance->pay($settlement, $request->user(), $data));
    }

    public function cancel(Request $request, HrTransaction $settlement)
    {
        $this->access($request, $settlement);
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000', 'payment_reversal_reference' => 'nullable|string|max:150']);

        return response()->json($this->finance->cancel($settlement, $request->user(), $data));
    }
}
