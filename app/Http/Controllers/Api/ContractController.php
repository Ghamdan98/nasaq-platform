<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index()
    {
        abort_unless(\App\Services\Employees\EmployeeData::canReadSensitive(request()->user()), 403);
        return response()->json(Contract::with('employee')->latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless(\App\Services\Employees\EmployeeData::canManage($request->user()), 403);
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'contract_type' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'fixed_allowances' => ['nullable', 'numeric', 'min:0'],
            'commissions' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'in:active,expired,terminated'],
        ]);

        return response()->json(Contract::create($validated), 201);
    }
}
