<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupCleanCommand extends Command
{
    protected $signature = 'backup:clean {--days= : Override retention days from config}';

    protected $description = 'Clean old backups beyond the retention period';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('days') ?? config('backup.retention_days', 30));
        $disk = config('backup.disk', 'local');
        $basePath = config('backup.path', 'backups/tesotunes');

        $this->info("Cleaning backups older than {$retentionDays} days from {$disk}...");

        try {
            $cutoffDate = now()->subDays($retentionDays);
            $deleted = 0;

            // Clean database backups
            $deleted += $this->cleanDirectory($disk, "{$basePath}/database", $cutoffDate);

            // Clean file backups
            $deleted += $this->cleanDirectory($disk, "{$basePath}/files", $cutoffDate);

            $this->info("Cleaned {$deleted} old backup files.");

            Log::info('Backup cleanup completed', [
                'deleted' => $deleted,
                'retention_days' => $retentionDays,
                'disk' => $disk,
            ]);

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Backup cleanup failed: {$e->getMessage()}");

            Log::error('Backup cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    protected function cleanDirectory(string $disk, string $directory, \Carbon\Carbon $cutoffDate): int
    {
        $storage = Storage::disk($disk);
        $deleted = 0;

        try {
            $files = $storage->allFiles($directory);
        } catch (Exception $e) {
            // Directory may not exist yet
            return 0;
        }

        foreach ($files as $file) {
            try {
                $lastModified = $storage->lastModified($file);
                $fileDate = \Carbon\Carbon::createFromTimestamp($lastModified);

                if ($fileDate->isBefore($cutoffDate)) {
                    $storage->delete($file);
                    $deleted++;
                    $this->line("Deleted: {$file}");
                }
            } catch (Exception $e) {
                $this->warn("Could not process: {$file} — {$e->getMessage()}");
            }
        }

        // Clean empty directories
        try {
            $dirs = $storage->directories($directory);
            foreach ($dirs as $dir) {
                if (empty($storage->allFiles($dir))) {
                    $storage->deleteDirectory($dir);
                }
            }
        } catch (Exception $e) {
            // Non-critical
        }

        return $deleted;
    }
}
