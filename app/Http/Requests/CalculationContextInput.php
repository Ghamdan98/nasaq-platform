<?php

namespace App\Http\Requests;

class CalculationContextInput
{
    public static function rules(): array
    {
        return [
            'calculation_mode' => ['sometimes', 'in:employee,estimate'],
            'estimate_name' => ['nullable', 'string', 'max:150'],
            'estimate_company' => ['nullable', 'string', 'max:150'],
        ];
    }

    public static function describe(array $input, array $result): array
    {
        $estimate = ($input['calculation_mode'] ?? null) === 'estimate' || (empty($input['employee_id']) && empty($input['contract_id']));
        $result['calculation_context'] = [
            'mode' => $estimate ? 'estimate' : 'employee',
            'is_estimate' => $estimate,
            'can_save' => ! $estimate && ! empty($input['employee_id']),
            'name' => $estimate ? ($input['estimate_name'] ?? null) : null,
            'company' => $estimate ? ($input['estimate_company'] ?? null) : null,
            'notice' => $estimate ? 'نتيجة تقديرية بناءً على البيانات المدخلة، غير معتمدة ولا تنشئ موظفًا أو التزامًا ماليًا.' : null,
        ];

        return $result;
    }
}
