<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt(config('backups.time'))->timezone(config('backups.timezone'))->withoutOverlapping(360)->appendOutputTo(storage_path('logs/backup-schedule.log'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('employees:prune-imports', function () {
    $count = \App\Models\EmployeeImport::where('expires_at', '<', now())->whereNotNull('payload')->update(['payload' => null]);
    $this->info('Expired employee preview payloads removed: '.$count);
})->purpose('Remove expired encrypted previews while retaining import results')->hourly();

Artisan::command('employees:use-gross-salary', function () {
    $count = 0;
    \Illuminate\Support\Facades\DB::transaction(function () use (&$count) {
        $inclusion = array_fill_keys(\App\Services\Employees\EmployeeData::MONEY, ['eos' => true, 'leave' => true, 'notice' => true]);
        \App\Models\Employee::query()->chunkById(200, function ($employees) use ($inclusion, &$count) {
            foreach ($employees as $employee) {
                $employee->update(['salary_inclusion' => $inclusion]);
                $count++;
            }
        });
        \App\Models\AuditLog::create(['action' => 'employees_gross_salary_enabled', 'entity_type' => \App\Models\Employee::class, 'new_values_json' => ['employee_count' => $count]]);
    });
    $this->info('Gross salary enabled for '.$count.' employees. Saved settlements were not changed.');
})->purpose('Include all employee salary components in EOS, leave and notice calculations');
