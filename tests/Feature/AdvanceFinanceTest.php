<?php

namespace Tests\Feature;

use App\Models\AdvanceTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\Advances\AdvanceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvanceFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin, 'sanctum');
        $this->employee = Employee::create(['employee_number' => 'FIN001', 'full_name' => 'موظف اختبار مالي', 'company' => 'شركة الاختبار', 'hire_date' => '2020-01-01', 'basic_salary' => 6000, 'status' => 'active']);
    }

    private function advance(string $amount = '5000', bool $disburse = true): int
    {
        $id = $this->postJson('/api/advances', ['employee_id' => $this->employee->id, 'reference_number' => 'ADV-'.uniqid(), 'original_amount' => $amount])->assertCreated()->assertJsonPath('balance', '0')->json('id');
        if ($disburse) {
            $this->postJson('/api/advances/'.$id.'/approve', ['approval_reference' => 'APPROVAL-'.$id])->assertOk();
            $this->movement($id, 'disbursement');
        }

        return $id;
    }

    private function movement(int $id, string $type, string $amount = '0', ?string $key = null)
    {
        $key ??= uniqid('operation-');

        return $this->postJson('/api/advances/'.$id.'/movements', ['type' => $type, 'amount' => $amount, 'transaction_date' => now()->toDateString(), 'reference_number' => $key, 'idempotency_key' => $key, 'method' => 'bank'])->assertCreated();
    }

    private function payload(array $decision = [], bool $leave = false): array
    {
        return array_merge(['employee_id' => $this->employee->id, 'transaction_type' => 'final_settlement', 'transaction_date' => now()->toDateString(), 'last_working_date' => '2026-01-01', 'termination_type' => 'termination', 'actual_notice_days' => 60, 'remaining_leave_days' => 0, 'unpaid_days' => 0, 'advance_decision' => $decision], $leave ? ['transaction_type' => 'leave_settlement', 'leave_transaction_type' => 'annual_leave_pay', 'opening_balance' => 10, 'requested_leave_days' => 5] : []);
    }

    private function settlement(array $decision, bool $leave = false): int
    {
        return $this->postJson('/api/hr-transactions', $this->payload($decision, $leave))->assertCreated()->json('id');
    }

    private function review(int $id, array $override = [])
    {
        return $this->postJson('/api/hr-transactions/'.$id.'/financial-review', array_merge(['deduction_source' => 'non_wage', 'deductible_limit' => '50000', 'debt_verified' => true, 'documents_verified' => true, 'protected_rights_reviewed' => true, 'reason' => 'تم فحص المستندات والأرصدة ومصدر الخصم'], $override));
    }

    private function approve(int $id): void
    {
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->review($id)->assertOk()->assertJsonPath('financial_stage', 'pending_approval');
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertOk();
    }

    private function balance(int $id): string
    {
        return app(AdvanceLedger::class)->balance(EmployeeAdvance::findOrFail($id));
    }

    public function test_requests_do_not_create_debt_and_unapproved_disbursement_is_blocked(): void
    {
        $id = $this->advance('5000', false);
        $this->getJson('/api/employees/'.$this->employee->id.'/advances')->assertOk()->assertJsonPath('total_balance', '0');
        $this->postJson('/api/advances/'.$id.'/movements', ['type' => 'disbursement', 'transaction_date' => now()->toDateString(), 'reference_number' => 'D1', 'idempotency_key' => 'D1', 'method' => 'bank'])->assertUnprocessable();
        $this->assertSame('0', $this->balance($id));
    }

    public function test_full_5000_1500_3500_cycle_posting_once_payment_and_cancellation(): void
    {
        $advance = $this->advance();
        $this->movement($advance, 'external_repayment', '1500');
        $this->assertSame('3500', $this->balance($advance));
        $this->getJson('/api/employees/'.$this->employee->id.'/advances')->assertOk()->assertJsonPath('items.0.balance', '3500');
        $preview = $this->postJson('/api/hr-transactions/calculate', $this->payload(['choice' => 'full']))->assertOk()->assertJsonPath('advance_summary.proposed_deduction', '3500');
        $this->assertSame('3500', $this->balance($advance));
        $id = $this->settlement(['choice' => 'full']);
        $original = HrTransaction::find($id)->input_snapshot_json;
        $this->getJson('/api/advances/'.$advance)->assertOk()->assertJsonPath('reserved_balance', '3500')->assertJsonPath('balance', '3500');
        $this->assertEquals(17500, HrTransaction::find($id)->net_amount);
        $this->approve($id);
        $this->assertSame('3500', $this->balance($advance));
        foreach ([1, 2] as $_) {
            $this->postJson('/api/hr-transactions/'.$id.'/post', ['idempotency_key' => 'POST-1'])->assertOk()->assertJsonPath('financial_stage', 'posted');
        }
        $this->assertSame('0', $this->balance($advance));
        $this->assertSame(1, AdvanceTransaction::where('transaction_type', 'settlement')->count());
        $this->postJson('/api/hr-transactions/'.$id.'/pay', ['reference_number' => 'PAY-1', 'payment_date' => now()->toDateString(), 'method' => 'bank'])->assertOk()->assertJsonPath('financial_stage', 'paid');
        $this->postJson('/api/hr-transactions/'.$id.'/financial-cancel', ['reason' => 'تصحيح مستند مالي'])->assertUnprocessable();
        $this->postJson('/api/hr-transactions/'.$id.'/financial-cancel', ['reason' => 'تم استرداد المبلغ بعكس موثق', 'payment_reversal_reference' => 'REFUND-1'])->assertOk();
        $this->assertSame('3500', $this->balance($advance));
        $this->postJson('/api/hr-transactions/'.$id.'/financial-cancel', ['reason' => 'إعادة إرسال آمن'])->assertOk();
        $this->assertSame('3500', $this->balance($advance));
        $this->assertSame($original, HrTransaction::find($id)->input_snapshot_json);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement_posted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement_financial_cancelled']);
    }

    public function test_multiple_advances_partial_selection_no_deduction_and_fully_paid_exclusion(): void
    {
        $first = $this->advance('1000');
        $second = $this->advance('2000');
        $paid = $this->advance('500');
        $this->movement($paid, 'payroll_repayment', '500');
        $this->getJson('/api/employees/'.$this->employee->id.'/advances')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('total_balance', '3000');
        $id = $this->settlement(['choice' => 'partial', 'allocations' => [['advance_id' => $second, 'amount' => '700']], 'reason' => 'تخصيص جزء من السلفة الثانية', 'remaining_debt_reason' => 'يبقى الدين للمطالبة اللاحقة']);
        $this->approve($id);
        $this->postJson('/api/hr-transactions/'.$id.'/post', ['idempotency_key' => 'POST-PARTIAL'])->assertOk();
        $this->assertSame('1000', $this->balance($first));
        $this->assertSame('1300', $this->balance($second));
        $none = $this->settlement(['choice' => 'none', 'reason' => 'استكمال الحقوق دون خصم', 'remaining_debt_reason' => 'تحصيل مستقل لاحق']);
        $this->approve($none);
        $this->postJson('/api/hr-transactions/'.$none.'/post', ['idempotency_key' => 'POST-NONE'])->assertOk();
        $this->assertSame('2300', app(AdvanceLedger::class)->summary($this->employee->id)['total_balance']);
    }

    public function test_explicit_decision_required_and_excess_debt_is_not_forgiven_or_negative(): void
    {
        $advance = $this->advance('5000');
        $id = $this->settlement([]);
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertUnprocessable();
        $this->postJson('/api/hr-transactions/calculate', $this->payload(['choice' => 'full', 'remaining_debt_reason' => 'باقي الدين قائم'], true))->assertOk()->assertJsonPath('advance_summary.proposed_deduction', '1000')->assertJsonPath('advance_summary.remaining_debt', '4000')->assertJsonPath('net_settlement', '0');
        $this->assertSame('5000', $this->balance($advance));
        $this->postJson('/api/hr-transactions/calculate', $this->payload(['choice' => 'partial', 'allocations' => [['advance_id' => $advance, 'amount' => 1200]]], true))->assertUnprocessable();
    }

    public function test_financial_and_legal_reviews_block_ambiguous_or_wage_deductions(): void
    {
        $advance = $this->advance();
        $id = $this->settlement(['choice' => 'full']);
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertUnprocessable();
        $this->review($id, ['documents_verified' => false])->assertOk()->assertJsonPath('financial_stage', 'pending_legal_review');
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertUnprocessable();
        $this->postJson('/api/hr-transactions/'.$id.'/advance-legal-review', ['approved_limit' => 5000, 'authority_reference' => 'LEGAL-CASE-1', 'reason' => 'تمت مراجعة الإثبات ومصدر الخصم والحقوق المحمية واعتماد القرار'])->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/financial-cancel', ['reason' => 'تجربة الخصم من الأجور'])->assertOk();
        $wage = $this->settlement(['choice' => 'full', 'remaining_debt_reason' => 'الدين المتبقي قائم'], true);
        $this->postJson('/api/hr-transactions/'.$wage.'/submit-review')->assertOk();
        $this->review($wage, ['deduction_source' => 'wages'])->assertOk()->assertJsonPath('financial_stage', 'pending_legal_review')->assertJsonPath('financial_review_json.policy.maximum', '100');
    }

    public function test_reservations_and_payments_while_waiting_prevent_double_deduction(): void
    {
        $advance = $this->advance();
        $first = $this->settlement(['choice' => 'full']);
        $second = $this->settlement(['choice' => 'full', 'remaining_debt_reason' => 'المديونية محجوزة في مخالصة أخرى']);
        $this->assertEquals(0, HrTransaction::find($second)->deductions()->where('code', 'employee_advances')->sum('approved_amount'));
        $this->approve($first);
        $this->movement($advance, 'external_repayment', '500');
        $this->postJson('/api/hr-transactions/'.$first.'/post', ['idempotency_key' => 'STALE'])->assertUnprocessable();
        $this->assertSame('4500', $this->balance($advance));
        $this->postJson('/api/hr-transactions/'.$first.'/financial-cancel', ['reason' => 'تحديث بعد السداد'])->assertOk();
        $this->assertSame('0', app(AdvanceLedger::class)->reserved(EmployeeAdvance::find($advance)));
    }

    public function test_repayment_idempotency_reversals_and_unauthorized_users(): void
    {
        $advance = $this->advance();
        $entry = $this->movement($advance, 'external_repayment', '1500', 'SAME-KEY')->json('id');
        $this->movement($advance, 'external_repayment', '1500', 'SAME-KEY');
        $this->postJson('/api/advances/'.$advance.'/movements', ['type' => 'external_repayment', 'amount' => 1500, 'transaction_date' => now()->toDateString(), 'reference_number' => 'SAME-KEY', 'idempotency_key' => 'DIFFERENT-KEY', 'method' => 'bank'])->assertUnprocessable();
        $this->assertSame('3500', $this->balance($advance));
        foreach ([1, 2] as $_) {
            $this->postJson('/api/advances/'.$advance.'/entries/'.$entry.'/reverse', ['transaction_date' => now()->toDateString(), 'reference_number' => 'REV-PAY', 'notes' => 'تصحيح سند سداد'])->assertCreated();
        }
        $this->assertSame('5000', $this->balance($advance));
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $this->postJson('/api/advances/'.$advance.'/approve', ['approval_reference' => 'FORBIDDEN'])->assertForbidden();
        $this->postJson('/api/advances/'.$advance.'/movements', ['type' => 'external_repayment', 'amount' => 1, 'transaction_date' => now()->toDateString(), 'reference_number' => 'NO', 'idempotency_key' => 'NO', 'method' => 'bank'])->assertForbidden();
    }

    public function test_zero_advances_legacy_approval_and_real_reports(): void
    {
        $id = $this->settlement([]);
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->review($id)->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertOk();
        $this->getJson('/api/advance-reports?kind=outstanding')->assertOk()->assertJsonPath('total', 0);
        $advance = $this->advance();
        $this->getJson('/api/advance-reports?kind=outstanding')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/advance-reports?kind=statement&employee_id='.$this->employee->id)->assertOk()->assertJsonPath('total', 1);
        $this->get('/api/advance-reports?kind=outstanding&format=xlsx')->assertOk();
        $this->get('/api/advance-reports?kind=outstanding&format=pdf')->assertOk();
    }

    public function test_hold_dispute_and_balance_change_before_approval_require_explicit_resolution(): void
    {
        $advance = $this->advance('5000');
        $id = $this->settlement(['choice' => 'hold', 'reason' => 'مطابقة المديونية مع الموظف']);
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->review($id)->assertUnprocessable();
        $this->putJson('/api/hr-transactions/'.$id.'/advance-decision', ['advance_decision' => ['choice' => 'full']])->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->review($id)->assertOk();
        $this->movement($advance, 'external_repayment', '500');
        $this->postJson('/api/hr-transactions/'.$id.'/approve')->assertUnprocessable();
        $this->putJson('/api/hr-transactions/'.$id.'/advance-decision', ['advance_decision' => ['choice' => 'full']])->assertOk();
        $this->patchJson('/api/advances/'.$advance.'/flags', ['is_disputed' => true, 'reason' => 'اعتراض الموظف على المديونية'])->assertOk();
        $this->postJson('/api/hr-transactions/'.$id.'/submit-review')->assertOk();
        $this->review($id)->assertOk()->assertJsonPath('financial_stage', 'pending_legal_review');
        $this->postJson('/api/hr-transactions/'.$id.'/post', ['idempotency_key' => 'BLOCKED'])->assertUnprocessable();
    }

    public function test_authorization_immutability_excess_repayment_and_private_documents(): void
    {
        Storage::fake('local');
        $advance = $this->advance('100');
        $this->postJson('/api/advances/'.$advance.'/movements', ['type' => 'external_repayment', 'amount' => '100.01', 'transaction_date' => now()->toDateString(), 'reference_number' => 'TOO-MUCH', 'idempotency_key' => 'TOO-MUCH', 'method' => 'bank'])->assertUnprocessable();
        $entry = AdvanceTransaction::where('advance_id', $advance)->first();
        try {
            $entry->update(['amount' => '1']);
            $this->fail('Ledger update should fail.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
        $file = UploadedFile::fake()->createWithContent('approval.pdf', '%PDF-1.4 test');
        $document = $this->post('/api/advances/'.$advance.'/documents', ['category' => 'approval', 'file' => $file], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('path')->json('id');
        $this->get('/api/advances/'.$advance.'/documents/'.$document)->assertOk();
        $other = $this->advance('200');
        $this->get('/api/advances/'.$other.'/documents/'.$document)->assertNotFound();
        $id = $this->settlement(['choice' => 'none', 'reason' => 'تحصيل مستقل', 'remaining_debt_reason' => 'يبقى الرصيد قائمًا']);
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum');
        $this->postJson('/api/hr-transactions/'.$id.'/post', ['idempotency_key' => 'NO-POST'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'sanctum');
        $this->postJson('/api/hr-transactions/'.$id.'/advance-legal-review', ['approved_limit' => 100, 'authority_reference' => 'REF', 'reason' => 'محاولة غير مخولة لتجاوز المراجعة'])->assertForbidden();
    }

    public function test_cancelling_draft_releases_holds_without_any_ledger_repayment(): void
    {
        $advance = $this->advance('5000');
        $id = $this->settlement(['choice' => 'full']);
        $this->postJson('/api/hr-transactions/'.$id.'/cancel', ['reason' => 'إلغاء مسودة قبل الاعتماد'])->assertOk();
        $this->assertSame('5000', $this->balance($advance));
        $this->assertSame('0', app(AdvanceLedger::class)->reserved(EmployeeAdvance::find($advance)));
        $this->assertSame(0, AdvanceTransaction::where('transaction_type', 'settlement')->count());
    }
}
