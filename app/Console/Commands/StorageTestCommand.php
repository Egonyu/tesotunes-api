<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MusicStorageService;
use App\Models\Artist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StorageTestCommand extends Command
{
    protected $signature = 'music:test-storage {--driver=local : Storage driver to test}';
    protected $description = 'Test music storage functionality';

    public function handle()
    {
        $driver = $this->option('driver');

        $this->info("Testing storage driver: {$driver}");
        $this->newLine();

        // Test storage service initialization
        try {
            $storageService = new MusicStorageService();
            $this->info('✓ MusicStorageService initialized successfully');
        } catch (\Exception $e) {
            $this->error('✗ Failed to initialize MusicStorageService: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test disk configurations
        $this->testDiskConfigurations();

        // Test storage statistics
        $this->testStorageStatistics($storageService);

        // Test directory creation
        $this->testDirectoryCreation();

        $this->newLine();
        $this->info('Storage test completed!');

        return Command::SUCCESS;
    }

    private function testDiskConfigurations()
    {
        $this->info('Testing disk configurations...');

        $disks = ['music_private', 'music_public', 'digitalocean'];

        foreach ($disks as $disk) {
            try {
                $diskInstance = Storage::disk($disk);

                // Test basic operations
                $testFile = 'test-' . time() . '.txt';
                $testContent = 'Test content for ' . $disk;

                // Put test file
                $success = $diskInstance->put($testFile, $testContent);

                if ($success) {
                    $this->info("✓ {$disk}: Write test passed");

                    // Test read
                    $content = $diskInstance->get($testFile);
                    if ($content === $testContent) {
                        $this->info("✓ {$disk}: Read test passed");
                    } else {
                        $this->warn("⚠ {$disk}: Read test failed");
                    }

                    // Cleanup
                    $diskInstance->delete($testFile);
                    $this->info("✓ {$disk}: Cleanup completed");
                } else {
                    $this->error("✗ {$disk}: Write test failed");
                }

            } catch (\Exception $e) {
                $this->error("✗ {$disk}: " . $e->getMessage());
            }
        }
    }

    private function testStorageStatistics(MusicStorageService $storageService)
    {
        $this->info('Testing storage statistics...');

        try {
            $stats = $storageService->getStorageStats();

            foreach ($stats as $driver => $stat) {
                if (isset($stat['error'])) {
                    $this->warn("⚠ {$driver}: " . $stat['error']);
                } else {
                    $this->info("✓ {$driver}: {$stat['file_count']} files, {$stat['total_size_mb']} MB total");
                }
            }
        } catch (\Exception $e) {
            $this->error('✗ Storage statistics failed: ' . $e->getMessage());
        }
    }

    private function testDirectoryCreation()
    {
        $this->info('Testing directory creation...');

        $directories = [
            'music/uploads',
            'music/processed',
            'artwork/covers',
            'music/temp'
        ];

        foreach (['music_private', 'music_public'] as $disk) {
            try {
                $diskInstance = Storage::disk($disk);

                foreach ($directories as $dir) {
                    if (!$diskInstance->exists($dir)) {
                        $diskInstance->makeDirectory($dir);
                        $this->info("✓ Created directory: {$dir} on {$disk}");
                    } else {
                        $this->info("✓ Directory exists: {$dir} on {$disk}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("✗ Directory creation failed on {$disk}: " . $e->getMessage());
            }
        }
    }
}