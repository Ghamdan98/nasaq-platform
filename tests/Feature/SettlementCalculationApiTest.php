<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Settlement\SettlementEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementCalculationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_of_service_uses_360_day_year_for_partial_service(): void
    {
        $result = (new \App\Services\Settlement\EndOfServiceCalculator())->calculate(
            ['eos_calculation_wage' => '6200'],
            ['years' => 6, 'months' => 1, 'days' => 10],
            'termination',
        );
        $this->assertSame('22388.89', $result['final_award']);
        $this->assertEqualsWithDelta(6 + 1 / 12 + 10 / 360, (float) $result['service_years'], 0.000000001);
    }

    public function test_four_year_end_of_service_uses_half_month_rule(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2022-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('12000', $result['entitlements'][2]['amount']);
    }

    public function test_seven_year_end_of_service_has_first_and_second_periods(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2019-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('27000', $result['entitlements'][2]['amount']);
    }

    public function test_resignation_after_four_years_uses_one_third_factor(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2022-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'resignation',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('4000', $result['entitlements'][2]['amount']);
    }

    public function test_resignation_after_seven_years_uses_two_thirds_factor(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2019-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'resignation',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('18000', $result['entitlements'][2]['amount']);
    }

    public function test_article_eighty_without_verification_requires_legal_review(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'article_80',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('requires_legal_review', $result['review_status']);
    }

    public function test_notice_period_for_monthly_employer_termination_is_sixty_days(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
            'actual_notice_days' => 30,
            'contract_type' => 'indefinite',
        ]);

        $this->assertSame('30', (string) $result['entitlements'][3]['quantity']);
    }

    public function test_leave_balance_amount_is_calculated_correctly(): void
    {
        $result = SettlementEngine::calculate([
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
            'remaining_leave_days' => 10,
        ]);

        $this->assertSame('2000', $result['entitlements'][1]['amount']);
    }

    public function test_invalid_last_working_date_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'employee_number' => 'E-1001',
            'full_name' => 'Test Employee',
            'hire_date' => '2020-01-01',
            'status' => 'active',
        ]);

        $contract = Contract::create([
            'employee_id' => $employee->id,
            'contract_type' => 'indefinite',
            'start_date' => '2020-01-01',
            'basic_salary' => 6000,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settlements/calculate', [
                'employee_id' => $employee->id,
                'contract_id' => $contract->id,
                'hire_date' => '2020-01-01',
                'last_working_date' => '2019-12-31',
                'termination_type' => 'termination',
                'basic_salary' => 6000,
            ])
            ->assertStatus(422);
    }

    public function test_api_calculate_endpoint_does_not_persist_settlement_records(): void
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'employee_number' => 'E-1002',
            'full_name' => 'Test Employee 2',
            'hire_date' => '2020-01-01',
            'status' => 'active',
        ]);

        $contract = Contract::create([
            'employee_id' => $employee->id,
            'contract_type' => 'indefinite',
            'start_date' => '2020-01-01',
            'basic_salary' => 6000,
            'status' => 'active',
        ]);

        $this->assertSame(0, Settlement::count());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/settlements/calculate', [
                'employee_id' => $employee->id,
                'contract_id' => $contract->id,
                'hire_date' => '2020-01-01',
                'last_working_date' => '2026-01-01',
                'termination_type' => 'termination',
                'basic_salary' => 6000,
            ])
            ->assertOk();

        $this->assertSame(0, Settlement::count());
    }
}
