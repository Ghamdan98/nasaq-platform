<?php

namespace App\Console\Commands;

use App\Services\Backups\SystemBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunSystemBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Create an encrypted SQLite and attachments backup, then test restoration in isolation';

    public function handle(SystemBackup $backups): int
    {
        $lock = Cache::store('file')->lock('system-backup', 21600);
        if (! $lock->get()) {
            $this->error('Another backup is already running.');

            return self::FAILURE;
        }
        try {
            $report = $backups->create();
            $this->info('Backup created and restore verified: '.$report['name']);
            $this->line('Restored files: '.$report['restored_files'].'; database tables: '.count($report['tables']));
            Log::info('System backup verified', $report);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            Log::error('System backup failed', ['message' => $error->getMessage()]);
            $this->error('Backup failed: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
