<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use ZipArchive;

class SystemBackup
{
    private function password(): string
    {
        $key = config('app.key');
        if (! $key) {
            throw new RuntimeException('APP_KEY is required for encrypted backups.');
        }

        return hash('sha256', 'settlement-backup-v1|'.$key);
    }

    private function work(): string
    {
        $path = config('backups.work_directory').'/'.bin2hex(random_bytes(16));
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function databaseInfo(string $path): array
    {
        $db = new PDO('sqlite:'.$path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA query_only=ON');
        if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('SQLite integrity check failed.');
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('SQLite foreign key check failed.');
        }
        $tables = [];
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $name = $table['name'];
            $tables[$name] = (int) $db->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $name).'"')->fetchColumn();
        }
        $documents = isset($tables['advance_documents']) ? $db->query('SELECT path FROM advance_documents')->fetchAll(PDO::FETCH_COLUMN) : [];
        if (isset($tables['system_branding_assets'])) {
            $documents = array_merge($documents, $db->query('SELECT path FROM system_branding_assets')->fetchAll(PDO::FETCH_COLUMN));
        }

        return ['tables' => $tables, 'documents' => $documents];
    }

    public function create(): array
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('This backup implementation supports SQLite only; configure a database-native backup before switching databases.');
        }
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new RuntimeException('ZIP AES-256 encryption is unavailable.');
        }
        $password = $this->password();
        $work = $this->work();
        File::ensureDirectoryExists(config('backups.directory'));
        $name = 'settlement-'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.zip';
        $pending = config('backups.directory').'/'.$name.'.partial';
        $target = config('backups.directory').'/'.$name;
        $zip = null;
        try {
            // VACUUM INTO produces a transactionally consistent SQLite snapshot, including WAL data.
            $snapshot = $work.'/database.sqlite';
            $pdo = DB::connection()->getPdo();
            $pdo->exec('VACUUM INTO '.$pdo->quote($snapshot));
            $info = $this->databaseInfo($snapshot);
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'database' => 'sqlite', 'tables' => $info['tables'], 'files' => []];
            $files = ['database.sqlite' => $snapshot];
            foreach (['private', 'public'] as $disk) {
                $storageDisk = $disk === 'private' ? 'local' : $disk;
                $root = config('filesystems.disks.'.$storageDisk.'.root');
                if (config('filesystems.disks.'.$storageDisk.'.driver') !== 'local') {
                    throw new RuntimeException('Backup requires local private and public storage.');
                }
                if (! is_dir($root)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) {
                        throw new RuntimeException('Symbolic links are not supported in backup storage.');
                    }
                    if (! $file->isFile()) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                    // Exclude old full database backups to prevent recursive archive growth.
                    if ($disk === 'private' && (str_starts_with($relative, 'backups/') || $relative === 'backups')) {
                        continue;
                    }
                    if (basename($relative) === '.gitignore') {
                        continue;
                    }
                    $files['files/'.$disk.'/'.$relative] = $file->getPathname();
                }
            }
            foreach ($info['documents'] as $document) {
                if (! isset($files['files/private/'.str_replace('\\', '/', $document)])) {
                    throw new RuntimeException('An attachment referenced by the snapshot is missing; backup was not completed.');
                }
            }
            $zip = new ZipArchive;
            if ($zip->open($pending, ZipArchive::CREATE | ZipArchive::EXCL) !== true || ! $zip->setPassword($password)) {
                throw new RuntimeException('Cannot create encrypted backup.');
            }
            foreach ($files as $entry => $source) {
                $hash = hash_file('sha256', $source);
                if ($hash === false || ! $zip->addFile($source, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    throw new RuntimeException('Cannot add backup file.');
                }
                $manifest['files'][$entry] = ['sha256' => $hash, 'bytes' => filesize($source)];
            }
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) || ! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256)) {
                throw new RuntimeException('Cannot write backup manifest.');
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finish backup archive.');
            }
            $zip = null;
            if (! rename($pending, $target)) {
                throw new RuntimeException('Cannot publish completed archive.');
            }
            // Restore every file to an isolated directory and validate the actual archived bytes.
            $report = $this->verify($name);

            return array_merge($report, ['name' => $name, 'bytes' => filesize($target)]);
        } catch (\Throwable $error) {
            if ($zip) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                }
            }
            File::delete([$pending, $target, $target.'.verified.json']);
            throw $error;
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function verify(string $name): array
    {
        if (! preg_match('/\Asettlement-\d{8}-\d{6}-[a-f0-9]{12}\.zip\z/', $name)) {
            throw new RuntimeException('Invalid backup name.');
        }
        $path = config('backups.directory').'/'.$name;
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Backup file does not exist.');
        }
        $zip = new ZipArchive;
        $work = $this->work();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Cannot open backup.');
            }
            $opened = true;
            $zip->setPassword($this->password());
            $meta = $zip->statName('manifest.json');
            if (! $meta || $meta['size'] > 32 * 1024 * 1024) {
                throw new RuntimeException('Invalid backup manifest.');
            }
            $json = $zip->getFromName('manifest.json');
            if ($json === false) {
                throw new RuntimeException('Cannot decrypt backup. Preserve the original APP_KEY.');
            }
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null) !== 1 || ($manifest['database'] ?? null) !== 'sqlite' || ! is_array($manifest['files'] ?? null) || count($manifest['files']) > 100000 || $zip->numFiles !== count($manifest['files']) + 1 || ! isset($manifest['files']['database.sqlite'])) {
                throw new RuntimeException('Invalid backup structure.');
            }
            $bytes = 0;
            foreach ($manifest['files'] as $entry => $expected) {
                if ($entry !== 'database.sqlite' && ! preg_match('~\Afiles/(private|public)/[^\\\\:]+\z~', $entry)) {
                    throw new RuntimeException('Unsafe archive entry.');
                }
                if (in_array('..', explode('/', $entry), true) || in_array('.', explode('/', $entry), true) || str_contains($entry, '//')) {
                    throw new RuntimeException('Unsafe archive path.');
                }
                $stat = $zip->statName($entry);
                if (! $stat || $stat['size'] !== $expected['bytes'] || ($stat['encryption_method'] ?? null) !== ZipArchive::EM_AES_256) {
                    throw new RuntimeException('Archive file metadata mismatch.');
                }
                $bytes += $stat['size'];
                if ($bytes > config('backups.maximum_restore_bytes')) {
                    throw new RuntimeException('Restore exceeds configured size limit.');
                }
                $destination = $work.'/'.$entry;
                File::ensureDirectoryExists(dirname($destination));
                $input = $zip->getStream($entry);
                $output = fopen($destination, 'xb');
                if (! $input || ! $output) {
                    throw new RuntimeException('Cannot restore backup file.');
                }
                try {
                    $copied = stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($copied !== $expected['bytes'] || ! hash_equals($expected['sha256'], hash_file('sha256', $destination))) {
                    throw new RuntimeException('Restored file checksum mismatch.');
                }
            }
            $info = $this->databaseInfo($work.'/database.sqlite');
            if ($info['tables'] !== $manifest['tables']) {
                throw new RuntimeException('Restored database row counts differ from the snapshot.');
            }
            foreach ($info['documents'] as $document) {
                if (! isset($manifest['files']['files/private/'.str_replace('\\', '/', $document)])) {
                    throw new RuntimeException('Restored attachment is missing.');
                }
            }
            $report = ['verified_at' => now()->toIso8601String(), 'archive_sha256' => hash_file('sha256', $path), 'restored_files' => count($manifest['files']), 'restored_bytes' => $bytes, 'tables' => $info['tables'], 'integrity' => 'ok', 'foreign_keys' => 'ok'];
            if (File::put($path.'.verified.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
                throw new RuntimeException('Cannot save restore verification report.');
            }

            return $report;
        } finally {
            if ($opened) {
                $zip->close();
            }
            File::deleteDirectory($work);
        }
    }

    public function latest(): ?string
    {
        $files = glob(config('backups.directory').'/settlement-*.zip') ?: [];
        rsort($files);

        return $files ? basename($files[0]) : null;
    }
}
