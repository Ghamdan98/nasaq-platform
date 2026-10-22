<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Services\Settlement\SettlementMath;
use Illuminate\Validation\Rule;

class EmployeeData
{
    public const MONEY = ['basic_salary', 'allowance_1', 'transportation_allowance', 'work_nature_allowance', 'subsistence_allowance', 'food_allowance', 'additional_fixed_allowance', 'other_allowances', 'housing_allowance'];

    public const ALIASES = ['employee_code' => 'employee_number', 'identity_number' => 'national_id_or_iqama', 'profession' => 'job_title', 'organizational_unit' => 'department', 'joining_date' => 'hire_date', 'employment_status' => 'status'];

    public const HEADERS = ['employee_number' => 'كود الموظف', 'full_name' => 'اسم الموظف', 'company' => 'الشركة', 'department' => 'الوحدة التنظيمية', 'job_title' => 'المهنة', 'nationality' => 'الجنسية', 'national_id_or_iqama' => 'رقم الهوية', 'hire_date' => 'تاريخ المباشرة', 'last_leave_settlement_date' => 'تاريخ آخر تصفية إجازة', 'basic_salary' => 'الراتب الأساسي', 'allowance_1' => 'بدل 1', 'transportation_allowance' => 'بدل الانتقال', 'work_nature_allowance' => 'بدل طبيعة العمل', 'subsistence_allowance' => 'بدل الإعاشة', 'food_allowance' => 'بدل الطعام', 'additional_fixed_allowance' => 'بدل إضافي ثابت', 'other_allowances' => 'بدلات أخرى', 'housing_allowance' => 'بدل السكن', 'status' => 'الحالة الوظيفية'];

    public static function canManage(?User $user): bool
    {
        return $user && $user->hasPermission('manage_employees');
    }

    public static function canReadSensitive(?User $user): bool
    {
        return self::canManage($user) || ($user && $user->hasPermission('view_employee_sensitive'));
    }

    public static function normalize(array $data): array
    {
        foreach (self::ALIASES as $alias => $field) {
            if (array_key_exists($alias, $data)) {
                $data[$field] = $data[$alias];
            }
        }
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        return $data;
    }

    public static function rules(array $data, ?Employee $employee = null, bool $partial = false): array
    {
        $company = $data['company'] ?? $employee?->company ?? 'الشركة الرئيسية';
        $required = $partial ? 'sometimes' : 'required';
        $rules = [
            'employee_number' => [$required, 'required', 'string', 'max:100', Rule::unique('employees')->where('company', $company)->ignore($employee?->id)],
            'company' => [$required, 'required', 'string', 'max:150'],
            'full_name' => [$required, 'required', 'string', 'max:255'],
            'hire_date' => [$required, 'required', 'date_format:Y-m-d'],
            'status' => [$required, 'required', 'in:active,inactive,terminated'],
            'last_leave_settlement_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($data['hire_date'] ?? $employee?->hire_date ?? '1900-01-01')],
            'salary_inclusion' => ['sometimes', 'array'],
        ];
        foreach (['national_id_or_iqama', 'job_title', 'department', 'nationality'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }
        foreach (self::MONEY as $field) {
            $rules[$field] = [$field === 'basic_salary' ? $required : 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'];
            foreach (['eos', 'leave', 'notice'] as $basis) {
                $rules["salary_inclusion.$field.$basis"] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }

    public static function total(Employee $employee): string
    {
        return SettlementMath::add(...array_map(fn ($key) => $employee->$key ?? 0, self::MONEY));
    }

    public static function serialize(Employee $employee, ?User $user): array
    {
        $data = $employee->toArray();
        foreach (self::ALIASES as $alias => $field) {
            $data[$alias] = $data[$field] ?? null;
        }
        if (! self::canReadSensitive($user)) {
            foreach (array_merge(self::MONEY, ['salary_inclusion', 'national_id_or_iqama', 'identity_number', 'contracts', 'salary_components']) as $key) {
                unset($data[$key]);
            }
        } else {
            foreach (array_merge(self::MONEY, ['national_id_or_iqama', 'salary_inclusion']) as $key) {
                $data[$key] = $employee->$key;
            }
            $data['identity_number'] = $employee->national_id_or_iqama;
            $data['employee_profile_version'] = hash('sha256', json_encode($employee->getAttributes()));
            $data['total_salary'] = self::total($employee);
            $data['settlement_input'] = self::settlementInput($employee);
        }

        return $data;
    }

    public static function settlementInput(Employee $employee): array
    {
        if ($employee->basic_salary === null) {
            return [];
        }
        $map = ['transportation_allowance' => 'transport_allowance', 'additional_fixed_allowance' => 'fixed_allowances'];
        $components = [];
        foreach (self::MONEY as $field) {
            $flags = $employee->salary_inclusion[$field] ?? [];
            $components[] = ['code' => $map[$field] ?? $field, 'name' => self::HEADERS[$field], 'amount' => $employee->$field ?? '0',
                'include_in_eos' => $flags['eos'] ?? true,
                'include_in_leave' => $flags['leave'] ?? true,
                'include_in_notice' => $flags['notice'] ?? true,
                'reason' => 'حسب قواعد إدراج مكونات الراتب المعتمدة في ملف الموظف'];
        }

        return ['hire_date' => $employee->hire_date, 'last_settlement_date' => $employee->last_leave_settlement_date,
            'basic_salary' => $employee->basic_salary, 'housing_allowance' => $employee->housing_allowance ?? '0',
            'transport_allowance' => $employee->transportation_allowance ?? '0', 'fixed_allowances' => $employee->additional_fixed_allowance ?? '0',
            'other_allowances' => $employee->other_allowances ?? '0', 'commissions' => '0', 'salary_components' => $components,
            'monthly_wage' => null];
    }
}
