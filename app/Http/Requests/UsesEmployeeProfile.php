<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Services\Employees\EmployeeData;
use Illuminate\Validation\ValidationException;

trait UsesEmployeeProfile
{
    protected function prepareForValidation(): void
    {
        // An estimate must never load or borrow an employee's authoritative profile.
        if ($this->input('calculation_mode') === 'estimate') {
            return;
        }
        if (! $this->filled('employee_id') || ! is_scalar($this->input('employee_id'))) {
            return;
        }
        $employee = Employee::find($this->input('employee_id'));
        if (! $employee || $employee->basic_salary === null) {
            return;
        }
        abort_unless(EmployeeData::canReadSensitive($this->user()), 403);
        if ($this->filled('employee_profile_version') && $this->input('employee_profile_version') !== hash('sha256', json_encode($employee->getAttributes()))) {
            throw ValidationException::withMessages(['employee_id' => 'تغير ملف الموظف. أعد اختيار الموظف واحتساب المخالصة قبل الحفظ.']);
        }
        // Authoritative source: salary edits belong in employee management, never in a settlement request.
        $this->merge(EmployeeData::settlementInput($employee));
    }
}
