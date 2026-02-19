<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--type=database : Backup type: database, full}
        {--clean : Clean old backups after running}';

    protected $description = 'Run a database or full backup to configured storage';

    public function handle(): int
    {
        $type = $this->option('type');
        $disk = config('backup.disk', 'local');
        $basePath = config('backup.path', 'backups/tesotunes');
        $timestamp = now()->format('Y-m-d_H-i-s');

        $this->info("Starting {$type} backup to {$disk}...");

        try {
            match ($type) {
                'database' => $this->backupDatabase($disk, $basePath, $timestamp),
                'full' => $this->backupFull($disk, $basePath, $timestamp),
                default => throw new Exception("Unknown backup type: {$type}"),
            };

            $this->info('Backup completed successfully.');

            if ($this->option('clean')) {
                $this->call('backup:clean');
            }

            Log::info("Backup {$type} completed", [
                'disk' => $disk,
                'timestamp' => $timestamp,
            ]);

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            Log::error('Backup failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    protected function backupDatabase(string $disk, string $basePath, string $timestamp): void
    {
        $connection = config('backup.database_connection') ?? config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        if (! $dbConfig) {
            throw new Exception("Database connection '{$connection}' not configured");
        }

        $filename = "db_{$timestamp}.sql";
        $tempPath = storage_path("app/backup-temp/{$filename}");

        // Ensure temp directory exists
        if (! is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $driver = $dbConfig['driver'] ?? 'mysql';

        if ($driver === 'mysql') {
            $this->dumpMysql($dbConfig, $tempPath);
        } elseif ($driver === 'pgsql') {
            $this->dumpPostgres($dbConfig, $tempPath);
        } else {
            throw new Exception("Unsupported database driver for backup: {$driver}");
        }

        // Compress
        $gzPath = $tempPath.'.gz';
        $this->compressFile($tempPath, $gzPath);

        // Upload to storage
        $storagePath = "{$basePath}/database/{$filename}.gz";
        Storage::disk($disk)->put($storagePath, file_get_contents($gzPath));

        // Cleanup temp files
        @unlink($tempPath);
        @unlink($gzPath);

        $size = Storage::disk($disk)->size($storagePath);
        $this->info("Database backup saved: {$storagePath} (".$this->formatBytes($size).')');
    }

    protected function backupFull(string $disk, string $basePath, string $timestamp): void
    {
        // First backup database
        $this->backupDatabase($disk, $basePath, $timestamp);

        // Then backup configured directories
        $includeDirs = config('backup.include_dirs', ['storage/app/public']);
        $excludeDirs = config('backup.exclude_dirs', []);

        foreach ($includeDirs as $dir) {
            $fullPath = base_path($dir);

            if (! is_dir($fullPath)) {
                $this->warn("Directory not found, skipping: {$dir}");

                continue;
            }

            $this->info("Backing up directory: {$dir}");

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $count = 0;
            foreach ($files as $file) {
                $relativePath = str_replace($fullPath.DIRECTORY_SEPARATOR, '', $file->getRealPath());

                // Check exclusions
                $skip = false;
                foreach ($excludeDirs as $excluded) {
                    if (str_starts_with($relativePath, basename($excluded))) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                $maxSize = config('backup.max_file_size', 500) * 1024 * 1024;
                if ($maxSize > 0 && $file->getSize() > $maxSize) {
                    $this->warn("Skipping large file: {$relativePath}");

                    continue;
                }

                $storagePath = "{$basePath}/files/{$timestamp}/{$dir}/{$relativePath}";
                Storage::disk($disk)->put($storagePath, file_get_contents($file->getRealPath()));
                $count++;
            }

            $this->info("Backed up {$count} files from {$dir}");
        }
    }

    protected function dumpMysql(array $config, string $outputPath): void
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'] ?? '';

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s %s > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            $password ? '--password='.escapeshellarg($password) : '',
            escapeshellarg($database),
            escapeshellarg($outputPath)
        );

        $result = null;
        $output = [];
        exec($command.' 2>&1', $output, $result);

        if ($result !== 0) {
            throw new Exception('mysqldump failed: '.implode("\n", $output));
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception('mysqldump produced an empty file');
        }
    }

    protected function dumpPostgres(array $config, string $outputPath): void
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'];
        $username = $config['username'];

        putenv("PGPASSWORD={$config['password']}");

        $command = sprintf(
            'pg_dump --host=%s --port=%s --username=%s %s > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($outputPath)
        );

        $result = null;
        $output = [];
        exec($command.' 2>&1', $output, $result);

        putenv('PGPASSWORD');

        if ($result !== 0) {
            throw new Exception('pg_dump failed: '.implode("\n", $output));
        }
    }

    protected function compressFile(string $source, string $destination): void
    {
        $fp = gzopen($destination, 'w9');
        $handle = fopen($source, 'r');

        while (! feof($handle)) {
            gzwrite($fp, fread($handle, 1024 * 512));
        }

        fclose($handle);
        gzclose($fp);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
