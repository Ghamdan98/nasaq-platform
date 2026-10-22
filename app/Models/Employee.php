<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    public function advances() { return $this->hasMany(EmployeeAdvance::class); }
    public function hrTransactions() { return $this->hasMany(HrTransaction::class); }

    protected $hidden = ['national_id_or_iqama', 'salary_inclusion', 'basic_salary', 'allowance_1', 'transportation_allowance', 'work_nature_allowance', 'subsistence_allowance', 'food_allowance', 'additional_fixed_allowance', 'other_allowances', 'housing_allowance'];

    protected $fillable = [
        'employee_number',
        'full_name',
        'national_id_or_iqama',
        'job_title',
        'department',
        'hire_date',
        'status',
        'company', 'nationality', 'last_leave_settlement_date',
        'basic_salary', 'allowance_1', 'transportation_allowance', 'work_nature_allowance',
        'subsistence_allowance', 'food_allowance', 'additional_fixed_allowance', 'other_allowances', 'housing_allowance', 'salary_inclusion',
    ];

    protected function casts(): array
    {
        return array_merge(array_fill_keys(\App\Services\Employees\EmployeeData::MONEY, 'decimal:2'), ['salary_inclusion' => 'array']);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function salaryComponents(): HasMany
    {
        return $this->hasMany(SalaryComponent::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
