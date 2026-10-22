<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\HRTransactions\TransactionExportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class HrTransactionExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_hr_can_export_xlsx(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs($this->owner, 'sanctum')
            ->get($this->url($transaction, 'excel'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_user_without_export_permission_cannot_export_excel(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs(User::factory()->create(['role' => 'guest']), 'sanctum')
            ->getJson($this->url($transaction, 'excel'))
            ->assertForbidden();
    }

    public function test_missing_transaction_excel_export_returns_404(): void
    {
        $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/hr-transactions/99999/export/excel')
            ->assertNotFound();
    }

    public function test_xlsx_workbook_contains_all_seven_arabic_sheets(): void
    {
        $transaction = $this->saveFinal();
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $spreadsheet = $this->loadWorkbook($response->getContent());

        $this->assertSame([
            'ملخص المعاملة',
            'المستحقات',
            'الخصومات',
            'تفاصيل الحساب',
            'السند النظامي',
            'سجل التعديلات',
            'سجل سير المعاملة',
        ], $spreadsheet->getSheetNames());
    }

    public function test_excel_filename_contains_reference_and_transaction_type(): void
    {
        $transaction = $this->saveFinal();
        $this->actingAs($this->owner, 'sanctum')
            ->get($this->url($transaction, 'excel'))
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$transaction->reference_number.'-final-settlement.xlsx"');
    }

    public function test_excel_export_preserves_calculated_and_approved_amounts(): void
    {
        $transaction = $this->saveFinal();
        $item = $transaction->items()->first();
        $this->adjust($transaction, $item->id, 1234.5);

        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $spreadsheet = $this->loadWorkbook($response->getContent());
        $sheet = $spreadsheet->getSheetByName('المستحقات');

        $this->assertEquals((float) $item->calculated_amount, (float) $sheet->getCell('F2')->getValue());
        $this->assertEquals(1234.5, (float) $sheet->getCell('G2')->getValue());
        $this->assertSame('نعم', $sheet->getCell('I2')->getValue());
    }

    public function test_excel_export_includes_manual_adjustment_reason(): void
    {
        $transaction = $this->saveFinal();
        $this->adjust($transaction, $transaction->items()->first()->id, 1234.5, 'تسوية معتمدة');

        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $rows = $this->loadWorkbook($response->getContent())->getSheetByName('المستحقات')->toArray();

        $this->assertSame('تسوية معتمدة', $rows[1][9]);
    }

    public function test_excel_export_includes_only_saved_legal_references(): void
    {
        $transaction = $this->saveFinal();
        $transaction->update(['legal_rules_snapshot_json' => [[
            'article_number' => '84',
            'title' => 'مكافأة نهاية الخدمة',
            'source_name' => 'HRSD',
        ]]]);

        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $rows = $this->loadWorkbook($response->getContent())->getSheetByName('السند النظامي')->toArray();

        $this->assertSame('84', (string) $rows[1][0]);
        $this->assertSame('مكافأة نهاية الخدمة', $rows[1][1]);
        $this->assertCount(2, $rows);
    }

    public function test_excel_workbook_includes_current_workflow_status_and_version(): void
    {
        $transaction = $this->saveFinal();
        $transaction->update(['status' => 'approved', 'version_number' => 3]);
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $rows = $this->loadWorkbook($response->getContent())->getSheetByName('ملخص المعاملة')->toArray();
        $summary = array_column(array_slice($rows, 1), 1, 0);

        $this->assertSame(3, (int) $summary['الإصدار']);
        $this->assertSame('approved', $summary['حالة المعاملة']);
    }

    public function test_leave_settlement_excel_includes_saved_leave_details(): void
    {
        $transaction = $this->saveLeave();
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();
        $spreadsheet = $this->loadWorkbook($response->getContent());
        $summary = array_column(array_slice($spreadsheet->getSheetByName('ملخص المعاملة')->toArray(), 1), 1, 0);

        $this->assertSame('annual_leave_pay', $summary['نوع معاملة الإجازة']);
        $this->assertGreaterThan(0, (float) $summary['مبلغ التصفية']);
    }

    public function test_excel_export_creates_audit_log(): void
    {
        $transaction = $this->saveFinal();
        $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'excel'))->assertOk();

        $this->assertAuditCreated($transaction, 'transaction_exported_excel');
    }

    public function test_hr_cannot_export_a_transaction_owned_by_another_hr_user(): void
    {
        $transaction = $this->saveFinal();
        $otherHr = User::factory()->create(['role' => 'hr']);

        $this->actingAs($otherHr, 'sanctum')
            ->getJson($this->url($transaction, 'excel'))
            ->assertForbidden();
    }

    public function test_authorized_reviewer_can_export_pdf(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'sanctum')
            ->get($this->url($transaction, 'pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_without_export_permission_cannot_export_pdf(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs(User::factory()->create(['role' => 'guest']), 'sanctum')
            ->getJson($this->url($transaction, 'pdf'))
            ->assertForbidden();
    }

    public function test_pdf_response_is_a_real_pdf_with_a_safe_filename(): void
    {
        $transaction = $this->saveFinal();
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $response->assertHeader('Content-Disposition', 'attachment; filename="'.$transaction->reference_number.'-final-settlement.pdf"');
    }

    public function test_final_settlement_pdf_uses_its_document_title(): void
    {
        $transaction = $this->saveFinal();
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_leave_settlement_pdf_is_generated(): void
    {
        $transaction = $this->saveLeave();
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('leave-settlement.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_approved_and_unapproved_pdfs_have_distinct_saved_statuses(): void
    {
        $approvedTransaction = $this->saveFinal();
        $approvedTransaction->update(['status' => 'approved']);
        $approvedPdf = $this->actingAs($this->owner, 'sanctum')->get($this->url($approvedTransaction, 'pdf'))->assertOk();
        $this->assertPdfHasText($approvedPdf->getContent(), 'معتمدة', $approvedTransaction);

        $unapprovedTransaction = $this->saveFinal();
        $unapprovedTransaction->update(['status' => 'pending_review']);
        $unapprovedPdf = $this->actingAs($this->owner, 'sanctum')->get($this->url($unapprovedTransaction, 'pdf'))->assertOk();
        $this->assertPdfHasText($unapprovedPdf->getContent(), 'غير معتمدة', $unapprovedTransaction);
    }

    public function test_pdf_preserves_manual_adjustment_data(): void
    {
        $transaction = $this->saveFinal();
        $this->adjust($transaction, $transaction->items()->first()->id, 1234.5, 'تسوية معتمدة');
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $this->assertPdfHasText($response->getContent(), 'القيمة المحسوبة', $transaction);
    }

    public function test_pdf_includes_the_saved_net_amount(): void
    {
        $transaction = $this->saveFinal();
        $expectedNet = number_format((float) $transaction->net_amount, 2);
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertGreaterThan(0, (float) $expectedNet);
        $this->assertPdfHasText($response->getContent(), 'صافي المستحق', $transaction);
    }

    public function test_pdf_includes_saved_legal_review_and_reference_data(): void
    {
        $transaction = $this->saveFinal();
        $transaction->update([
            'review_status' => 'requires_legal_review',
            'warnings_snapshot_json' => [['message' => 'مراجعة قانونية مطلوبة']],
            'legal_rules_snapshot_json' => [['article_number' => '84', 'title' => 'مكافأة نهاية الخدمة', 'source_name' => 'HRSD']],
        ]);
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertPdfHasText($response->getContent(), 'التقييم النظامي', $transaction);
        $this->assertPdfHasText($response->getContent(), 'السند النظامي', $transaction);
    }

    public function test_pdf_export_creates_audit_entry(): void
    {
        $transaction = $this->saveFinal();
        $this->actingAs($this->owner, 'sanctum')->get($this->url($transaction, 'pdf'))->assertOk();

        $this->assertAuditCreated($transaction, 'transaction_exported_pdf');
    }

    public function test_print_data_requires_authentication(): void
    {
        $transaction = $this->saveFinal();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($transaction, 'print'))->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_load_print_data(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs(User::factory()->create(['role' => 'guest']), 'sanctum')
            ->getJson($this->url($transaction, 'print'))
            ->assertForbidden();
    }

    public function test_print_data_uses_saved_values_and_version(): void
    {
        $transaction = $this->saveFinal();
        $transaction->update(['version_number' => 4]);
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->url($transaction, 'print'))
            ->assertOk()
            ->assertJsonPath('transaction.id', $transaction->id)
            ->assertJsonPath('transaction.version_number', 4);

        $this->assertSame(
            $transaction->items()->first()->calculated_amount,
            $response->json('items.0.calculated_amount'),
        );
    }

    public function test_print_request_records_audit_log_with_version(): void
    {
        $transaction = $this->saveFinal();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url($transaction, 'print'))
            ->assertOk();

        $this->assertAuditCreated($transaction, 'transaction_printed');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction_printed',
            'entity_id' => $transaction->id,
            'new_values_json' => json_encode(['version' => 1]),
        ]);
    }

    public function test_print_data_is_not_available_for_missing_transaction(): void
    {
        $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/hr-transactions/98765/print')
            ->assertNotFound();
    }

    private User $owner;

    private function saveFinal(): HrTransaction
    {
        $this->owner = $this->owner ?? User::factory()->create(['role' => 'hr']);
        [$employee, $contract] = $this->employeeAndContract();
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/hr-transactions', [
            'transaction_type' => 'final_settlement',
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'transaction_date' => '2026-10-17',
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-10-17',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'employee_loan' => 100,
        ])->assertCreated();

        return HrTransaction::findOrFail($response->json('id'));
    }

    private function saveLeave(): HrTransaction
    {
        $this->owner = $this->owner ?? User::factory()->create(['role' => 'hr']);
        [$employee, $contract] = $this->employeeAndContract();
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/hr-transactions', [
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
        ])->assertCreated();

        return HrTransaction::findOrFail($response->json('id'));
    }

    private function employeeAndContract(): array
    {
        $employee = Employee::create([
            'employee_number' => 'EX-'.uniqid(),
            'full_name' => 'موظف اختبار',
            'job_title' => 'مدير',
            'department' => 'الموارد البشرية',
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

    private function authorizedUser(): User
    {
        return $this->owner ??= User::factory()->create(['role' => 'hr']);
    }

    private function adjust(HrTransaction $transaction, int $itemId, float $amount, string $reason = 'تعديل بعد المراجعة'): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->patchJson("/api/hr-transactions/{$transaction->id}/items/{$itemId}/adjust", [
                'approved_amount' => $amount,
                'reason' => $reason,
            ])
            ->assertOk();
    }

    private function url(HrTransaction $transaction, string $format): string
    {
        if ($format === 'print') {
            return "/api/hr-transactions/{$transaction->id}/print";
        }

        return "/api/hr-transactions/{$transaction->id}/export/{$format}";
    }

    private function loadWorkbook(string $contents)
    {
        $path = tempnam(sys_get_temp_dir(), 'hr-export-');
        if ($path === false) {
            $this->fail('Unable to allocate a temporary XLSX test file.');
        }

        try {
            file_put_contents($path, $contents);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function assertAuditCreated(HrTransaction $transaction, string $action): void
    {
        $this->assertTrue(AuditLog::where('entity_id', $transaction->id)->where('action', $action)->exists());
    }

    private function assertPdfHasText(string $pdf, string $text, HrTransaction $transaction): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/ToUnicode', $pdf);
        $data = app(TransactionExportData::class)->build($transaction);
        $html = view('exports.hr-transaction-pdf', compact('data'))->render();
        $this->assertStringContainsString($text, $html);
    }
}
