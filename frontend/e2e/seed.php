<?php
// Run only against the dedicated audit database; never against business data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || !str_ends_with(config('database.connections.sqlite.database'), 'phase6-e2e.sqlite')) {
    throw new RuntimeException('Dedicated E2E database required.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
foreach (['hr', 'reviewer', 'admin'] as $role) {
    App\Models\User::firstOrCreate(['email' => "$role@audit.test"], [
        'name' => "Audit $role", 'password' => 'audit-test-password', 'role' => $role, 'status' => 'active',
    ]);
}
$employee = App\Models\Employee::firstOrCreate(['employee_number' => 'AUDIT-E2E'], [
    'full_name' => 'Audit Employee', 'hire_date' => '2022-01-01', 'status' => 'active',
]);
App\Models\Contract::firstOrCreate(['employee_id' => $employee->id], [
    'contract_type' => 'indefinite', 'start_date' => '2022-01-01', 'basic_salary' => 6000, 'status' => 'active',
]);
echo "Dedicated audit database prepared.\n";
