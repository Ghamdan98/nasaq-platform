<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeImport;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\Employees\EmployeeData;
use App\Services\Employees\EmployeeSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class EmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        $user = User::factory()->create(['role' => 'hr']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['employee_number' => '000123', 'full_name' => 'محمد أحمد', 'company' => 'الشركة أ',
            'hire_date' => '2020-08-26', 'status' => 'active', 'national_id_or_iqama' => '0012345678',
            'basic_salary' => '3000.00', 'housing_allowance' => '1000.00', 'work_nature_allowance' => '1300.00',
            'transportation_allowance' => '500.00', 'subsistence_allowance' => '400.00'], $overrides);
    }

    private function upload(array $rows, string $mode = 'upsert')
    {
        $contents = app(EmployeeSpreadsheet::class)->workbook(array_values(EmployeeData::HEADERS), array_map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', array_keys(EmployeeData::HEADERS)), $rows));
        $file = UploadedFile::fake()->createWithContent('employees.xlsx', $contents);

        return $this->post('/api/employee-imports/preview', ['file' => $file, 'mode' => $mode], ['Accept' => 'application/json']);
    }

    public function test_manual_create_aliases_totals_company_scoped_codes_and_updates(): void
    {
        $this->hr();
        $response = $this->postJson('/api/employees', $this->data())->assertCreated()
            ->assertJsonPath('employee_code', '000123')->assertJsonPath('identity_number', '0012345678')
            ->assertJsonPath('total_salary', '6200');
        $id = $response->json('id');
        $this->putJson('/api/employees/'.$id, ['basic_salary' => '3500.25'])->assertOk()->assertJsonPath('total_salary', '6700.25');
        $this->postJson('/api/employees', $this->data())->assertUnprocessable()->assertJsonValidationErrors('employee_number');
        $this->postJson('/api/employees', $this->data(['company' => 'الشركة ب']))->assertCreated();
        $this->putJson('/api/employees/'.$id, ['company' => 'الشركة ب'])->assertUnprocessable();
        $this->postJson('/api/employees', $this->data(['employee_number' => 'new', 'basic_salary' => '-1']))->assertUnprocessable();
    }

    public function test_search_by_partial_name_code_identity_and_company_status_filters(): void
    {
        $this->hr();
        $employee = Employee::create($this->data());
        Employee::create($this->data(['employee_number' => 'other', 'national_id_or_iqama' => '9876543210', 'full_name' => 'سالم', 'company' => 'شركة أخرى', 'status' => 'inactive']));
        foreach (['أحم', '000123', '0012345678'] as $search) {
            $this->getJson('/api/employees?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $employee->id);
        }
        $this->getJson('/api/employees?'.http_build_query(['company' => 'الشركة أ', 'status' => 'active', 'per_page' => 1]))->assertOk()->assertJsonPath('total', 1);
    }

    public function test_100_employee_import_preview_commit_and_replay_protection(): void
    {
        $this->hr();
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->data(['employee_number' => str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }
        $response = $this->upload($rows)->assertCreated()->assertJsonPath('new_count', 100)->assertJsonPath('rejected_count', 0);
        $id = $response->json('id');
        $this->assertDatabaseCount('employees', 0);
        $this->assertCount(50, $response->json('rows'));
        $this->getJson('/api/employee-imports/'.$id.'?page=2')->assertOk()->assertJsonPath('rows.0.row', 52);
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseCount('employees', 100);
        $this->assertDatabaseHas('employees', ['employee_number' => '000000', 'full_name' => 'محمد أحمد']);
        $this->assertNull(EmployeeImport::find($id)->payload);
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertStatus(409);
    }

    public function test_duplicates_invalid_dates_and_salaries_block_whole_import_with_error_report(): void
    {
        $this->hr();
        $response = $this->upload([$this->data(), $this->data(), $this->data(['employee_number' => 'bad', 'hire_date' => '31/02/2024', 'basic_salary' => '-10']), $this->data(['employee_number' => 'valid'])])->assertCreated()->assertJsonPath('rejected_count', 3)->assertJsonPath('status', 'rejected');
        $id = $response->json('id');
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertStatus(409);
        $this->assertDatabaseCount('employees', 0);
        $report = $this->get('/api/employee-imports/'.$id.'/errors')->assertOk();
        $this->assertStringStartsWith('PK', $report->getContent());
    }

    public function test_import_reads_across_batch_boundaries_and_encrypts_preview_data(): void
    {
        $this->hr();
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = $this->data(['employee_number' => 'batch-'.$i]);
        }
        $response = $this->upload($rows)->assertCreated()->assertJsonPath('new_count', 205);
        $id = $response->json('id');
        $raw = DB::table('employee_imports')->where('id', $id)->value('payload');
        $this->assertStringNotContainsString('0012345678', $raw);
        $this->assertStringNotContainsString('batch-204', $raw);
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertOk();
        $this->assertDatabaseHas('employees', ['employee_number' => 'batch-204']);
        $this->assertDatabaseCount('employees', 205);
    }

    public function test_import_modes_preserve_blanks_unlisted_employees_and_inclusion_rules(): void
    {
        $this->hr();
        $employee = Employee::create($this->data(['salary_inclusion' => ['housing_allowance' => ['eos' => true]]]));
        Employee::create($this->data(['employee_number' => 'unlisted']));
        $response = $this->upload([$this->data(['basic_salary' => '3500', 'housing_allowance' => ''])], 'new_only')->assertCreated()->assertJsonPath('skipped_count', 1);
        $this->postJson('/api/employee-imports/'.$response->json('id').'/commit')->assertOk();
        $this->assertSame('3000.00', $employee->fresh()->basic_salary);
        $response = $this->upload([$this->data(['basic_salary' => '3500', 'housing_allowance' => ''])])->assertCreated()->assertJsonPath('updated_count', 1);
        $this->postJson('/api/employee-imports/'.$response->json('id').'/commit')->assertOk();
        $this->assertSame('3500.00', $employee->fresh()->basic_salary);
        $this->assertSame('1000.00', $employee->fresh()->housing_allowance);
        $this->assertTrue($employee->fresh()->salary_inclusion['housing_allowance']['eos']);
        $this->assertDatabaseCount('employees', 2);
        $this->upload([$this->data(['employee_number' => 'not-existing'])], 'update_only')->assertCreated()->assertJsonPath('skipped_count', 1);
    }

    public function test_import_dates_text_serials_and_leading_zero_ids_are_preserved(): void
    {
        $this->hr();
        foreach (['26/08/2020', Date::PHPToExcel(new \DateTime('2020-08-26'))] as $date) {
            $response = $this->upload([$this->data(['hire_date' => $date])])->assertCreated()->assertJsonPath('rejected_count', 0)->assertJsonPath('rows.0.data.hire_date', '2020-08-26')->assertJsonPath('rows.0.data.employee_number', '000123');
            $this->postJson('/api/employee-imports/'.$response->json('id').'/commit')->assertOk();
        }
    }

    public function test_import_conflicts_rollback_all_rows_and_preview_is_owner_scoped(): void
    {
        $user = $this->hr();
        $employee = Employee::create($this->data());
        $response = $this->upload([$this->data(['employee_number' => 'new']), $this->data(['basic_salary' => '5000'])])->assertCreated();
        $id = $response->json('id');
        $employee->update(['basic_salary' => '4500']);
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertStatus(409);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'new']);
        $this->actingAs(User::factory()->create(['role' => 'hr']), 'sanctum')->getJson('/api/employee-imports/'.$id)->assertForbidden();
        $this->actingAs($user, 'sanctum');
        $this->travel(2)->days();
        $this->postJson('/api/employee-imports/'.$id.'/commit')->assertStatus(409);
        $this->get('/api/employee-imports/'.$id.'/errors')->assertStatus(410);
    }

    public function test_sensitive_salary_and_identity_require_permission(): void
    {
        $employee = Employee::create($this->data());
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'sanctum');
        $this->getJson('/api/employees/'.$employee->id)->assertOk()->assertJsonMissingPath('basic_salary')->assertJsonMissingPath('identity_number')->assertJsonMissingPath('national_id_or_iqama')->assertJsonMissingPath('settlement_input');
        $this->getJson('/api/employees')->assertOk()->assertJsonMissingPath('data.0.basic_salary')->assertJsonMissingPath('data.0.contracts');
        $this->postJson('/api/employees', $this->data())->assertForbidden();
        $this->putJson('/api/employees/'.$employee->id, ['basic_salary' => 1])->assertForbidden();
        $this->getJson('/api/employee-imports')->assertForbidden();
        $this->getJson('/api/contracts')->assertForbidden();
        $this->getJson('/api/salary-components')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'employee']), 'sanctum')->getJson('/api/employees')->assertForbidden();
    }

    public function test_selected_employee_salary_is_authoritative_and_saved_history_is_immutable(): void
    {
        $this->hr();
        $employee = Employee::create($this->data(['last_leave_settlement_date' => '2025-01-01']));
        $this->getJson('/api/employees/'.$employee->id)->assertOk()->assertJsonPath('settlement_input.hire_date', '2020-08-26')->assertJsonPath('settlement_input.last_settlement_date', '2025-01-01');
        $payload = ['employee_id' => $employee->id, 'transaction_type' => 'final_settlement', 'transaction_date' => '2026-10-06',
            'last_working_date' => '2026-10-06', 'hire_date' => '2026-01-01', 'termination_type' => 'termination', 'actual_notice_days' => 60, 'unpaid_days' => 6, 'basic_salary' => 999999,
            'leave_balance_method' => 'date_accrual', 'salary_components' => [['code' => 'basic_salary', 'amount' => 999999]]];
        $this->postJson('/api/settlements/calculate', $payload)->assertOk()->assertJsonPath('salary_base.gross_monthly_wage', '6200')->assertJsonPath('salary_base.eos_wage', '6200')->assertJsonPath('leave_calculation.daily_rate', '206.666666666666');
        $saved = $this->postJson('/api/hr-transactions', $payload)->assertCreated()->assertJsonPath('input_snapshot_json.basic_salary', '3000.00');
        $transaction = HrTransaction::find($saved->json('id'));
        $snapshot = $transaction->input_snapshot_json;
        $amount = $transaction->net_amount;
        $this->putJson('/api/employees/'.$employee->id, ['basic_salary' => 7000])->assertOk();
        $this->assertSame($snapshot, $transaction->fresh()->input_snapshot_json);
        $this->assertSame($amount, $transaction->fresh()->net_amount);
        $this->assertSame($employee->id, $transaction->employee_id);
        $this->postJson('/api/settlements/calculate', $payload)->assertOk()->assertJsonPath('salary_base.gross_monthly_wage', '10200');
    }

    public function test_salary_changes_since_selection_require_reselection_and_leave_uses_inclusion(): void
    {
        $this->hr();
        $employee = Employee::create($this->data(['salary_inclusion' => ['housing_allowance' => ['leave' => false]]]));
        $version = $this->getJson('/api/employees/'.$employee->id)->json('employee_profile_version');
        $payload = ['employee_id' => $employee->id, 'transaction_type' => 'leave_settlement', 'transaction_date' => '2026-10-06', 'last_working_date' => '2026-10-06',
            'leave_transaction_type' => 'annual_leave_pay', 'opening_balance' => 20, 'requested_leave_days' => 10, 'monthly_wage' => 99999];
        $this->postJson('/api/hr-transactions/calculate', $payload)->assertOk()->assertJsonPath('calculation_wage', '5200')->assertJsonPath('gross_leave_amount', '1733.33');
        $employee->update(['basic_salary' => 5000]);
        $this->postJson('/api/hr-transactions/calculate', array_merge($payload, ['employee_profile_version' => $version]))->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_public_registration_cannot_create_payroll_access(): void
    {
        $payload = ['name' => 'New user', 'email' => 'new@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'];
        $this->postJson('/api/auth/register', $payload)->assertUnauthorized();
        $this->hr();
        $this->postJson('/api/auth/register', $payload)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_bulk_gross_salary_enables_all_components_for_existing_employees(): void
    {
        $employee = Employee::create($this->data(['salary_inclusion' => ['housing_allowance' => ['eos' => false, 'leave' => false, 'notice' => false]]]));
        $this->artisan('employees:use-gross-salary')->assertSuccessful();
        $profile = EmployeeData::settlementInput($employee->fresh());
        foreach ($profile['salary_components'] as $component) {
            $this->assertTrue($component['include_in_eos']);
            $this->assertTrue($component['include_in_leave']);
            $this->assertTrue($component['include_in_notice']);
        }
        $base = (new \App\Services\Settlement\SalaryBaseCalculator())->calculate($profile);
        foreach (['gross_monthly_wage', 'eos_calculation_wage', 'leave_calculation_wage', 'notice_calculation_wage'] as $field) {
            $this->assertSame('6200', $base[$field]);
        }
    }
}
