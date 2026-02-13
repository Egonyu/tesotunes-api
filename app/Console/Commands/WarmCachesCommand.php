<?php

namespace App\Console\Commands;

use App\Services\CacheWarmingService;
use Illuminate\Console\Command;

class WarmCachesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warm 
                            {--active-users : Warm caches for active users}
                            {--limit=100 : Number of active users to warm caches for}
                            {--clear : Clear all warmed caches first}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm critical application caches for improved performance';

    /**
     * Execute the console command.
     */
    public function handle(CacheWarmingService $cacheWarmingService): int
    {
        $this->info('Starting cache warming...');
        $this->newLine();
        
        // Clear caches if requested
        if ($this->option('clear')) {
            $this->warn('Clearing existing caches...');
            $cacheWarmingService->clearAll();
            $this->info('✓ Caches cleared');
            $this->newLine();
        }
        
        // Warm global caches
        $this->info('Warming global caches...');
        $progressBar = $this->output->createProgressBar(5);
        
        $results = $cacheWarmingService->warmAll();
        
        $progressBar->finish();
        $this->newLine(2);
        
        if ($results['success']) {
            $this->info("✓ Cache warming completed in {$results['duration_ms']}ms");
            $this->newLine();
            
            $this->table(
                ['Cache Type', 'Entries Warmed'],
                collect($results['details'])->map(fn($count, $type) => [$type, $count])->toArray()
            );
            
            $this->newLine();
            $this->info("Total caches warmed: {$results['caches_warmed']}");
        } else {
            $this->error('✗ Cache warming failed: ' . $results['error']);
            return Command::FAILURE;
        }
        
        // Warm active user caches if requested
        if ($this->option('active-users')) {
            $this->newLine();
            $limit = (int) $this->option('limit');
            $this->info("Warming caches for {$limit} active users...");
            
            $userCaches = $cacheWarmingService->warmActiveUserCaches($limit);
            $this->info("✓ Warmed {$userCaches} user-specific caches");
        }
        
        // Display cache statistics
        $this->newLine();
        $this->info('Cache Statistics:');
        $stats = $cacheWarmingService->getStats();
        
        foreach ($stats as $key => $value) {
            if (!is_array($value)) {
                $this->line("  {$key}: {$value}");
            }
        }
        
        $this->newLine();
        $this->info('✓ Cache warming completed successfully!');
        
        return Command::SUCCESS;
    }
}
