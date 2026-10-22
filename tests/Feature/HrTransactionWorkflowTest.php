<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\HrTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrTransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private HrTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = User::factory()->create(['role' => 'hr']);
        [$employee, $contract] = $this->employeeAndContract();
        $response = $this->actingAs($this->hr, 'sanctum')->postJson('/api/hr-transactions', [
            'transaction_type' => 'final_settlement',
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'transaction_date' => '2026-10-17',
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-10-17',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
        ])->assertCreated();

        $this->transaction = HrTransaction::findOrFail($response->json('id'));
        $this->assertSame('calculated', $this->transaction->status);
    }

    public function test_hr_can_submit_calculated_transaction_for_review(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->postJson($this->endpoint('submit-review'))
            ->assertOk()
            ->assertJsonPath('status', 'pending_review');
    }

    public function test_user_without_submit_permission_cannot_submit(): void
    {
        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('submit-review'))
            ->assertForbidden();
    }

    public function test_submit_moves_calculated_to_pending_review(): void
    {
        $this->submit();

        $this->assertSame('pending_review', $this->transaction->fresh()->status);
    }

    public function test_reviewer_can_approve_pending_transaction(): void
    {
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_hr_cannot_approve_without_permission(): void
    {
        $this->submit();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertForbidden();
    }

    public function test_approval_records_approver_and_timestamp(): void
    {
        $this->submit();
        $reviewer = $this->reviewer();
        $this->actingAs($reviewer, 'sanctum')->postJson($this->endpoint('approve'))->assertOk();
        $this->transaction->refresh();

        $this->assertSame($reviewer->id, $this->transaction->approved_by);
        $this->assertNotNull($this->transaction->approved_at);
    }

    public function test_blocked_transaction_cannot_be_approved(): void
    {
        $this->submit();
        $this->transaction->update(['review_status' => 'blocked']);

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_normal_reviewer_cannot_approve_legal_review_transaction(): void
    {
        $this->transaction->update(['review_status' => 'requires_legal_review']);
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertForbidden();
    }

    public function test_privileged_reviewer_can_approve_legal_review_transaction(): void
    {
        $this->transaction->update(['review_status' => 'requires_legal_review']);
        $this->submit();
        $reviewer = User::factory()->create([
            'role' => 'reviewer',
            'permissions_json' => ['approve_legal_review_transaction'],
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('reject'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_reviewer_can_reject_pending_transaction(): void
    {
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('reject'), ['reason' => 'بيانات غير مكتملة'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_rejection_stores_reason(): void
    {
        $this->submit();
        $reviewer = $this->reviewer();
        $this->actingAs($reviewer, 'sanctum')->postJson($this->endpoint('reject'), ['reason' => 'بيانات غير مكتملة'])->assertOk();
        $this->transaction->refresh();

        $this->assertSame('بيانات غير مكتملة', $this->transaction->rejection_reason);
        $this->assertSame($reviewer->id, $this->transaction->rejected_by);
        $this->assertNotNull($this->transaction->rejected_at);
    }

    public function test_approved_transaction_cannot_be_manually_adjusted(): void
    {
        $this->approve();
        $item = $this->transaction->items()->first();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->patchJson($this->itemEndpoint($item->id, 'adjust'), [
                'approved_amount' => '100.00',
                'reason' => 'Attempt to alter approved value',
            ])
            ->assertUnprocessable();
    }

    public function test_approved_transaction_cannot_be_updated_normally(): void
    {
        $this->approve();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->putJson($this->endpoint(), [
                'transaction_type' => 'final_settlement',
                'employee_id' => $this->transaction->employee_id,
                'contract_id' => $this->transaction->contract_id,
                'transaction_date' => '2026-10-17',
                'hire_date' => '2020-01-01',
                'last_working_date' => '2026-10-17',
                'termination_type' => 'termination',
                'basic_salary' => 7000,
            ])
            ->assertUnprocessable();
    }

    public function test_invalid_approval_transition_fails_from_calculated(): void
    {
        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertUnprocessable();
    }

    public function test_reviewer_can_request_changes(): void
    {
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('request-changes'), ['reason' => 'يرجى إرفاق المستندات'])
            ->assertOk()
            ->assertJsonPath('status', 'changes_requested');
    }

    public function test_request_changes_requires_a_reason(): void
    {
        $this->submit();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('request-changes'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_cancelled_transaction_cannot_be_approved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->endpoint('cancel'), ['reason' => 'تم إلغاء الطلب'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertUnprocessable();
    }

    public function test_cancel_requires_authorization(): void
    {
        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('cancel'), ['reason' => 'Cancel'])
            ->assertForbidden();
    }

    public function test_reopen_requires_special_permission(): void
    {
        $this->approve();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('reopen'), ['reason' => 'Reopen'])
            ->assertForbidden();
    }

    public function test_reopen_requires_a_reason(): void
    {
        $this->approve();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson($this->endpoint('reopen'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_reopening_approved_transaction_creates_next_version(): void
    {
        $this->approve();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson($this->endpoint('reopen'), ['reason' => 'تصحيح بيانات العقد'])
            ->assertOk()
            ->assertJsonPath('status', 'calculated')
            ->assertJsonPath('version_number', 2);

        $this->assertDatabaseHas('hr_transaction_versions', [
            'hr_transaction_id' => $this->transaction->id,
            'version_number' => 1,
            'status' => 'approved',
        ]);
    }

    public function test_previous_approved_version_snapshot_is_preserved(): void
    {
        $this->approve();
        $originalAmount = $this->transaction->items()->first()->approved_amount;
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson($this->endpoint('reopen'), ['reason' => 'Reopen'])
            ->assertOk();

        $version = $this->transaction->versions()->firstOrFail();
        $this->assertSame('approved', $version->status);
        $this->assertSame($originalAmount, $version->snapshot_json['items'][0]['approved_amount']);
    }

    public function test_status_history_records_submission(): void
    {
        $this->submit();

        $this->assertDatabaseHas('hr_transaction_status_history', [
            'hr_transaction_id' => $this->transaction->id,
            'from_status' => 'calculated',
            'to_status' => 'pending_review',
        ]);
    }

    public function test_status_history_records_approval(): void
    {
        $this->approve();

        $this->assertDatabaseHas('hr_transaction_status_history', [
            'hr_transaction_id' => $this->transaction->id,
            'from_status' => 'pending_review',
            'to_status' => 'approved',
        ]);
    }

    public function test_status_history_records_rejection(): void
    {
        $this->submit();
        $this->actingAs($this->reviewer(), 'sanctum')->postJson($this->endpoint('reject'), ['reason' => 'Rejected'])->assertOk();

        $this->assertDatabaseHas('hr_transaction_status_history', [
            'hr_transaction_id' => $this->transaction->id,
            'from_status' => 'pending_review',
            'to_status' => 'rejected',
        ]);
    }

    public function test_audit_log_records_workflow_actions(): void
    {
        $this->approve();

        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $this->transaction->id,
            'action' => 'submitted_for_review',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $this->transaction->id,
            'action' => 'transaction_approved',
        ]);
    }

    public function test_direct_put_cannot_set_approved_status(): void
    {
        $this->actingAs($this->hr, 'sanctum')
            ->putJson($this->endpoint(), [
                'status' => 'approved',
                'transaction_type' => 'final_settlement',
                'employee_id' => $this->transaction->employee_id,
                'contract_id' => $this->transaction->contract_id,
                'transaction_date' => '2026-10-17',
                'hire_date' => '2020-01-01',
                'last_working_date' => '2026-10-17',
                'termination_type' => 'termination',
                'basic_salary' => 6000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('calculated', $this->transaction->fresh()->status);
    }

    public function test_second_conflicting_approval_fails_after_first_transition(): void
    {
        $this->approve();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('approve'))
            ->assertUnprocessable();

        $this->assertSame(1, $this->transaction->statusHistory()->where('to_status', 'approved')->count());
    }

    public function test_resubmission_after_requested_changes_is_allowed(): void
    {
        $this->submit();
        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson($this->endpoint('request-changes'), ['reason' => 'Please correct'])
            ->assertOk();

        $this->actingAs($this->hr, 'sanctum')
            ->postJson($this->endpoint('submit-review'))
            ->assertOk()
            ->assertJsonPath('status', 'pending_review');
    }

    public function test_blocked_transaction_cannot_be_submitted_for_review(): void
    {
        $this->transaction->update(['review_status' => 'blocked']);

        $this->actingAs($this->hr, 'sanctum')
            ->postJson($this->endpoint('submit-review'))
            ->assertUnprocessable();
    }

    public function test_reopening_records_version_creation_and_reopen_audit(): void
    {
        $this->approve();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->postJson($this->endpoint('reopen'), ['reason' => 'Version the approved data'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $this->transaction->id,
            'action' => 'transaction_version_created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $this->transaction->id,
            'action' => 'transaction_reopened',
        ]);
    }

    public function test_hr_cannot_edit_another_users_transaction(): void
    {
        $otherHr = User::factory()->create(['role' => 'hr']);

        $this->actingAs($otherHr, 'sanctum')
            ->putJson($this->endpoint(), [
                'transaction_type' => 'final_settlement',
                'employee_id' => $this->transaction->employee_id,
                'contract_id' => $this->transaction->contract_id,
                'transaction_date' => '2026-10-17',
                'hire_date' => '2020-01-01',
                'last_working_date' => '2026-10-17',
                'termination_type' => 'termination',
                'basic_salary' => 6000,
            ])
            ->assertForbidden();
    }

    private function submit(): void
    {
        $this->actingAs($this->hr, 'sanctum')->postJson($this->endpoint('submit-review'))->assertOk();
    }

    private function approve(): void
    {
        $this->submit();
        $this->actingAs($this->reviewer(), 'sanctum')->postJson($this->endpoint('approve'))->assertOk();
    }

    private function reviewer(): User
    {
        return User::factory()->create(['role' => 'reviewer']);
    }

    private function employeeAndContract(): array
    {
        $employee = Employee::create([
            'employee_number' => 'WF-'.uniqid(),
            'full_name' => 'Workflow User',
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

    private function endpoint(string $action = ''): string
    {
        return '/api/hr-transactions/'.$this->transaction->id.($action ? '/'.$action : '');
    }

    private function itemEndpoint(int $itemId, string $action): string
    {
        return $this->endpoint()."/items/{$itemId}/{$action}";
    }
}
