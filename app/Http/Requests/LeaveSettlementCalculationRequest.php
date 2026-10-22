<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveSettlementCalculationRequest extends FormRequest
{
    use UsesEmployeeProfile;
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...LeaveAccrualInput::rules(),
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'fixed_allowances' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'commissions' => ['nullable', 'numeric', 'min:0'],
            'salary_components' => ['nullable', 'array'],
            'salary_components.*.code' => ['required', 'string'],
            'salary_components.*.name' => ['nullable', 'string'],
            'salary_components.*.amount' => ['required', 'numeric', 'min:0'],
            'salary_components.*.include_in_eos' => ['sometimes', 'boolean'],
            'salary_components.*.include_in_leave' => ['sometimes', 'boolean'],
            'salary_components.*.include_in_notice' => ['sometimes', 'boolean'],
            'salary_components.*.reason' => ['nullable', 'string'],
            ...AdditionalFinancialInput::rules(),
            'hire_date' => ['nullable', 'required_with:last_return_from_leave_date,last_settlement_date', 'required_if:leave_balance_method,date_accrual', 'date'],
            'last_working_date' => ['nullable', 'required_with:last_return_from_leave_date,last_settlement_date', 'required_if:leave_balance_method,date_accrual', 'required_if:salary_days_source,last_working_date', 'date', \Illuminate\Validation\Rule::when($this->filled('last_return_from_leave_date'), 'after_or_equal:last_return_from_leave_date'), \Illuminate\Validation\Rule::when($this->filled('last_settlement_date'), 'after:last_settlement_date')],
            'employee_id' => ['nullable', 'required_if:calculation_mode,employee', 'prohibited_if:calculation_mode,estimate', 'integer', 'exists:employees,id'],
            'contract_id' => ['nullable', 'prohibited_if:calculation_mode,estimate', 'integer', 'exists:contracts,id'],
            'transaction_date' => ['required', 'date'],
            'leave_transaction_type' => ['required', 'in:annual_leave_pay,termination_leave_compensation,active_service_cash_encashment'],
            'leave_period_from' => ['nullable', 'date'],
            'leave_period_to' => ['nullable', 'date', 'after_or_equal:leave_period_from'],
            'leave_start_date' => ['nullable', 'date'],
            'leave_end_date' => ['nullable', 'date', 'after_or_equal:leave_start_date'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'accrued_days' => ['nullable', 'numeric', 'min:0'],
            'used_days' => ['nullable', 'numeric', 'min:0'],
            'adjustment_days' => ['nullable', 'numeric'],
            'available_days' => ['nullable', 'numeric', 'min:0'],
            'requested_leave_days' => ['nullable', 'numeric', 'min:0'],
            'days_to_compensate' => ['nullable', 'numeric', 'min:0'],
            'balance_source' => ['nullable', 'in:calculated,hr_system,manual'],
            'basic_salary' => ['nullable', 'required_if:calculation_mode,estimate', 'numeric', 'min:0'],
            'monthly_wage' => ['nullable', 'numeric', 'min:0'],
            'unpaid_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'salary_days_source' => ['sometimes', 'in:manual,last_working_date,paid'],
            'salary_due_monthly_wage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_working_date.required_with' => 'أدخل تاريخ آخر يوم عمل لإكمال الحساب عند إدخال تاريخ آخر مباشرة بعد الإجازة أو آخر يوم تمت تسويته.',
            'last_working_date.required_if' => 'أدخل تاريخ آخر يوم عمل لحساب مدة استحقاق الإجازة وأيام راتب التصفية.',
            'last_working_date.date' => 'تاريخ آخر يوم عمل غير صحيح.',
            'last_working_date.after_or_equal' => 'تاريخ آخر يوم عمل يجب ألا يسبق تاريخ آخر مباشرة بعد الإجازة.',
            'last_working_date.after' => 'تاريخ آخر يوم عمل يجب أن يكون بعد آخر يوم تمت تسويته.',
            'hire_date.required_with' => 'أدخل تاريخ المباشرة لإكمال حساب مدة استحقاق الإجازة.',
            'hire_date.required_if' => 'أدخل تاريخ المباشرة لحساب رصيد الإجازة تلقائيًا حسب التواريخ.',
            'hire_date.date' => 'تاريخ المباشرة غير صحيح.',
            'unpaid_days.integer' => 'أدخل أيام الراتب المستحقة كعدد صحيح.',
            'unpaid_days.min' => 'أيام الراتب المستحقة لا يمكن أن تكون سالبة.',
            'unpaid_days.max' => 'أيام الراتب المستحقة في شهر التصفية لا تتجاوز 31 يوماً.',
            'salary_due_monthly_wage.numeric' => 'أدخل الراتب الشهري لحساب الأيام كمبلغ صحيح.',
            'salary_due_monthly_wage.min' => 'الراتب الشهري لحساب الأيام لا يمكن أن يكون سالباً.',
            'salary_due_monthly_wage.decimal' => 'الراتب الشهري لحساب الأيام يقبل منزلتين عشريتين كحد أقصى.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $request = $this;

            $availableDays = (float) ($request->input('available_days') ?? 0);
            $requested = (float) ($request->input('requested_leave_days') ?? 0);
            $compensated = (float) ($request->input('days_to_compensate') ?? 0);

            if ($availableDays !== 0.0 && $requested > $availableDays) {
                $validator->errors()->add('requested_leave_days', 'Requested leave days cannot exceed available balance.');
            }

            if ($compensated > $availableDays && $availableDays !== 0.0) {
                $validator->errors()->add('days_to_compensate', 'Compensated days cannot exceed available balance.');
            }

            $negativeFields = ['opening_balance', 'accrued_days', 'used_days', 'adjustment_days', 'available_days', 'requested_leave_days', 'days_to_compensate'];
            foreach ($negativeFields as $field) {
                $value = $request->input($field);
                if ($value !== null && (float) $value < 0) {
                    $validator->errors()->add($field, 'Leave values cannot be negative.');
                }
            }
        });
    }
}
