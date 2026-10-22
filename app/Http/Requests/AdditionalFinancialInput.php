<?php

namespace App\Http\Requests;

class AdditionalFinancialInput
{
    public static function rules(): array
    {
        $rules = [];
        foreach (['additional_entitlements', 'additional_deductions'] as $field) {
            $rules[$field] = ['sometimes', 'array', 'max:50'];
            $rules[$field.'.*'] = ['required', 'array:name,amount,reason'];
            $rules[$field.'.*.name'] = ['required', 'string', 'max:200'];
            $rules[$field.'.*.amount'] = ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'];
            $rules[$field.'.*.reason'] = ['required', 'string', 'min:3', 'max:1000'];
        }
        return $rules;
    }
}
