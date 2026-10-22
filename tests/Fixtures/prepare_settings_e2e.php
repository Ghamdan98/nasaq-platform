<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || realpath(dirname($path)) !== realpath(storage_path('framework/testing'))) {
    throw new RuntimeException('Settings E2E setup requires an isolated test database.');
}
$password = getenv('E2E_DEVELOPER_PASSWORD');
if (! $password || strlen($password) < 12) {
    throw new RuntimeException('Set a temporary E2E_DEVELOPER_PASSWORD.');
}
User::updateOrCreate(['email' => 'developer-e2e@example.test'], ['name' => 'مطور اختبار معزول', 'role' => 'developer', 'status' => 'active', 'permissions_json' => ['manage-system-settings'], 'password' => $password]);
echo "Isolated developer test account prepared.\n";
