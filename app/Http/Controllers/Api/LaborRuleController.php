<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaborRule;
use Illuminate\Http\Request;

class LaborRuleController extends Controller
{
    public function index()
    {
        return response()->json(LaborRule::latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $validated = $request->validate([
            'code' => ['required', 'string', 'unique:labor_rules,code'],
            'article_number' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'category' => ['required', 'string'],
            'description' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'source_name' => ['required', 'string'],
            'source_url' => ['nullable', 'url'],
            'version' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(LaborRule::create($validated), 201);
    }
}
