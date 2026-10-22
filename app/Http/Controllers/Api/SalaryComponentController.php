<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryComponent;
use Illuminate\Http\Request;

class SalaryComponentController extends Controller
{
    public function index()
    {
        abort_unless(\App\Services\Employees\EmployeeData::canReadSensitive(request()->user()), 403);
        return response()->json(SalaryComponent::with('employee')->latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless(\App\Services\Employees\EmployeeData::canManage($request->user()), 403);
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'name' => ['required', 'string'],
            'type' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'include_in_settlement' => ['required', 'boolean'],
            'include_in_end_of_service' => ['required', 'boolean'],
        ]);

        return response()->json(SalaryComponent::create($validated), 201);
    }
}
