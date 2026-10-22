<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculators_support_simulation_without_employee_but_saving_requires_one(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $final = ['transaction_type' => 'final_settlement', 'hire_date' => '2022-01-01',
            'last_working_date' => '2026-01-01', 'basic_salary' => 6000, 'termination_type' => 'termination'];
        $leave = ['transaction_type' => 'leave_settlement', 'transaction_date' => '2026-10-18',
            'leave_transaction_type' => 'annual_leave_pay', 'opening_balance' => 20,
            'requested_leave_days' => 10, 'basic_salary' => 6000];
        $this->postJson('/api/settlements/calculate', $final)->assertOk()->assertJsonPath('entitlements.2.amount', '12000');
        $this->postJson('/api/hr-transactions/calculate', $leave)->assertOk()->assertJsonPath('gross_leave_amount', '2000');
        foreach ([$final, $leave] as $payload) {
            $this->postJson('/api/hr-transactions', $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_supplied_nonexistent_employee_is_not_silently_ignored(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/settlements/calculate', ['employee_id' => 999, 'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->postJson('/api/hr-transactions/calculate', ['transaction_type' => 'leave_settlement',
            'employee_id' => 999, 'transaction_date' => '2026-10-18', 'leave_transaction_type' => 'annual_leave_pay'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    private function leave(): array
    {
        $employee = Employee::create(['employee_number' => 'AUDIT', 'full_name' => 'Audit Employee', 'hire_date' => '2020-01-01', 'status' => 'active']);
        return ['transaction_type' => 'leave_settlement', 'employee_id' => $employee->id,
            'transaction_date' => '2026-10-18', 'leave_transaction_type' => 'annual_leave_pay',
            'opening_balance' => 20, 'accrued_days' => 10, 'used_days' => 5,
            'requested_leave_days' => 10, 'basic_salary' => 6000];
    }

    public function test_real_leave_api_routes_and_saves_the_same_calculation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $payload = $this->leave();
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()
            ->assertJsonPath('transaction_type', 'leave_settlement')->assertJsonPath('available_days', '25')
            ->assertJsonPath('remaining_balance', '15')->assertJsonPath('gross_leave_amount', '2000');
        $this->assertDatabaseCount('hr_transactions', 0);
        $this->postJson('/api/hr-transactions', $payload)->assertCreated()->assertJsonPath('net_amount', '2000.00');
        $this->assertDatabaseCount('hr_transactions', 1);
    }

    public function test_annual_leave_does_not_use_termination_compensation_days(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->leave() + ['days_to_compensate' => 3])
            ->assertOk()->assertJsonPath('gross_leave_amount', '2000');
    }

    public function test_leave_reversed_period_is_rejected_on_calculate_and_save(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $payload = $this->leave() + ['leave_period_from' => '2026-10-20', 'leave_period_to' => '2026-10-01'];
        foreach (['/api/hr-transactions/calculate', '/api/hr-transactions'] as $url) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('leave_period_to');
        }
    }

    public function test_unknown_transaction_type_and_negative_final_wage_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', ['transaction_type' => 'unknown'])->assertUnprocessable();
        $this->postJson('/api/hr-transactions/calculate', ['last_working_date' => '2026-01-01', 'basic_salary' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('basic_salary');
    }

    public function test_registration_cannot_grant_admin_role(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->postJson('/api/auth/register', ['name' => 'Audit', 'email' => 'audit@example.test',
            'password' => 'test-password', 'password_confirmation' => 'test-password', 'role' => 'admin'])
            ->assertCreated()->assertJsonPath('user.role', 'hr');
    }

    public function test_hr_cannot_read_other_hr_transactions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $id = $this->postJson('/api/hr-transactions', $this->leave())->assertCreated()->json('id');
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $this->getJson('/api/hr-transactions')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/hr-transactions/$id")->assertForbidden();
    }

    public function test_calculation_requires_authentication(): void
    {
        $this->postJson('/api/hr-transactions/calculate', [])->assertUnauthorized();
        $this->postJson('/api/settlements/calculate', [])->assertUnauthorized();
    }

    public function test_distinct_salary_bases_reach_leave_and_notice_items(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $response = $this->postJson('/api/settlements/calculate', [
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
            'termination_type' => 'termination', 'basic_salary' => 6000, 'remaining_leave_days' => 10,
            'salary_components' => [['code' => 'bonus', 'amount' => 3000,
                'include_in_eos' => false, 'include_in_leave' => false, 'include_in_notice' => false,
                'reason' => 'Audit fixture exclusion']],
        ])->assertOk();
        $response->assertJsonPath('entitlements.0.rate', '300')
            ->assertJsonPath('entitlements.1.amount', '2000')
            ->assertJsonPath('entitlements.2.amount', '12000')
            ->assertJsonPath('entitlements.3.amount', '12000');
    }

    public function test_resignation_missing_notice_does_not_credit_employee(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/settlements/calculate', [
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
            'termination_type' => 'resignation', 'basic_salary' => 6000, 'actual_notice_days' => 0,
        ])->assertOk()->assertJsonPath('entitlements.3.amount', '0')
            ->assertJsonPath('review_status', 'requires_legal_review');
    }

    public function test_final_save_rejects_reversed_service_dates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = $this->leave()['employee_id'];
        $this->postJson('/api/hr-transactions', [
            'transaction_type' => 'final_settlement', 'employee_id' => $employee,
            'hire_date' => '2026-01-01', 'last_working_date' => '2020-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('last_working_date');
    }

    public function test_fractional_deduction_totals_match_saved_items(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = $this->leave()['employee_id'];
        $payload = ['transaction_type' => 'final_settlement', 'employee_id' => $employee,
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01', 'basic_salary' => 6000,
            'deductions' => [['amount' => '0.005'], ['amount' => '0.005']]];
        $calculated = $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()
            ->assertJsonPath('total_deductions', '0.02')->json();
        $saved = $this->postJson('/api/hr-transactions', $payload)->assertCreated()->json();
        $this->assertEquals($calculated['net_settlement'], $saved['net_amount']);
    }

    public function test_reviewer_cannot_mutate_financial_sources_or_read_global_audit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'sanctum');
        foreach (['employees', 'contracts', 'salary-components', 'labor-rules', 'settlement-rules', 'termination-types'] as $resource) {
            $this->postJson('/api/'.$resource, [])->assertForbidden();
        }
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->postJson('/api/settlements', [])->assertStatus(410);
    }

    public function test_zero_leave_balance_is_valid_and_does_not_require_review(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/settlements/calculate', [
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
            'termination_type' => 'termination', 'basic_salary' => 6000, 'remaining_leave_days' => 0,
        ])->assertOk()->assertJsonPath('entitlements.1.amount', '0')->assertJsonPath('review_status', 'valid');
    }

    public function test_zero_fractional_and_large_totals_are_exact(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach ([['0', '0', '0', []], ['6000.01', '12000.02', '11849.67', [['amount' => '100.25'], ['amount' => '50.10']]], ['1000000000', '2000000000', '2000000000', []]] as [$wage, $gross, $net, $deductions]) {
            $this->postJson('/api/settlements/calculate', [
                'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
                'termination_type' => 'termination', 'basic_salary' => $wage,
                'actual_notice_days' => 60, 'unpaid_days' => 0, 'deductions' => $deductions,
            ])->assertOk()->assertJsonPath('entitlements.2.amount', $gross)
                ->assertJsonPath('gross_entitlements', $gross)->assertJsonPath('net_settlement', $net);
        }
    }

    public function test_all_six_salary_components_are_included_once(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/settlements/calculate', [
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
            'termination_type' => 'termination', 'basic_salary' => 1000, 'housing_allowance' => 200,
            'transport_allowance' => 100, 'fixed_allowances' => 50, 'commissions' => 20, 'other_allowances' => 30,
            'actual_notice_days' => 60, 'unpaid_days' => 0,
        ])->assertOk()->assertJsonPath('salary_base.gross_monthly_wage', '1400')
            ->assertJsonPath('entitlements.2.amount', '2800')->assertJsonPath('net_settlement', '2800');
    }
}
