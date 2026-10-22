<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\Advances\AdvanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin, 'sanctum');
        $this->employee = Employee::create(['employee_number' => 'MANAGE1', 'full_name' => 'موظف اختبار', 'company' => 'اختبار', 'hire_date' => '2020-01-01', 'status' => 'active']);
    }

    private function advance(): EmployeeAdvance
    {
        return EmployeeAdvance::create(['employee_id' => $this->employee->id, 'company' => 'اختبار', 'reference_number' => 'A1', 'original_amount' => 5000, 'created_by' => $this->admin->id]);
    }

    private function settlement(): HrTransaction
    {
        return HrTransaction::create(['employee_id' => $this->employee->id, 'reference_number' => 'S1', 'transaction_type' => 'final_settlement', 'transaction_date' => now()->toDateString(), 'status' => 'calculated', 'created_by' => $this->admin->id, 'gross_amount' => 100, 'total_deductions' => 0, 'net_amount' => 100]);
    }

    private function deletion(string $kind, int $id, array $overrides = [])
    {
        return $this->deleteJson("/api/data-management/$kind/$id", array_merge(['reason' => 'سجل تجريبي أدخل بالخطأ', 'confirmation' => 'DELETE-'.$id, 'incorrect_data' => true], $overrides));
    }

    public function test_admin_only_even_if_non_admin_has_finance_permissions(): void
    {
        $advance = $this->advance();
        $this->actingAs(User::factory()->create(['role' => 'hr', 'permissions_json' => ['post_settlements', 'manage_advances']]), 'sanctum');
        $this->getJson('/api/data-management?kind=advances')->assertForbidden();
        $this->getJson('/api/data-management/advances/'.$advance->id)->assertForbidden();
        $this->deletion('advances', $advance->id)->assertForbidden();
        $this->putJson('/api/data-management/advances/'.$advance->id, [])->assertForbidden();
        $this->assertModelExists($advance);
    }

    public function test_delete_request_requires_confirmation_and_saves_encrypted_complete_backup(): void
    {
        $advance = $this->advance();
        $this->getJson('/api/data-management/advances/'.$advance->id)->assertOk()->assertJsonPath('can_delete', true);
        $this->deletion('advances', $advance->id, ['confirmation' => 'wrong'])->assertUnprocessable();
        $this->assertModelExists($advance);
        $this->deletion('advances', $advance->id)->assertOk();
        $this->assertModelMissing($advance);
        $log = AuditLog::where('action', 'data_permanently_deleted')->firstOrFail();
        $content = Storage::disk('local')->get($log->new_values_json['backup']);
        $this->assertStringNotContainsString('original_amount', $content);
        $backup = json_decode(Crypt::decryptString($content), true);
        $this->assertSame('5000.00', $backup['original_amount']);
        $this->assertArrayHasKey('documents', $backup);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_edit_resets_approval_and_plan_but_disbursed_advance_cannot_be_edited_or_deleted(): void
    {
        $advance = $this->advance();
        $advance->update(['status' => 'approved', 'approved_by' => $this->admin->id, 'approved_at' => now(), 'repayment_plan' => [['amount' => 5000]]]);
        $this->putJson('/api/data-management/advances/'.$advance->id, ['original_amount' => 6000, 'reason' => 'تصحيح بيانات طلب السلفة'])->assertOk()->assertJsonPath('status', 'requested')->assertJsonPath('approved_at', null)->assertJsonPath('repayment_plan', null);
        $advance->refresh()->update(['status' => 'approved']);
        app(AdvanceLedger::class)->movement($advance, $this->admin, 'disbursement', ['transaction_date' => now()->toDateString(), 'method' => 'bank', 'reference_number' => 'PAY1', 'idempotency_key' => 'PAY1']);
        $this->deletion('advances', $advance->id)->assertUnprocessable();
        $this->putJson('/api/data-management/advances/'.$advance->id, ['original_amount' => 50, 'reason' => 'تصحيح بيانات طلب السلفة'])->assertUnprocessable();
        $this->assertSame('6000', app(AdvanceLedger::class)->balance($advance->fresh()));
    }

    public function test_delete_draft_releases_reservations_and_cascades_details_but_approved_is_blocked(): void
    {
        $advance = $this->advance();
        $settlement = $this->settlement();
        $settlement->advanceAllocations()->create(['advance_id' => $advance->id, 'allocated_amount' => 50, 'status' => 'reserved']);
        $this->deletion('advances', $advance->id)->assertUnprocessable();
        $settlement->update(['status' => 'approved', 'approved_at' => now()]);
        $this->deletion('settlements', $settlement->id)->assertUnprocessable();
        $settlement->update(['status' => 'calculated', 'approved_at' => null]);
        $this->deletion('settlements', $settlement->id)->assertOk();
        $this->assertModelMissing($settlement);
        $this->assertDatabaseCount('settlement_advance_allocations', 0);
        $this->deletion('advances', $advance->id)->assertOk();
    }

    public function test_cancelled_settlement_keeps_balanced_loan_entries_and_cannot_erase_unreversed_payment(): void
    {
        $settlement = $this->settlement();
        $settlement->update(['status' => 'cancelled', 'financial_stage' => 'cancelled']);
        $payment = $settlement->payments()->create(['reference_number' => 'P1', 'amount' => 100, 'payment_date' => now()->toDateString(), 'method' => 'bank', 'created_by' => $this->admin->id]);
        $this->deletion('settlements', $settlement->id)->assertUnprocessable();
        $settlement->payments()->create(['reference_number' => 'RP1', 'amount' => 100, 'payment_date' => now()->toDateString(), 'method' => 'reversal', 'reversal_of_id' => $payment->id, 'created_by' => $this->admin->id]);
        $advance = $this->advance();
        $entry = $advance->transactions()->create(['transaction_type' => 'settlement', 'amount' => 50, 'direction' => -1, 'transaction_date' => now()->toDateString(), 'reference_number' => 'L1', 'idempotency_key' => 'L1', 'hr_transaction_id' => $settlement->id, 'created_by' => $this->admin->id]);
        $this->deletion('settlements', $settlement->id)->assertUnprocessable();
        $advance->transactions()->create(['transaction_type' => 'reversal', 'amount' => 50, 'direction' => 1, 'transaction_date' => now()->toDateString(), 'reference_number' => 'RL1', 'idempotency_key' => 'RL1', 'reversal_of_id' => $entry->id, 'hr_transaction_id' => $settlement->id, 'created_by' => $this->admin->id]);
        $this->deletion('settlements', $settlement->id)->assertOk();
        $this->assertDatabaseCount('settlement_payments', 0);
        $this->assertDatabaseCount('advance_transactions', 2);
        $this->assertDatabaseHas('advance_transactions', ['id' => $entry->id, 'hr_transaction_id' => null]);
        $this->assertSame('0', app(AdvanceLedger::class)->balance($advance));
    }

    public function test_archive_and_restore_only_change_visibility_not_financial_state(): void
    {
        $advance = $this->advance();
        $url = '/api/data-management/advances/'.$advance->id.'/archive';
        $data = ['archived' => true, 'reason' => 'أرشفة سجل ملغى للاختبار'];
        $this->postJson($url, $data)->assertUnprocessable();
        $advance->update(['status' => 'cancelled']);
        $this->postJson($url, $data)->assertOk();
        $this->getJson('/api/advances')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/data-management?kind=advances&archived=yes')->assertOk()->assertJsonPath('total', 1);
        $this->postJson($url, array_merge($data, ['archived' => false]))->assertOk();
        $this->assertSame('cancelled', $advance->fresh()->status);
        $this->getJson('/api/advances')->assertOk()->assertJsonPath('total', 1);
        $settlement = $this->settlement();
        $settlement->update(['status' => 'cancelled']);
        $url = '/api/data-management/settlements/'.$settlement->id.'/archive';
        $this->postJson($url, $data)->assertOk();
        $this->getJson('/api/hr-transactions')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($url, array_merge($data, ['archived' => false]))->assertOk();
        $this->assertSame('cancelled', $settlement->fresh()->status);
    }

    public function test_backup_failure_prevents_deletion_and_audit_is_not_committed(): void
    {
        $advance = $this->advance();
        Storage::shouldReceive('disk')->with('local')->once()->andReturn(new class
        {
            public function put($path, $contents): bool
            {
                return false;
            }
        });
        $this->deletion('advances', $advance->id)->assertStatus(500);
        $this->assertModelExists($advance);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'data_permanently_deleted']);
    }

    public function test_cancelled_reversed_advance_can_be_deleted_without_foreign_key_errors(): void
    {
        $advance = $this->advance();
        $ledger = app(AdvanceLedger::class);
        $ledger->approve($advance, $this->admin, 'APP1');
        $entry = $ledger->movement($advance, $this->admin, 'disbursement', ['transaction_date' => now()->toDateString(), 'method' => 'bank', 'reference_number' => 'D1', 'idempotency_key' => 'D1']);
        $ledger->reverse($advance, $entry, $this->admin, ['transaction_date' => now()->toDateString(), 'reference_number' => 'RD1', 'notes' => 'عكس حركة تجريبية']);
        $this->deletion('advances', $advance->id)->assertOk();
        $this->assertDatabaseCount('advance_transactions', 0);
        $this->assertModelMissing($advance);
    }
}
