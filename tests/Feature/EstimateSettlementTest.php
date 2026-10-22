<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    private function payload(): array
    {
        return ['calculation_mode' => 'estimate', 'estimate_name' => 'توقعات موظف غير مسجل', 'estimate_company' => 'شركة افتراضية',
            'transaction_type' => 'final_settlement', 'transaction_date' => '2026-01-01', 'hire_date' => '2020-01-01', 'last_working_date' => '2026-01-01',
            'basic_salary' => 6000, 'housing_allowance' => 1000, 'transport_allowance' => 500, 'fixed_allowances' => 500,
            'termination_type' => 'termination', 'actual_notice_days' => 60, 'leave_balance_method' => 'manual', 'remaining_leave_days' => 0, 'unpaid_days' => 0];
    }

    public function test_estimate_uses_entered_total_salary_without_creating_employee_or_financial_records(): void
    {
        foreach (['/api/settlements/calculate', '/api/hr-transactions/calculate'] as $endpoint) {
            $response = $this->postJson($endpoint, $this->payload())->assertOk()->assertJsonPath('calculation_context.is_estimate', true)->assertJsonPath('calculation_context.can_save', false)->assertJsonPath('calculation_context.name', 'توقعات موظف غير مسجل');
            $this->assertEquals(8000, $response->json('salary_base.gross_monthly_wage'));
            $this->assertEquals(28000, $response->json('net_settlement'));
        }
        foreach (['employees', 'hr_transactions', 'employee_advances', 'advance_transactions', 'settlement_payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_estimate_cannot_reference_registered_employee_or_be_saved_even_with_employee_id(): void
    {
        $employee = Employee::create(['employee_number' => 'EST1', 'full_name' => 'موظف مسجل', 'hire_date' => '2020-01-01', 'basic_salary' => 50000, 'status' => 'active']);
        foreach (['/api/settlements/calculate', '/api/hr-transactions/calculate'] as $endpoint) {
            $this->postJson($endpoint, array_merge($this->payload(), ['employee_id' => $employee->id]))->assertUnprocessable()->assertJsonValidationErrors('employee_id');
            $this->postJson($endpoint, array_merge($this->payload(), ['contract_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        }
        $this->postJson('/api/hr-transactions', array_merge($this->payload(), ['employee_id' => $employee->id]))->assertUnprocessable()->assertJsonValidationErrors('calculation_mode');
        $this->assertDatabaseCount('hr_transactions', 0);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_explicit_employee_mode_requires_selection_and_keeps_profile_as_authoritative_source(): void
    {
        $payload = array_merge($this->payload(), ['calculation_mode' => 'employee']);
        $this->postJson('/api/settlements/calculate', $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $employee = Employee::create(['employee_number' => 'EST2', 'full_name' => 'موظف مسجل', 'hire_date' => '2020-01-01', 'basic_salary' => 9000, 'status' => 'active']);
        $response = $this->postJson('/api/settlements/calculate', array_merge($payload, ['employee_id' => $employee->id]))->assertOk()->assertJsonPath('calculation_context.is_estimate', false)->assertJsonPath('calculation_context.can_save', true);
        $this->assertEquals(9000, $response->json('salary_base.gross_monthly_wage'));
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_leave_estimate_and_invalid_manual_input(): void
    {
        $payload = array_merge($this->payload(), ['transaction_type' => 'leave_settlement', 'leave_transaction_type' => 'annual_leave_pay', 'opening_balance' => 10, 'requested_leave_days' => 5, 'balance_source' => 'manual']);
        $response = $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()->assertJsonPath('calculation_context.is_estimate', true);
        $this->assertEquals(8000, $response->json('calculation_wage'));
        $this->assertEquals(1333.33, $response->json('gross_leave_amount'));
        $this->postJson('/api/settlements/calculate', array_merge($this->payload(), ['basic_salary' => null]))->assertUnprocessable()->assertJsonValidationErrors('basic_salary');
        $this->postJson('/api/settlements/calculate', array_merge($this->payload(), ['last_working_date' => '2019-01-01']))->assertUnprocessable()->assertJsonValidationErrors('last_working_date');
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_missing_leave_last_working_date_has_actionable_arabic_message_and_accepts_valid_date(): void
    {
        foreach (['last_return_from_leave_date', 'last_settlement_date'] as $field) {
            $payload = array_merge($this->payload(), ['transaction_type' => 'leave_settlement', 'leave_transaction_type' => 'annual_leave_pay', 'leave_balance_method' => 'date_accrual', 'requested_leave_days' => 5, 'last_working_date' => null, $field => '2025-01-01']);
            $response = $this->postJson('/api/hr-transactions/calculate', $payload)->assertUnprocessable()->assertJsonValidationErrors('last_working_date');
            $this->assertStringContainsString('أدخل تاريخ آخر يوم عمل', implode(' ', $response->json('errors.last_working_date')));
            $this->assertStringNotContainsString('The last working date', implode(' ', $response->json('errors.last_working_date')));
            $this->postJson('/api/hr-transactions/calculate', array_merge($payload, ['last_working_date' => '2026-01-01']))->assertOk();
        }
    }
}
