<?php

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UpdateSongDurations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'songs:update-durations {--force : Update all songs even if they have duration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update duration for songs that have 0 or null duration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');
        
        $query = Song::query();
        
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('duration')->orWhere('duration', 0);
            });
        }
        
        $songs = $query->whereNotNull('audio_file')->get();
        
        if ($songs->isEmpty()) {
            $this->info('No songs found to update.');
            return 0;
        }
        
        $this->info("Found {$songs->count()} songs to update.");
        
        $progressBar = $this->output->createProgressBar($songs->count());
        $progressBar->start();
        
        $updated = 0;
        $failed = 0;
        
        foreach ($songs as $song) {
            try {
                $duration = $this->extractDuration($song);
                
                if ($duration > 0) {
                    $song->update(['duration' => $duration]);
                    $updated++;
                } else {
                    $this->newLine();
                    $this->warn("Could not extract duration for song: {$song->title} (ID: {$song->id})");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing song {$song->id}: {$e->getMessage()}");
                $failed++;
            }
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        $this->info("Duration update complete!");
        $this->info("Updated: {$updated}");
        $this->info("Failed: {$failed}");
        
        return 0;
    }
    
    /**
     * Extract duration from audio file
     */
    private function extractDuration(Song $song): int
    {
        // Try to find the audio file
        $filePath = $this->findAudioFile($song);
        
        if (!$filePath) {
            return 0;
        }
        
        // Try using getID3 if available
        if (class_exists('\getID3')) {
            try {
                $getID3 = new \getID3();
                $fileInfo = $getID3->analyze($filePath);
                
                if (isset($fileInfo['playtime_seconds'])) {
                    return (int) round($fileInfo['playtime_seconds']);
                }
            } catch (\Exception $e) {
                // Continue to next method
            }
        }
        
        // Try using FFprobe
        if (function_exists('shell_exec')) {
            try {
                $escapedPath = escapeshellarg($filePath);
                $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$escapedPath} 2>&1";
                
                $output = shell_exec($command);
                
                if ($output && is_numeric(trim($output))) {
                    return (int) round((float) trim($output));
                }
            } catch (\Exception $e) {
                // Continue
            }
        }
        
        return 0;
    }
    
    /**
     * Find the actual audio file path
     */
    private function findAudioFile(Song $song): ?string
    {
        // Try different disk configurations
        $disks = ['music_private', 'music', 'public', 'local'];
        
        foreach ($disks as $diskName) {
            try {
                if (Storage::disk($diskName)->exists($song->audio_file)) {
                    return Storage::disk($diskName)->path($song->audio_file);
                }
            } catch (\Exception $e) {
                // Disk might not exist, continue
            }
        }
        
        // Try direct path in storage/app
        $directPath = storage_path('app/' . $song->audio_file);
        if (file_exists($directPath)) {
            return $directPath;
        }
        
        // Try with 'music/' prefix
        $musicPath = storage_path('app/music/' . $song->audio_file);
        if (file_exists($musicPath)) {
            return $musicPath;
        }
        
        return null;
    }
}
