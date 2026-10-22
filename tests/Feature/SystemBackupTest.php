<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Backups\SystemBackup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SystemBackupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Storage::fake('local');
        Storage::fake('public');
        $this->root = storage_path('framework/testing/backup-test-'.bin2hex(random_bytes(8)));
        config(['backups.directory' => $this->root.'/archives', 'backups.work_directory' => $this->root.'/work', 'filesystems.disks.local.root' => Storage::disk('local')->path(''), 'filesystems.disks.public.root' => Storage::disk('public')->path('')]);
        Employee::create(['employee_number' => 'BACKUP1', 'full_name' => 'موظف اختبار النسخ', 'company' => 'اختبار', 'hire_date' => '2020-01-01', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_encrypted_backup_restores_database_and_private_public_files_without_changing_live_data(): void
    {
        Storage::disk('local')->put('advances/proof.txt', 'private-proof');
        Storage::disk('local')->put('backups/old.sqlite', 'do-not-include-old-backups');
        Storage::disk('local')->put('data-management/deleted.encrypted', 'saved-deleted-record');
        Storage::disk('public')->put('test/image.txt', 'public-proof');
        $report = app(SystemBackup::class)->create();
        $this->assertSame(1, $report['tables']['employees']);
        $this->assertSame(4, $report['restored_files']);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open(config('backups.directory').'/'.$report['name'], ZipArchive::RDONLY));
        $this->assertFalse($archive->getFromName('manifest.json'));
        $this->assertFalse($archive->locateName('files/private/backups/old.sqlite'));
        $archive->close();
        Employee::first()->update(['full_name' => 'بيانات أحدث من النسخة']);
        $second = app(SystemBackup::class)->verify($report['name']);
        $this->assertSame('ok', $second['integrity']);
        $this->assertSame('بيانات أحدث من النسخة', Employee::first()->full_name);
        Storage::disk('local')->assertExists('advances/proof.txt');
        $this->assertSame([], File::directories(config('backups.work_directory')));
    }

    public function test_wrong_key_and_archive_path_traversal_are_rejected(): void
    {
        $report = app(SystemBackup::class)->create();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('backup:verify', ['name' => $report['name']])->assertFailed();
        $this->artisan('backup:verify', ['name' => '../outside.zip'])->assertFailed();
        $this->assertSame(1, Employee::count());
    }

    public function test_tampering_fails_restore_and_cleans_temporary_files(): void
    {
        $report = app(SystemBackup::class)->create();
        $zip = new ZipArchive;
        $zip->open(config('backups.directory').'/'.$report['name']);
        $zip->deleteName('database.sqlite');
        $zip->close();
        $this->artisan('backup:verify', ['name' => $report['name']])->assertFailed();
        $this->assertSame([], File::directories(config('backups.work_directory')));
    }
}
