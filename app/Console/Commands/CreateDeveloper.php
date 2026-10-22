<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateDeveloper extends Command
{
    protected $signature = 'developer:create';

    protected $description = 'Interactively create a developer account without fixed or command-line passwords';

    public function handle(): int
    {
        $data = ['name' => $this->ask('اسم المطور'), 'email' => strtolower(trim($this->ask('البريد الإلكتروني'))), 'password' => $this->secret('كلمة المرور (12 حرفًا على الأقل)'), 'password_confirmation' => $this->secret('تأكيد كلمة المرور')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|max:128|confirmed']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $user = User::create(array_merge(array_diff_key($data, ['password_confirmation' => true]), ['role' => 'developer', 'status' => 'active', 'permissions_json' => ['manage-system-settings'], 'denied_permissions_json' => []]));
            AuditLog::create(['action' => 'developer_created_cli', 'entity_type' => User::class, 'entity_id' => $user->id, 'new_values_json' => ['email' => $user->email, 'role' => 'developer']]);
        });
        $this->info('تم إنشاء حساب المطور. افتح /#/developer/settings بعد تسجيل الدخول.');

        return self::SUCCESS;
    }
}
