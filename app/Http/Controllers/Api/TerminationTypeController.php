<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TerminationType;
use Illuminate\Http\Request;

class TerminationTypeController extends Controller
{
    public function index()
    {
        return response()->json(TerminationType::latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $validated = $request->validate([
            'code' => ['required', 'string', 'unique:termination_types,code'],
            'name_ar' => ['required', 'string'],
            'name_en' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(TerminationType::create($validated), 201);
    }
}
