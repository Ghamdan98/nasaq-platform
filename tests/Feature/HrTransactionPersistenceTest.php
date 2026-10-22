<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\HrTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrTransactionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_transaction_calculation_endpoint_does_not_persist(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();

        $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions/calculate', $this->finalSettlementPayload($employee, $contract))
            ->assertOk();

        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_saving_final_settlement_creates_hr_transaction(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();

        $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions', $this->finalSettlementPayload($employee, $contract))
            ->assertCreated()
            ->assertJsonPath('transaction_type', 'final_settlement');

        $this->assertDatabaseHas('hr_transactions', ['employee_id' => $employee->id, 'transaction_type' => 'final_settlement']);
    }

    public function test_saving_leave_settlement_creates_hr_transaction(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();

        $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions', $this->leaveSettlementPayload($employee, $contract))
            ->assertCreated()
            ->assertJsonPath('transaction_type', 'leave_settlement');

        $this->assertDatabaseHas('hr_transactions', ['employee_id' => $employee->id, 'transaction_type' => 'leave_settlement']);
    }

    public function test_final_settlement_reference_number_is_generated(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();

        $response = $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions', $this->finalSettlementPayload($employee, $contract))
            ->assertCreated();

        $this->assertMatchesRegularExpression('/^SET-\d{4}-\d{6}$/', $response->json('reference_number'));
    }

    public function test_leave_settlement_reference_number_is_generated(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();

        $response = $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions', $this->leaveSettlementPayload($employee, $contract))
            ->assertCreated();

        $this->assertMatchesRegularExpression('/^LEV-\d{4}-\d{6}$/', $response->json('reference_number'));
    }

    public function test_reference_numbers_are_unique(): void
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();
        $user = $this->makeUser();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/hr-transactions', $this->finalSettlementPayload($employee, $contract))
            ->assertCreated()
            ->json('reference_number');
        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/hr-transactions', $this->finalSettlementPayload($employee, $contract))
            ->assertCreated()
            ->json('reference_number');

        $this->assertNotSame($first, $second);
    }

    public function test_input_snapshot_is_stored(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->assertSame(6000, $transaction->fresh()->input_snapshot_json['basic_salary']);
    }

    public function test_calculation_snapshot_is_stored(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->assertArrayHasKey('entitlements', $transaction->fresh()->calculation_snapshot_json);
    }

    public function test_legal_rule_snapshot_is_stored(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->assertIsArray($transaction->fresh()->legal_rules_snapshot_json);
    }

    public function test_warnings_snapshot_is_stored(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->assertIsArray($transaction->fresh()->warnings_snapshot_json);
    }

    public function test_client_submitted_totals_are_ignored(): void
    {
        $transaction = $this->saveFinalSettlement([
            'gross_amount' => 1,
            'net_amount' => 1,
            'total_deductions' => 1,
        ]);

        $this->assertNotSame('1.00', $transaction->gross_amount);
        $this->assertNotSame('1.00', $transaction->net_amount);
    }

    public function test_calculated_amount_is_preserved_when_saved(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->assertGreaterThan(0, (float) $transaction->items()->first()->calculated_amount);
    }

    public function test_approved_amount_initially_equals_calculated_amount(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();

        $this->assertSame($item->calculated_amount, $item->approved_amount);
    }

    public function test_admin_can_adjust_approved_amount(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();

        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '17500.00',
                'reason' => 'تعديل بعد المراجعة',
            ])
            ->assertOk()
            ->assertJsonPath('item.approved_amount', '17500.00');
    }

    public function test_admin_can_adjust_a_deduction_when_item_ids_overlap(): void
    {
        $transaction = $this->saveFinalSettlement(['employee_loan' => 500]);
        $deduction = $transaction->deductions()->first();
        $this->assertNotNull($deduction);
        $this->assertSame($transaction->items()->first()->id, $deduction->id);

        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$deduction->id}/adjust", [
                'item_type' => 'deduction',
                'approved_amount' => '125.00',
                'reason' => 'تعديل الخصم',
            ])
            ->assertOk()
            ->assertJsonPath('item.approved_amount', '125.00');
    }

    public function test_unauthorized_user_cannot_adjust_item(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();

        $this->actingAs($this->makeUser('hr'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '100.00',
                'reason' => 'Unauthorized adjustment',
            ])
            ->assertForbidden();
    }

    public function test_adjustment_reason_is_required(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();

        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '100.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_adjustment_does_not_overwrite_calculated_amount(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $calculated = $item->calculated_amount;

        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '123.45',
                'reason' => 'Correction',
            ])
            ->assertOk();

        $this->assertSame($calculated, $item->fresh()->calculated_amount);
    }

    public function test_totals_recalculate_using_approved_amounts(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '123.45',
                'reason' => 'Correction',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(
            (float) $transaction->fresh()->items()->sum('approved_amount') - (float) $transaction->fresh()->deductions()->sum('approved_amount'),
            (float) $transaction->fresh()->net_amount,
            0.000001,
        );
    }

    public function test_adjustment_history_is_created(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '123.45',
                'reason' => 'Correction',
            ])
            ->assertOk();

        $this->assertDatabaseHas('transaction_adjustments', [
            'hr_transaction_id' => $transaction->id,
            'item_id' => $item->id,
            'reason' => 'Correction',
        ]);
    }

    public function test_adjustment_creates_audit_log(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
                'approved_amount' => '123.45',
                'reason' => 'Correction',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'item_adjusted',
            'entity_id' => $transaction->id,
        ]);
    }

    public function test_revert_restores_approved_amount_to_calculated_amount(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $calculated = $item->calculated_amount;
        $user = $this->makeUser('admin');
        $this->actingAs($user, 'sanctum')->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
            'approved_amount' => '123.45',
            'reason' => 'Correction',
        ])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/revert-adjustment")
            ->assertOk();

        $this->assertSame($calculated, $item->fresh()->approved_amount);
    }

    public function test_revert_preserves_adjustment_history(): void
    {
        $transaction = $this->saveFinalSettlement();
        $item = $transaction->items()->first();
        $user = $this->makeUser('admin');
        $this->actingAs($user, 'sanctum')->patchJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/adjust", [
            'approved_amount' => '123.45',
            'reason' => 'Correction',
        ])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/hr-transactions/{$transaction->id}/items/{$item->id}/revert-adjustment")->assertOk();

        $this->assertSame(2, $transaction->adjustments()->count());
    }

    public function test_transaction_index_returns_saved_transactions(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->actingAs(User::findOrFail($transaction->created_by), 'sanctum')
            ->getJson('/api/hr-transactions')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_transaction_show_returns_saved_details(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->actingAs(User::findOrFail($transaction->created_by), 'sanctum')
            ->getJson("/api/hr-transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('transaction.id', $transaction->id)
            ->assertJsonStructure(['financial_items', 'deductions', 'manual_adjustments', 'legal_references']);
    }

    public function test_transaction_filters_work_for_type_reference_and_status(): void
    {
        $transaction = $this->saveFinalSettlement();

        $this->actingAs(User::findOrFail($transaction->created_by), 'sanctum')
            ->getJson('/api/hr-transactions?transaction_type=final_settlement&status=calculated&reference_number='.$transaction->reference_number)
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs(User::findOrFail($transaction->created_by), 'sanctum')
            ->getJson('/api/hr-transactions?transaction_type=leave_settlement')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    private function saveFinalSettlement(array $overrides = []): HrTransaction
    {
        [$employee, $contract] = $this->makeEmployeeAndContract();
        $response = $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/hr-transactions', array_merge($this->finalSettlementPayload($employee, $contract), $overrides))
            ->assertCreated();

        return HrTransaction::findOrFail($response->json('id'));
    }

    private function makeEmployeeAndContract(): array
    {
        $employee = Employee::create([
            'employee_number' => 'EMP-'.uniqid(),
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

        return [$employee, $contract];
    }

    private function makeUser(string $role = 'hr'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function finalSettlementPayload(Employee $employee, Contract $contract): array
    {
        return [
            'transaction_type' => 'final_settlement',
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'transaction_date' => '2026-10-17',
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-10-17',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ];
    }

    private function leaveSettlementPayload(Employee $employee, Contract $contract): array
    {
        return [
            'transaction_type' => 'leave_settlement',
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'transaction_date' => '2026-10-17',
            'leave_transaction_type' => 'annual_leave_pay',
            'basic_salary' => 6000,
            'opening_balance' => 10,
            'accrued_days' => 5,
            'used_days' => 2,
            'adjustment_days' => 1,
            'requested_leave_days' => 5,
            'balance_source' => 'calculated',
        ];
    }
}
