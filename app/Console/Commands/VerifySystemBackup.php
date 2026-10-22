<?php

namespace App\Console\Commands;

use App\Services\Backups\SystemBackup;
use Illuminate\Console\Command;

class VerifySystemBackup extends Command
{
    protected $signature = 'backup:verify {name? : Backup filename; defaults to latest}';

    protected $description = 'Test restoration into a temporary isolated directory without changing live data';

    public function handle(SystemBackup $backups): int
    {
        try {
            $name = $this->argument('name') ?: $backups->latest();
            if (! $name) {
                throw new \RuntimeException('No backup found.');
            }
            $report = $backups->verify($name);
            $this->info('Isolated restore passed: '.$name);
            $this->line('Restored files: '.$report['restored_files'].'; integrity: '.$report['integrity'].'; foreign keys: '.$report['foreign_keys']);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Restore verification failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
