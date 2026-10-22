<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SettlementRule;
use Illuminate\Http\Request;

class SettlementRuleController extends Controller
{
    public function index()
    {
        return response()->json(SettlementRule::with('terminationType')->latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $validated = $request->validate([
            'termination_type_id' => ['nullable', 'exists:termination_types,id'],
            'rule_code' => ['required', 'string', 'unique:settlement_rules,rule_code'],
            'rule_name' => ['required', 'string'],
            'category' => ['required', 'string'],
            'condition_json' => ['nullable', 'array'],
            'calculation_method' => ['required', 'string'],
            'legal_rule_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(SettlementRule::create($validated), 201);
    }
}
