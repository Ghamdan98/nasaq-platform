<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalFinancialItemsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $type = 'final_settlement'): array
    {
        return ['transaction_type' => $type, 'transaction_date' => '2026-10-18',
            'hire_date' => '2022-01-01', 'last_working_date' => '2026-01-01',
            'termination_type' => 'termination', 'basic_salary' => 6000, 'actual_notice_days' => 60, 'unpaid_days' => 0,
            'leave_transaction_type' => 'annual_leave_pay', 'opening_balance' => 20, 'accrued_days' => 10,
            'used_days' => 5, 'requested_leave_days' => 10,
            'additional_entitlements' => [['name' => 'Performance bonus', 'amount' => '1000.25', 'reason' => 'Approved performance award']],
            'additional_deductions' => [['name' => 'Other balance', 'amount' => '200.10', 'reason' => 'Documented outstanding balance']]];
    }

    public function test_final_additions_change_totals_without_changing_statutory_salary_base(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        foreach (['/api/settlements/calculate', '/api/hr-transactions/calculate'] as $url) {
            $this->postJson($url, $this->payload() + ['employee_loan' => 50])->assertOk()
                ->assertJsonPath('salary_base.gross_monthly_wage', '6000')->assertJsonPath('entitlements.2.amount', '12000')
                ->assertJsonPath('gross_entitlements', '13000.25')->assertJsonPath('total_deductions', '250.1')
                ->assertJsonPath('net_settlement', '12750.15')->assertJsonPath('review_status', 'warning')
                ->assertJsonPath('entitlements.4.name_ar', 'Performance bonus');
        }
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_final_and_leave_saves_preserve_both_item_types_reasons_and_totals(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = Employee::create(['employee_number' => 'ADDITIONAL', 'full_name' => 'Additional Test', 'hire_date' => '2022-01-01', 'status' => 'active']);
        foreach (['final_settlement' => ['13000.25', '12800.15'], 'leave_settlement' => ['3000.25', '2800.15']] as $type => [$gross, $net]) {
            $payload = $this->payload($type) + ['employee_id' => $employee->id, 'net_amount' => 1];
            $saved = $this->postJson('/api/hr-transactions', $payload)->assertCreated()
                ->assertJsonPath('gross_amount', $gross)->assertJsonPath('net_amount', $net)->json();
            $id = $saved['id'];
            $details = $this->getJson('/api/hr-transactions/'.$id)->assertOk()
                ->assertJsonPath('calculated_totals.gross_amount', $gross)
                ->assertJsonPath('calculated_totals.net_amount', $net)->json();
            $bonus = collect($details['financial_items'])->firstWhere('code', 'additional_entitlement_1');
            $deduction = collect($details['deductions'])->firstWhere('code', 'additional_deduction_1');
            $this->assertSame('1000.25', $bonus['calculated_amount']);
            $this->assertSame('Approved performance award', $bonus['calculation_details_json']['reason']);
            $this->assertSame('200.10', $deduction['calculated_amount']);
            $this->assertSame('Documented outstanding balance', $deduction['calculation_details_json']['reason']);
            $this->getJson("/api/hr-transactions/$id/print")->assertOk()
                ->assertJsonPath('calculated_totals.gross_amount', $gross)->assertJsonPath('calculated_totals.net_amount', $net);
            $this->get("/api/hr-transactions/$id/export/excel")->assertOk();
            $this->get("/api/hr-transactions/$id/export/pdf")->assertOk();
        }
    }

    public function test_leave_gross_remains_separate_from_bonus_and_net(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload('leave_settlement'))->assertOk()
            ->assertJsonPath('gross_leave_amount', '2000')->assertJsonPath('gross_entitlements', '3000.25')
            ->assertJsonPath('total_deductions', '200.1')->assertJsonPath('net_settlement', '2800.15')
            ->assertJsonPath('review_status', 'warning');
    }

    public function test_multiple_fractional_items_use_exact_money(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $payload = $this->payload();
        $payload['additional_entitlements'] = [
            ['name' => 'Bonus A', 'amount' => '0.01', 'reason' => 'Reason A'],
            ['name' => 'Bonus B', 'amount' => '0.02', 'reason' => 'Reason B']];
        $payload['additional_deductions'] = [
            ['name' => 'Deduction A', 'amount' => '0.01', 'reason' => 'Reason A'],
            ['name' => 'Deduction B', 'amount' => '0.01', 'reason' => 'Reason B']];
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()
            ->assertJsonPath('gross_entitlements', '12000.03')->assertJsonPath('total_deductions', '0.02')
            ->assertJsonPath('net_settlement', '12000.01');
    }

    public function test_names_reasons_amounts_and_reserved_fields_are_validated(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach (['additional_entitlements', 'additional_deductions'] as $field) {
            foreach ([['name' => ''], ['reason' => ''], ['amount' => -1], ['amount' => '0.001'], ['amount' => 0], ['code' => 'end_of_service']] as $override) {
                $payload = $this->payload();
                $payload[$field][0] = array_merge($payload[$field][0], $override);
                $this->postJson('/api/hr-transactions/calculate', $payload)->assertUnprocessable();
            }
        }
    }

    public function test_additions_do_not_bypass_blocked_leave_or_save_permissions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'sanctum');
        $payload = $this->payload('leave_settlement');
        $payload['leave_transaction_type'] = 'active_service_cash_encashment';
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()
            ->assertJsonPath('review_status', 'blocked')->assertJsonPath('gross_leave_amount', '0');
        $this->postJson('/api/hr-transactions', $payload)->assertForbidden();
    }
}
