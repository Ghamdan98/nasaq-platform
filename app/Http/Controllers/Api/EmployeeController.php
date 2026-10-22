<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Employees\EmployeeData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('view_transactions') || EmployeeData::canManage($request->user()), 403);
        $request->validate(['search' => 'nullable|string|max:255', 'company' => 'nullable|string|max:150', 'status' => 'nullable|in:active,inactive,terminated', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = Employee::query();
        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($term, $request) {
                $q->where('employee_number', 'like', $term)->orWhere('full_name', 'like', $term);
                if (EmployeeData::canReadSensitive($request->user())) {
                    $q->orWhere('national_id_or_iqama', 'like', $term);
                }
            });
        }
        foreach (['company', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if (EmployeeData::canReadSensitive($request->user())) {
            $query->with('contracts');
        }
        $result = $query->orderBy('full_name')->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());
        $result->setCollection($result->getCollection()->map(fn ($employee) => EmployeeData::serialize($employee, $request->user())));

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        abort_unless(EmployeeData::canManage($request->user()), 403);
        $data = EmployeeData::normalize($request->all());
        $data['company'] ??= 'الشركة الرئيسية';
        $employee = Employee::create(Validator::make($data, EmployeeData::rules($data))->validate());
        $this->audit($request, $employee, 'employee_created');

        return response()->json(EmployeeData::serialize($employee->fresh(), $request->user()), 201);
    }

    public function show(Request $request, Employee $employee)
    {
        abort_unless($request->user()->hasPermission('view_transactions') || EmployeeData::canManage($request->user()), 403);
        if (EmployeeData::canReadSensitive($request->user())) {
            $employee->load('contracts');
        }

        return response()->json(EmployeeData::serialize($employee, $request->user()))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Employee $employee)
    {
        abort_unless(EmployeeData::canManage($request->user()), 403);
        $data = EmployeeData::normalize($request->all());
        $data['company'] ??= $employee->company;
        $data['employee_number'] ??= $employee->employee_number;
        $employee->update(Validator::make($data, EmployeeData::rules($data, $employee, true))->validate());
        $this->audit($request, $employee, 'employee_updated');

        return response()->json(EmployeeData::serialize($employee->fresh(), $request->user()));
    }

    public function destroy(Employee $employee)
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $employee->update(['status' => 'inactive']);

        return response()->json(['message' => 'تم تعطيل الموظف مع الاحتفاظ بسجلاته.']);
    }

    public function companies(Request $request)
    {
        abort_unless($request->user()->hasPermission('view_transactions') || EmployeeData::canManage($request->user()), 403);

        return response()->json(Employee::distinct()->orderBy('company')->pluck('company'));
    }

    private function audit(Request $request, Employee $employee, string $action): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'entity_type' => Employee::class, 'entity_id' => $employee->id, 'ip_address' => $request->ip()]);
    }
}
