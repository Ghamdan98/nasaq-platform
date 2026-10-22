<?php

namespace Tests\Feature;

use App\Listeners\ApplySystemMailBranding;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\HRTransactions\TransactionExportData;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->developer = User::factory()->create(['role' => 'developer', 'permissions_json' => ['manage-system-settings']]);
        $this->actingAs($this->developer, 'sanctum');
    }

    private function change(array $settings, int $revision = 0)
    {
        return $this->putJson('/api/developer/settings', ['settings' => $settings, 'revision' => $revision, 'reason' => 'تخصيص الهوية لاختبار الإعدادات']);
    }

    public function test_defaults_update_cache_revision_and_public_allowlist(): void
    {
        $defaults = $this->getJson('/api/system/public-settings')->assertOk()->json('settings');
        $this->assertSame('#2563eb', $defaults['appearance.primary']);
        $this->getJson('/api/developer/settings')->assertOk()->assertJsonStructure(['registry', 'defaults', 'revision', 'settings']);
        $this->change(['branding.name_ar' => 'هوية جديدة', 'appearance.primary' => '#7c3aed'])->assertOk()->assertJsonPath('revision', 1);
        $public = $this->getJson('/api/system/public-settings')->assertOk()->json('settings');
        $this->assertSame('هوية جديدة', $public['branding.name_ar']);
        $this->assertSame('#7c3aed', app(SystemSettingsService::class)->get('appearance.primary'));
        $this->assertArrayNotHasKey('APP_KEY', $public);
        $this->assertArrayNotHasKey('_revision', $public);
        $this->change(['branding.name_ar' => 'تعارض'], 0)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('هوية جديدة', app(SystemSettingsService::class)->get('branding.name_ar'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system_settings_updated', 'user_id' => $this->developer->id]);
    }

    public function test_admin_hr_reviewer_cannot_use_developer_api_even_with_forged_permission(): void
    {
        foreach (['admin', 'hr', 'reviewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'permissions_json' => ['manage-system-settings']]);
            $this->actingAs($user, 'sanctum');
            $this->assertFalse($user->hasPermission('manage-system-settings'));
            foreach (['/api/developer/settings', '/api/developer/settings/export', '/api/developer/health', '/api/developer/settings/audit'] as $path) {
                $this->getJson($path)->assertForbidden();
            }
            $this->change(['branding.name_ar' => 'غير مسموح'])->assertForbidden();
            $this->postJson('/api/developer/settings/reset', [])->assertForbidden();
            $this->postJson('/api/developer/settings/restore', [])->assertForbidden();
            $this->postJson('/api/developer/branding/upload', [])->assertForbidden();
        }
        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_sensitive_unknown_keys_invalid_colors_and_financial_overrides_rejected_atomically(): void
    {
        foreach (['APP_KEY', 'database.password', 'mail.password', 'settlement.year_days', 'security.disable_permissions', '_revision'] as $key) {
            $this->change([$key => 'bad', 'branding.name_ar' => 'لا يحفظ'])->assertUnprocessable();
        }
        $this->change(['appearance.primary' => 'red;display:none'])->assertUnprocessable();
        $this->change(['appearance.font_ar' => 'url(https://example.com/font)'])->assertUnprocessable();
        $this->change(['branding.website' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_upload_normalizes_raster_and_keeps_previous_logo_when_invalid(): void
    {
        $upload = $this->postJson('/api/developer/branding/upload', ['file' => UploadedFile::fake()->image('logo.png', 32, 32)])->assertCreated();
        $id = $upload->json('id');
        $this->change(['branding.logo' => $id])->assertOk();
        $this->get('/api/system/branding/'.$id)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson('/api/developer/branding/upload', ['file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg><script>alert(1)</script></svg>')])->assertUnprocessable();
        $this->postJson('/api/developer/branding/upload', ['file' => UploadedFile::fake()->image('too-big.png', 2100, 8)])->assertUnprocessable();
        $this->assertSame($id, app(SystemSettingsService::class)->get('branding.logo'));
        $this->change(['branding.logo' => '11111111-1111-4111-8111-111111111111'], 1)->assertUnprocessable();
    }

    public function test_reset_export_restore_health_and_audit(): void
    {
        $this->change(['branding.name_ar' => 'نسخة تجريبية'])->assertOk();
        $backup = $this->getJson('/api/developer/settings/export')->assertOk()->json();
        $this->postJson('/api/developer/settings/reset', ['revision' => 1, 'reason' => 'استعادة افتراضيات الاختبار', 'confirmation' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/developer/settings/reset', ['revision' => 1, 'reason' => 'استعادة افتراضيات الاختبار', 'confirmation' => 'RESET'])->assertOk();
        $this->assertSame(config('system.settings')['branding.name_ar']['default'], app(SystemSettingsService::class)->get('branding.name_ar'));
        $this->postJson('/api/developer/settings/restore', ['schema' => 1, 'settings' => $backup['settings'], 'revision' => 2, 'reason' => 'استعادة نسخة الإعدادات الآمنة', 'confirmation' => 'RESTORE'])->assertOk();
        $this->assertSame('نسخة تجريبية', app(SystemSettingsService::class)->get('branding.name_ar'));
        $this->getJson('/api/developer/settings/audit')->assertOk()->assertJsonPath('total', 3);
        $health = $this->getJson('/api/developer/health')->assertOk()->json();
        $this->assertSame('connected', $health['database']);
        $this->assertArrayNotHasKey('password', $health);
        $this->assertDatabaseCount('hr_transactions', 0);
    }

    public function test_admin_cannot_take_over_developer_and_developer_has_no_financial_authority(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        $this->putJson('/api/users/'.$this->developer->id, [])->assertForbidden();
        $this->getJson('/api/users/'.$this->developer->id.'/audit')->assertForbidden();
        $this->postJson('/api/users', ['name' => 'مزور', 'email' => 'forged@example.test', 'role' => 'developer', 'status' => 'active', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!', 'reason' => 'طلب حساب مطور عبر المدير'])->assertUnprocessable();
        $this->actingAs($this->developer, 'sanctum');
        $this->postJson('/api/hr-transactions', [])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/advances')->assertForbidden();
    }

    public function test_branding_used_in_reports_and_business_calculation_unchanged(): void
    {
        $this->change(['branding.name_ar' => 'هوية التقارير', 'reports.organization' => 'جهة الاختبار', 'appearance.primary' => '#123456', 'documents.settlement_prefix' => 'TEST'])->assertOk();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        $employee = Employee::create(['employee_number' => 'SETTING1', 'full_name' => 'موظف اختبار', 'hire_date' => '2020-01-01', 'basic_salary' => 6000, 'status' => 'active']);
        $payload = ['employee_id' => $employee->id, 'transaction_type' => 'final_settlement', 'transaction_date' => '2026-01-01', 'last_working_date' => '2026-01-01', 'termination_type' => 'termination', 'actual_notice_days' => 60, 'remaining_leave_days' => 0, 'unpaid_days' => 0];
        $saved = $this->postJson('/api/hr-transactions', $payload)->assertCreated();
        $this->assertStringStartsWith('TEST-', $saved->json('reference_number'));
        $this->assertEquals(21000, $saved->json('net_amount'));
        $data = app(TransactionExportData::class)->build(HrTransaction::findOrFail($saved->json('id')));
        $html = view('exports.hr-transaction-pdf', compact('data'))->render();
        $this->assertStringContainsString('هوية التقارير', $html);
        $this->assertStringContainsString('#123456', $html);
        $this->assertStringContainsString('جهة الاختبار', $html);
        $this->get('/api/hr-transactions/'.$saved->json('id').'/export/pdf')->assertOk();
    }

    public function test_operational_defaults_and_mail_branding_are_used_without_changing_stored_money(): void
    {
        $this->change(['display.rows' => 7, 'display.money_decimals' => 3, 'display.numbering' => 'latn', 'display.date_format' => 'yyyy-MM-dd', 'documents.advance_prefix' => 'LOAN', 'branding.name_ar' => 'هوية البريد'])->assertOk();
        $settings = app(SystemSettingsService::class);
        $this->assertSame('2026-01-02', $settings->formatDate('2026-01-02'));
        $this->assertStringContainsString('12.340', $settings->formatMoney('12.34'));
        $message = (new Email)->from('system@example.test')->to('recipient@example.test')->text('test');
        (new ApplySystemMailBranding)->handle(new MessageSending($message, []));
        $this->assertSame('هوية البريد', $message->getFrom()[0]->getName());
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->getJson('/api/employees')->assertOk()->assertJsonPath('per_page', 7);
        $employee = Employee::create(['employee_number' => 'AUTO-LOAN', 'full_name' => 'اختبار الترقيم', 'hire_date' => '2020-01-01', 'status' => 'active']);
        $response = $this->postJson('/api/advances', ['employee_id' => $employee->id, 'original_amount' => 120])->assertCreated();
        $this->assertStringStartsWith('LOAN-', $response->json('reference_number'));
        $this->assertSame('120.00', $response->json('original_amount'));
    }

    public function test_developer_created_only_by_interactive_command_and_password_not_logged(): void
    {
        $this->artisan('developer:create')->expectsQuestion('اسم المطور', 'مطور تجريبي')->expectsQuestion('البريد الإلكتروني', 'cli-developer@example.test')->expectsQuestion('كلمة المرور (12 حرفًا على الأقل)', 'CommandPassword123!')->expectsQuestion('تأكيد كلمة المرور', 'CommandPassword123!')->assertSuccessful();
        $created = User::where('email', 'cli-developer@example.test')->firstOrFail();
        $this->assertTrue($created->hasPermission('manage-system-settings'));
        $this->assertFalse($created->hasPermission('pay_settlements'));
        $this->assertStringNotContainsString('CommandPassword123!', AuditLog::where('action', 'developer_created_cli')->firstOrFail()->toJson());
    }
}
