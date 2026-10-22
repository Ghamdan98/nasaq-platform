<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSalaryDueTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge(['transaction_type' => 'leave_settlement', 'transaction_date' => '2026-10-18',
            'leave_transaction_type' => 'termination_leave_compensation', 'leave_balance_method' => 'date_accrual',
            'hire_date' => '2024-03-01', 'last_return_from_leave_date' => '2024-03-01',
            'last_working_date' => '2026-10-18', 'basic_salary' => 3500], $overrides);
    }

    public function test_fifteen_unpaid_days_are_calculated_and_added_once_to_leave_total(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload(['unpaid_days' => 15]))->assertOk()
            ->assertJsonPath('salary_due.days', 15)->assertJsonPath('salary_due.net_due', '1750')
            ->assertJsonPath('gross_leave_amount', '6444.86')->assertJsonPath('gross_entitlements', '8194.86')
            ->assertJsonPath('net_settlement', '8194.86')->assertJsonPath('entitlements.0.code', 'salary_due')
            ->assertJsonPath('entitlements.0.quantity', 15)->assertJsonPath('entitlements.0.amount', '1750');
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_missing_or_zero_days_never_assume_salary_for_last_working_day(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach ([[], ['unpaid_days' => 0]] as $overrides) {
            $this->postJson('/api/hr-transactions/calculate', $this->payload($overrides))->assertOk()
                ->assertJsonPath('salary_due.net_due', '0')->assertJsonPath('salary_due.days', 0)
                ->assertJsonPath('net_settlement', '6444.86')->assertJsonCount(0, 'entitlements');
        }
    }

    public function test_separate_salary_wage_does_not_change_leave_wage_and_combines_with_extras(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'unpaid_days' => 15, 'salary_due_monthly_wage' => 4000,
            'additional_entitlements' => [['name' => 'Bonus', 'amount' => 500, 'reason' => 'Bonus reason']],
            'additional_deductions' => [['name' => 'Deduction', 'amount' => 200, 'reason' => 'Deduction reason']],
        ]))->assertOk()->assertJsonPath('salary_due.net_due', '2000')
            ->assertJsonPath('gross_leave_amount', '6444.86')->assertJsonPath('calculation_wage', '3500')
            ->assertJsonPath('gross_entitlements', '8944.86')->assertJsonPath('net_settlement', '8744.86');
    }

    public function test_invalid_days_or_wages_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach ([['unpaid_days' => -1], ['unpaid_days' => 32], ['unpaid_days' => '1.5'],
            ['salary_due_monthly_wage' => -1], ['salary_due_monthly_wage' => 'invalid']] as $overrides) {
            $this->postJson('/api/hr-transactions/calculate', $this->payload($overrides))->assertUnprocessable();
        }
    }

    public function test_saved_salary_item_reopens_with_quantity_wage_and_original_amount(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = Employee::create(['employee_number' => 'SALARY-LEAVE', 'full_name' => 'Salary Test', 'hire_date' => '2024-03-01', 'status' => 'active']);
        $id = $this->postJson('/api/hr-transactions', $this->payload(['employee_id' => $employee->id,
            'unpaid_days' => 15, 'net_amount' => 1]))->assertCreated()->assertJsonPath('net_amount', '8194.86')->json('id');
        $details = $this->getJson('/api/hr-transactions/'.$id)->assertOk()
            ->assertJsonPath('calculated_totals.net_amount', '8194.86')->json();
        $salary = collect($details['financial_items'])->firstWhere('code', 'salary_due');
        $this->assertSame('15.00', $salary['quantity']);
        $this->assertSame('1750.00', $salary['calculated_amount']);
        $this->assertSame('1750.00', $salary['approved_amount']);
        $this->assertEquals(3500, $salary['calculation_details_json']['monthly_wage']);
        $this->getJson("/api/hr-transactions/$id/print")->assertOk()->assertJsonPath('calculated_totals.net_amount', '8194.86');
        $this->get("/api/hr-transactions/$id/export/excel")->assertOk();
        $this->get("/api/hr-transactions/$id/export/pdf")->assertOk();
        $this->assertDatabaseCount('hr_transaction_items', 2);
    }

    public function test_salary_does_not_bypass_blocked_leave(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'unpaid_days' => 15, 'leave_transaction_type' => 'active_service_cash_encashment',
        ]))->assertOk()->assertJsonPath('review_status', 'blocked')->assertJsonPath('gross_leave_amount', '0');
    }
}
