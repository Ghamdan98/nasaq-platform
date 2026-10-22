<?php

namespace App\Http\Requests;

use App\Models\Contract;
use App\Models\TerminationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlementCalculationRequest extends FormRequest
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
            ...AdditionalFinancialInput::rules(),
            'employee_id' => ['nullable', 'required_if:calculation_mode,employee', 'prohibited_if:calculation_mode,estimate', 'exists:employees,id'],
            'contract_id' => ['nullable', 'prohibited_if:calculation_mode,estimate', 'exists:contracts,id'],
            'termination_type' => ['nullable', Rule::in(['resignation', 'termination', 'article_80', 'article_81', 'retirement'])],
            'hire_date' => ['required_without_all:employee_id,contract_id', 'required_with:last_return_from_leave_date,last_settlement_date', 'required_if:leave_balance_method,date_accrual', 'nullable', 'date'],
            'last_working_date' => ['required', 'date', Rule::when($this->filled('last_return_from_leave_date'), 'after_or_equal:last_return_from_leave_date'), Rule::when($this->filled('last_settlement_date'), 'after:last_settlement_date')],
            'termination_date' => ['nullable', 'date'],
            'basic_salary' => ['nullable', 'required_if:calculation_mode,estimate', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'fixed_allowances' => ['nullable', 'numeric', 'min:0'],
            'commissions' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'monthly_wage' => ['nullable', 'numeric', 'min:0'],
            'already_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'notice_days' => ['nullable', 'integer', 'min:0'],
            'actual_notice_days' => ['nullable', 'integer', 'min:0'],
            'remaining_leave_days' => ['nullable', 'numeric', 'min:0'],
            'accrued_leave_days' => ['nullable', 'numeric', 'min:0'],
            'used_leave_days' => ['nullable', 'numeric', 'min:0'],
            'adjusted_leave_days' => ['nullable', 'numeric', 'min:0'],
            'employee_loan' => ['nullable', 'numeric', 'min:0'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'absence_adjustment' => ['nullable', 'numeric', 'min:0'],
            'unreturned_assets' => ['nullable', 'numeric', 'min:0'],
            'other' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.amount' => ['nullable', 'numeric', 'min:0'],
            'salary_components' => ['nullable', 'array'],
            'salary_components.*.code' => ['required', 'string', 'distinct'],
            'salary_components.*.amount' => ['required', 'numeric', 'min:0'],
            'salary_components.*.include_in_eos' => ['sometimes', 'boolean'],
            'salary_components.*.include_in_leave' => ['sometimes', 'boolean'],
            'salary_components.*.include_in_notice' => ['sometimes', 'boolean'],
            'salary_components.*.reason' => ['nullable', 'string'],
            'pay_period_start' => ['nullable', 'date', 'before_or_equal:last_working_date'],
            'unpaid_days' => ['nullable', 'integer', 'min:0'],
            'salary_days_source' => ['sometimes', 'in:manual,last_working_date,paid'],
            'salary_due_monthly_wage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'termination_type_id' => ['nullable', 'exists:termination_types,id'],
            'contract_type' => ['nullable', 'string'],
            'contract_notice_days' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.exists' => 'الموظف المحدد غير موجود. اختر موظفاً مسجلاً أو اترك الاختيار فارغاً للحساب التجريبي.',
            'contract_id.exists' => 'العقد المحدد غير موجود. اختر عقداً مسجلاً للموظف.',
            'last_working_date.required' => 'يجب إدخال تاريخ آخر يوم عمل.',
            'last_working_date.required_with' => 'أدخل تاريخ آخر يوم عمل لإكمال الحساب عند إدخال تاريخ آخر مباشرة بعد الإجازة أو آخر يوم تمت تسويته.',
            'last_working_date.date' => 'تاريخ آخر يوم عمل غير صحيح.',
            'hire_date.date' => 'تاريخ المباشرة غير صحيح.',
            'basic_salary.min' => 'الراتب الأساسي لا يمكن أن يكون سالباً.',
            'housing_allowance.min' => 'بدل السكن لا يمكن أن يكون سالباً.',
            'transport_allowance.min' => 'بدل النقل لا يمكن أن يكون سالباً.',
            'fixed_allowances.min' => 'البدلات الثابتة لا يمكن أن تكون سلبية.',
            'commissions.min' => 'العمولات لا يمكن أن تكون سلبية.',
            'other_allowances.min' => 'البدلات الأخرى لا يمكن أن تكون سلبية.',
            'notice_days.min' => 'عدد أيام الإشعار لا يمكن أن يكون سالباً.',
            'employee_loan.min' => 'قيمة القرض لا يمكن أن تكون سلبية.',
            'advance.min' => 'قيمة السلفة لا يمكن أن تكون سلبية.',
            'absence_adjustment.min' => 'قيمة تعديل الغياب لا يمكن أن تكون سلبية.',
            'unreturned_assets.min' => 'قيمة الأصول غير المسترجعة لا يمكن أن تكون سلبية.',
            'other.min' => 'قيمة الخصم الآخر لا يمكن أن تكون سلبية.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hireDate = $this->input('hire_date');
            $lastWorkingDate = $this->input('last_working_date');

            if ($hireDate && $lastWorkingDate && strtotime($lastWorkingDate) < strtotime($hireDate)) {
                $validator->errors()->add('last_working_date', 'تاريخ آخر يوم عمل لا يمكن أن يكون قبل تاريخ المباشرة.');
            }

            if ($this->input('employee_id') && $this->input('contract_id')) {
                $contract = Contract::find($this->input('contract_id'));

                if ($contract && (int) $contract->employee_id !== (int) $this->input('employee_id')) {
                    $validator->errors()->add('contract_id', 'العقد لا ينتمي إلى الموظف المحدد.');
                }
            }

            if ($this->input('termination_type_id')) {
                $terminationType = TerminationType::find($this->input('termination_type_id'));
                if ($terminationType && ! $terminationType->is_active) {
                    $validator->errors()->add('termination_type_id', 'نوع الإنهاء غير فعال في النظام.');
                }
            }
        });
    }
}
