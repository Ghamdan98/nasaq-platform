<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = config('database.connections.sqlite.database');
$allowed = realpath(storage_path('framework/testing'));
if (config('database.default') !== 'sqlite' || ! $allowed || realpath(dirname($path)) !== $allowed) {
    throw new RuntimeException('Finance E2E setup is restricted to a SQLite database under storage/framework/testing.');
}
User::firstOrCreate(['email' => 'finance-e2e@example.test'], ['name' => 'Finance E2E Admin', 'password' => 'FinanceE2E123!', 'role' => 'admin', 'status' => 'active']);
echo "Isolated finance E2E account prepared.\n";
