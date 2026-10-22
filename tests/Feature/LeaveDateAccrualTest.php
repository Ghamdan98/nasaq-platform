<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Settlement\LeaveAccrualCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveDateAccrualTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge(['transaction_type' => 'leave_settlement', 'transaction_date' => '2026-10-18',
            'leave_transaction_type' => 'termination_leave_compensation', 'leave_balance_method' => 'date_accrual',
            'hire_date' => '2022-01-01', 'last_return_from_leave_date' => '2023-01-01',
        'last_working_date' => '2024-01-01', 'basic_salary' => 6000], $overrides);
    }

    public function test_previously_settled_days_are_excluded_from_leave_cash_and_final_settlement(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $data = $this->payload(['previously_settled_days' => 5]);
        $this->postJson('/api/hr-transactions/calculate', $data)->assertOk()
            ->assertJsonPath('available_days', '16')->assertJsonPath('gross_leave_amount', '3200');
        $result = (new \App\Services\Settlement\LeaveBalanceCalculator())->calculate(array_merge($data, ['monthly_wage' => 6000]));
        $this->assertEquals(16, $result['remaining_days']);
        $this->assertEquals(3200, $result['amount']);
    }

    public function test_optional_period_deduction_reduces_accrual_before_deducting_balance(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        foreach ([true, false] as $enabled) {
            $data = $this->payload(['previously_settled_days' => 2,
                'deduct_settled_days_from_accrual_period' => $enabled]);
            $response = $this->postJson('/api/hr-transactions/calculate', $data)->assertOk()
                ->assertJsonPath('accrual_details.accrual_details.eligible_service_days', $enabled ? '358' : '360')
                ->assertJsonPath('gross_leave_amount', $enabled ? '3776.67' : '3800');
            $this->assertEqualsWithDelta($enabled ? 18.883333333333 : 19, (float) $response->json('available_days'), 0.00000001);
            $final = (new \App\Services\Settlement\LeaveBalanceCalculator())->calculate(array_merge($data, ['monthly_wage' => 6000]));
            $this->assertEquals($response->json('gross_leave_amount'), $final['amount']);
        }
    }

    public function test_period_exclusion_is_capped_and_supports_fractional_days_and_rate_changes(): void
    {
        $calculator = new \App\Services\Settlement\LeaveAccrualCalculator();
        $base = $this->payload(['deduct_settled_days_from_accrual_period' => true]);
        $result = $calculator->calculate(array_merge($base, ['previously_settled_days' => 500]));
        $this->assertSame('0', $result['available_days']);
        $this->assertSame('0', $result['accrual_details']['eligible_service_days']);
        $fractional = $calculator->calculate(array_merge($base, ['previously_settled_days' => 0.5]));
        $this->assertSame('359.5', $fractional['accrual_details']['eligible_service_days']);
        $split = $calculator->calculate(array_merge($base, ['hire_date' => '2020-01-01',
            'last_return_from_leave_date' => '2024-07-01', 'last_working_date' => '2025-07-01', 'previously_settled_days' => 2]));
        $this->assertSame('179', $split['accrual_details']['segments'][0]['eligible_service_days']);
        $this->assertSame('179', $split['accrual_details']['segments'][1]['eligible_service_days']);
        $this->assertEqualsWithDelta(23.358333333333, (float) $split['available_days'], 0.00000001);
    }

    public function test_full_years_before_and_after_five_years_and_leap_year(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        foreach ([['2022-01-01', '2023-01-01', '2024-01-01', '21', '4200', 360],
        ['2022-01-01', '2024-01-01', '2025-01-01', '21', '4200', 360],
        ['2018-01-01', '2025-01-01', '2026-01-01', '30', '6000', 360]] as [$hire, $from, $to, $days, $amount, $periodDays]) {
            $this->postJson('/api/hr-transactions/calculate', $this->payload([
                'hire_date' => $hire, 'last_return_from_leave_date' => $from, 'last_working_date' => $to,
            ]))->assertOk()->assertJsonPath('available_days', $days)->assertJsonPath('remaining_balance', '0')
                ->assertJsonPath('gross_leave_amount', $amount)
            ->assertJsonPath('accrual_details.accrual_details.service_days', $periodDays)
            ->assertJsonPath('accrual_details.accrual_details.year_basis', '30/360');
        }
    }

    public function test_period_is_split_at_fifth_anniversary(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'hire_date' => '2020-01-01', 'last_return_from_leave_date' => '2024-07-01', 'last_working_date' => '2025-06-30',
        ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.segments.0.annual_entitlement', '21')
            ->assertJsonPath('accrual_details.accrual_details.segments.0.service_days', 180)
            ->assertJsonPath('accrual_details.accrual_details.segments.1.annual_entitlement', '30')
            ->assertJsonPath('accrual_details.accrual_details.segments.1.service_days', 179)
            ->assertJsonPath('gross_leave_amount', '5083.33');
    }

    public function test_anniversary_boundary_is_shown_as_a_zero_length_thirty_day_segment(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'hire_date' => '2020-01-01',
            'last_return_from_leave_date' => '2024-07-01',
            'last_working_date' => '2025-01-01',
        ]))->assertOk()
            ->assertJsonPath('accrual_details.accrual_details.segments.0.annual_entitlement', '21')
            ->assertJsonPath('accrual_details.accrual_details.segments.0.service_days', 180)
            ->assertJsonPath('accrual_details.accrual_details.segments.1.annual_entitlement', '30')
            ->assertJsonPath('accrual_details.accrual_details.segments.1.service_days', 0);
    }

    public function test_twenty_one_and_thirty_day_rates_use_263_accounting_days(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        foreach ([['21', '15.341666666666', '3068.33'], ['30', '21.916666666666', '4383.33']] as [$annualRate, $expectedAccrual, $expectedAmount]) {
            $response = $this->postJson('/api/hr-transactions/calculate', $this->payload([
                'hire_date' => '2022-01-01',
                'last_return_from_leave_date' => '2026-01-22',
                'last_working_date' => '2026-10-15',
                'contractual_annual_leave_days' => $annualRate === '30' ? 30 : null,
            ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.service_days', 263)
                ->assertJsonPath('accrual_details.accrual_details.period_from', '2026-01-22')
                ->assertJsonPath('accrual_details.accrual_details.period_to', '2026-10-15')
                ->assertJsonPath('gross_leave_amount', $expectedAmount);

            $this->assertEqualsWithDelta(
                (float) $expectedAccrual,
                (float) $response->json('accrual_details.accrued_days'),
                0.00000000001,
            );
        }
    }

    public function test_same_month_february_end_and_year_end_follow_30_360(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach ([['2026-05-10', '2026-05-20', 10], ['2024-02-29', '2024-03-01', 1],
            ['2025-01-31', '2025-02-28', 30],
            ['2025-12-15', '2026-01-15', 30]] as [$from, $to, $days]) {
            $this->postJson('/api/hr-transactions/calculate', $this->payload([
                'last_return_from_leave_date' => $from, 'last_working_date' => $to,
            ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.service_days', $days);
        }
    }

    public function test_last_settlement_starts_on_the_following_date_and_absent_dates_start_at_hire(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'last_return_from_leave_date' => '2026-01-20',
            'last_settlement_date' => '2026-01-21',
            'last_working_date' => '2026-10-15',
        ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.period_from', '2026-01-22')
            ->assertJsonPath('accrual_details.accrual_details.service_days', 263);

        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'hire_date' => '2026-01-22', 'last_return_from_leave_date' => null,
            'last_settlement_date' => null, 'last_working_date' => '2026-10-15',
        ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.period_from', '2026-01-22')
            ->assertJsonPath('accrual_details.accrual_details.service_days', 263);
    }

    public function test_five_year_transition_at_month_end_and_settlement_at_month_end(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'hire_date' => '2020-01-31',
            'last_return_from_leave_date' => '2025-01-30',
            'last_working_date' => '2025-02-02',
        ]))->assertOk()
            ->assertJsonPath('accrual_details.accrual_details.segments.0.annual_entitlement', '21')
            ->assertJsonPath('accrual_details.accrual_details.segments.0.service_days', 0)
            ->assertJsonPath('accrual_details.accrual_details.segments.1.annual_entitlement', '30')
            ->assertJsonPath('accrual_details.accrual_details.segments.1.service_days', 2);

        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'last_return_from_leave_date' => null,
            'last_settlement_date' => '2026-09-30',
            'last_working_date' => '2026-10-15',
        ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.period_from', '2026-10-01')
            ->assertJsonPath('accrual_details.accrual_details.service_days', 14);
    }

    public function test_settled_days_are_capped_to_the_balance_and_not_deducted_twice_from_zero(): void
    {
        $calculator = new LeaveAccrualCalculator();
        $first = $calculator->calculate([
            'opening_balance' => 3, 'accrued_days' => 2, 'used_days' => 1,
            'previously_settled_days' => 100, 'leave_balance_method' => 'manual',
        ]);
        $this->assertSame('4', $first['previously_settled_days']);
        $this->assertSame('0', $first['available_days']);

        $again = $calculator->calculate([
            'opening_balance' => 0, 'accrued_days' => 0, 'used_days' => 0,
            'previously_settled_days' => $first['previously_settled_days'], 'leave_balance_method' => 'manual',
        ]);
        $this->assertSame('0', $again['previously_settled_days']);
        $this->assertSame('0', $again['available_days']);
    }

    public function test_single_day_period_does_not_add_a_day_to_the_accounting_difference(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'last_return_from_leave_date' => '2024-02-29', 'last_working_date' => '2024-03-01',
        ]))->assertOk()->assertJsonPath('accrual_details.accrual_details.service_days', 1)
            ->assertJsonPath('available_days', '0.058333333333')->assertJsonPath('gross_leave_amount', '11.67');
    }

    public function test_higher_contract_rate_is_respected_and_forged_manual_days_ignored(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/hr-transactions/calculate', $this->payload([
            'contractual_annual_leave_days' => 30, 'accrued_days' => 999, 'available_days' => 999,
        ]))->assertOk()->assertJsonPath('available_days', '30')->assertJsonPath('gross_leave_amount', '6000');
    }

    public function test_both_final_routes_use_date_balance_instead_of_manual_value(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach (['/api/settlements/calculate', '/api/hr-transactions/calculate'] as $url) {
            $this->postJson($url, $this->payload(['transaction_type' => 'final_settlement',
                'remaining_leave_days' => 999, 'termination_type' => 'termination', 'actual_notice_days' => 60, 'unpaid_days' => 0]))
                ->assertOk()->assertJsonPath('entitlements.1.quantity', '21')
                ->assertJsonPath('entitlements.1.amount', '4200')->assertJsonPath('leave_calculation.source', 'date_accrual');
        }
    }

    public function test_invalid_or_missing_dates_and_low_contract_rate_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        foreach ([['hire_date' => null], ['last_return_from_leave_date' => '2021-12-31'],
            ['last_working_date' => '2022-12-31'], ['last_return_from_leave_date' => '2023-02-30'],
            ['last_settlement_date' => '2023-02-30'], ['last_settlement_date' => '2023-12-31', 'last_working_date' => '2023-12-31'],
            ['contractual_annual_leave_days' => 20]] as $override) {
            $this->postJson('/api/hr-transactions/calculate', $this->payload($override))->assertUnprocessable();
        }
    }

    public function test_save_recalculates_and_preserves_dates_days_and_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = Employee::create(['employee_number' => 'LEAVE-DATES', 'full_name' => 'Leave Test', 'hire_date' => '2022-01-01', 'status' => 'active']);
        $payload = $this->payload(['employee_id' => $employee->id, 'net_amount' => 1]);
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk();
        $this->assertDatabaseCount('hr_transactions', 0);
        $id = $this->postJson('/api/hr-transactions', $payload)->assertCreated()->assertJsonPath('net_amount', '4200.00')->json('id');
        $this->getJson('/api/hr-transactions/'.$id)->assertOk()
            ->assertJsonPath('transaction.input_snapshot_json.last_return_from_leave_date', '2023-01-01')
            ->assertJsonPath('transaction.calculation_snapshot_json.accrual_details.accrued_days', '21')
            ->assertJsonPath('transaction.calculation_snapshot_json.gross_leave_amount', '4200');
        $this->assertDatabaseHas('leave_settlement_details', ['hr_transaction_id' => $id, 'accrued_days' => 21, 'gross_leave_amount' => 4200]);
    }

    public function test_263_day_calculation_keeps_precision_and_saved_amount_on_reopen(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $employee = Employee::create(['employee_number' => 'LEAVE-360', 'full_name' => 'Leave 360 Test', 'hire_date' => '2022-01-01', 'status' => 'active']);
        $payload = $this->payload([
            'employee_id' => $employee->id, 'hire_date' => '2022-01-01',
            'last_return_from_leave_date' => '2026-01-22', 'last_working_date' => '2026-10-15',
            'net_amount' => 1,
        ]);
        $id = $this->postJson('/api/hr-transactions', $payload)->assertCreated()
            ->assertJsonPath('gross_amount', '3068.33')->assertJsonPath('net_amount', '3068.33')->json('id');
        $this->getJson('/api/hr-transactions/'.$id)->assertOk()
            ->assertJsonPath('transaction.calculation_snapshot_json.accrual_details.accrued_days', '15.341666666666')
            ->assertJsonPath('transaction.calculation_snapshot_json.accrual_details.accrual_details.service_days', 263)
            ->assertJsonPath('transaction.calculation_snapshot_json.gross_leave_amount', '3068.33');
        $this->assertDatabaseHas('leave_settlement_details', [
            'hr_transaction_id' => $id, 'gross_leave_amount' => 3068.33,
        ]);
    }

    public function test_screenshot_period_distinguishes_requested_leave_from_full_balance_compensation(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $payload = $this->payload(['hire_date' => '2024-03-01', 'last_return_from_leave_date' => '2024-03-01',
            'last_working_date' => '2026-10-18', 'basic_salary' => 3500, 'contractual_annual_leave_days' => 21]);
        $this->postJson('/api/hr-transactions/calculate', array_merge($payload, [
            'leave_transaction_type' => 'annual_leave_pay', 'requested_leave_days' => 5,
        ]))->assertOk()->assertJsonPath('available_days', '55.241666666666')
            ->assertJsonPath('remaining_balance', '50.241666666666')->assertJsonPath('gross_leave_amount', '583.33');
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()
            ->assertJsonPath('available_days', '55.241666666666')->assertJsonPath('remaining_balance', '0')
            ->assertJsonPath('gross_leave_amount', '6444.86');
        $this->postJson('/api/hr-transactions/calculate', array_merge($payload, ['requested_leave_days' => 5, 'days_to_compensate' => 5]))
            ->assertOk()->assertJsonPath('payable_days', '55.241666666666')->assertJsonPath('gross_leave_amount', '6444.86');
    }
}
