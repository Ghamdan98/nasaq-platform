<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'مستخدم اختباري', 'email' => 'new-user@example.test', 'role' => 'hr', 'status' => 'active', 'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!', 'reason' => 'إنشاء حساب موظف اختبار'], $overrides);
    }

    private function updateData(User $user, array $overrides = []): array
    {
        return array_merge($user->only(['name', 'email', 'role', 'status', 'phone']), ['reason' => 'تحديث الحساب للاختبار'], $overrides);
    }

    public function test_only_admin_can_manage_users_even_with_explicit_fake_management_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'hr', 'permissions_json' => ['manage_users']]), 'sanctum');
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/users/permissions')->assertForbidden();
        $this->postJson('/api/users', $this->payload())->assertForbidden();
        $this->putJson('/api/users/'.$this->admin->id, $this->updateData($this->admin))->assertForbidden();
        $this->getJson('/api/users/'.$this->admin->id.'/audit')->assertForbidden();
    }

    public function test_create_without_auto_login_and_audit_does_not_contain_passwords_or_hashes(): void
    {
        $response = $this->postJson('/api/users', $this->payload())->assertCreated()->assertJsonMissingPath('token')->assertJsonMissingPath('user.password');
        $created = User::findOrFail($response->json('user.id'));
        $this->assertTrue(Hash::check('StrongPass123!', $created->password));
        $this->assertSame(0, $created->tokens()->count());
        $this->assertContains('create_transactions', $response->json('user.effective_permissions'));
        $log = AuditLog::where('entity_id', $created->id)->where('action', 'user_created')->firstOrFail();
        $this->assertStringNotContainsString('StrongPass123!', json_encode($log));
        $this->assertStringNotContainsString($created->password, json_encode($log));
        $this->postJson('/api/users', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_default_permission_can_be_withdrawn_and_financial_permission_added(): void
    {
        $user = User::factory()->create(['role' => 'hr', 'status' => 'active']);
        $user->createToken('old-session');
        $this->putJson('/api/users/'.$user->id, $this->updateData($user, ['permissions' => ['view_transactions', 'view_advances', 'pay_settlements']]))->assertOk();
        $user->refresh();
        $this->assertFalse($user->hasPermission('create_transactions'));
        $this->assertFalse($user->hasPermission('manage_employees'));
        $this->assertTrue($user->hasPermission('pay_settlements'));
        $this->assertSame(0, $user->tokens()->count());
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/settlement-finance')->assertOk();
        $this->postJson('/api/hr-transactions', [])->assertForbidden();
        $this->postJson('/api/employees', [])->assertForbidden();
        $this->postJson('/api/contracts', [])->assertForbidden();
        $this->postJson('/api/salary-components', [])->assertForbidden();
        $user->update(['denied_permissions_json' => array_merge($user->denied_permissions_json, ['view_transactions'])]);
        $this->getJson('/api/settlement-finance')->assertForbidden();
    }

    public function test_disable_revokes_tokens_and_blocks_login_and_existing_authenticated_requests(): void
    {
        $user = User::factory()->create(['role' => 'reviewer', 'status' => 'active', 'password' => 'StrongPass123!']);
        $user->createToken('active-session');
        $this->putJson('/api/users/'.$user->id, $this->updateData($user, ['status' => 'inactive']))->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!'])->assertUnauthorized();
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/auth/profile')->assertUnauthorized();
        $this->getJson('/api/advances')->assertUnauthorized();
    }

    public function test_last_admin_and_self_cannot_be_disabled_or_demoted(): void
    {
        $this->putJson('/api/users/'.$this->admin->id, $this->updateData($this->admin, ['status' => 'inactive']))->assertUnprocessable();
        $this->putJson('/api/users/'.$this->admin->id, $this->updateData($this->admin, ['role' => 'hr']))->assertUnprocessable();
        $second = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->putJson('/api/users/'.$this->admin->id, $this->updateData($this->admin, ['status' => 'inactive']))->assertUnprocessable();
        $this->putJson('/api/users/'.$second->id, $this->updateData($second, ['role' => 'reviewer']))->assertOk();
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_reset_password_revoke_sessions_and_self_changes_request_reauthentication(): void
    {
        $user = User::factory()->create(['role' => 'hr']);
        $user->createToken('old-session');
        $this->putJson('/api/users/'.$user->id, $this->updateData($user, ['password' => 'NewStrongPass123!', 'password_confirmation' => 'NewStrongPass123!']))->assertOk();
        $this->assertTrue(Hash::check('NewStrongPass123!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user_password_reset']);
        $this->admin->createToken('self-session');
        $this->putJson('/api/users/'.$this->admin->id, $this->updateData($this->admin, ['password' => 'NewAdminPass123!', 'password_confirmation' => 'NewAdminPass123!']))->assertOk()->assertJsonPath('reauthentication_required', true);
    }

    public function test_invalid_permissions_passwords_and_reviewer_value_adjustment_are_rejected(): void
    {
        $this->postJson('/api/users', $this->payload(['permissions' => ['unknown_permission']]))->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->postJson('/api/users', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $this->payload(['role' => 'reviewer', 'permissions' => ['adjust_transaction_values']]))->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson('/api/users', $this->payload(['permissions' => ['manage_employees']]))->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_list_filters_history_and_reactivation_preserve_existing_audit(): void
    {
        $created = $this->postJson('/api/users', $this->payload(['status' => 'inactive']))->assertCreated()->json('user.id');
        $this->getJson('/api/users?search=new-user&status=inactive')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/users/'.$created.'/audit')->assertOk()->assertJsonPath('total', 1);
        $user = User::findOrFail($created);
        $this->putJson('/api/users/'.$created, $this->updateData($user, ['status' => 'active']))->assertOk();
        $this->getJson('/api/users/'.$created.'/audit')->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/auth/login', ['email' => strtoupper($user->email), 'password' => 'StrongPass123!'])->assertOk()->assertJsonStructure(['user' => ['effective_permissions']]);
        $this->getJson('/api/users/permissions')->assertOk()->assertJsonStructure(['groups', 'roles', 'defaults']);
    }

    public function test_financial_queue_respects_hr_ownership_and_withdrawn_view_permission(): void
    {
        $employee = \App\Models\Employee::create(['employee_number' => 'USER-SCOPE', 'full_name' => 'موظف اختبار نطاق الصلاحيات', 'hire_date' => '2020-01-01', 'status' => 'active']);
        $hr = User::factory()->create(['role' => 'hr', 'permissions_json' => ['post_settlements']]);
        foreach ([$this->admin->id, $hr->id] as $creator) {
            \App\Models\HrTransaction::create(['employee_id' => $employee->id, 'created_by' => $creator, 'transaction_type' => 'final_settlement', 'reference_number' => 'SCOPE-'.$creator, 'transaction_date' => now()->toDateString(), 'status' => 'calculated']);
        }
        $this->actingAs($hr, 'sanctum');
        $this->getJson('/api/settlement-finance')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.created_by', $hr->id);
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer, 'sanctum');
        $this->getJson('/api/settlement-finance')->assertOk()->assertJsonPath('total', 2);
        $reviewer->update(['denied_permissions_json' => ['view_transactions']]);
        $this->getJson('/api/settlement-finance')->assertForbidden();
    }
}
