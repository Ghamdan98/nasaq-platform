<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettlementCalculationRequest;
use App\Models\Settlement;
use App\Services\Settlement\SettlementEngine;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function calculate(SettlementCalculationRequest $request)
    {
        $validated = $request->validated();
        $result = SettlementEngine::calculate($validated);
        $result = app(\App\Services\Advances\AdvanceSettlementService::class)->preview($validated, $result);

        return response()->json(\App\Http\Requests\CalculationContextInput::describe($validated, $result));
    }

    public function index()
    {
        request()->user()->can('view_transactions') || abort(403);
        $query = Settlement::with(['employee', 'contract'])->latest();
        if (request()->user()->isHr()) {
            $query->where('created_by', request()->user()->id);
        }
        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        // This legacy endpoint cannot persist calculation snapshots or enforce review.
        return response()->json(['message' => 'Use POST /api/hr-transactions to save a recalculated transaction with snapshots and workflow.'], 410);

    }
}
