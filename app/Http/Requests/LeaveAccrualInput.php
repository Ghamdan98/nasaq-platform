<?php

namespace App\Http\Requests;

class LeaveAccrualInput
{
    public static function rules(): array
    {
        return [
            ...CalculationContextInput::rules(),
            'financial_process_required' => ['sometimes', 'boolean'],
            'advance_decision' => ['nullable', 'array'],
            'advance_decision.choice' => ['nullable', 'in:full,partial,none,hold'],
            'advance_decision.reason' => ['nullable', 'string', 'max:2000'],
            'advance_decision.remaining_debt_reason' => ['nullable', 'string', 'max:2000'],
            'advance_decision.allocations' => ['nullable', 'array', 'max:200'],
            'advance_decision.allocations.*.advance_id' => ['required', 'integer', 'distinct'],
            'advance_decision.allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'employee_profile_version' => ['nullable', 'string', 'size:64'],
            'leave_balance_method' => ['sometimes', 'in:manual,date_accrual'],
            'last_return_from_leave_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'last_settlement_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'previously_settled_days' => ['nullable', 'numeric', 'min:0'],
            'deduct_settled_days_from_accrual_period' => ['sometimes', 'boolean'],
            'contractual_annual_leave_days' => ['nullable', 'numeric', 'min:21', 'max:365'],
        ];
    }
}
