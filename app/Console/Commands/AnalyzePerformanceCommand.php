<?php

namespace App\Console\Commands;

use App\Services\QueryOptimizationService;
use Illuminate\Console\Command;

class AnalyzePerformanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'performance:analyze 
                            {--optimize : Optimize database tables}
                            {--indexes : Check for missing indexes}
                            {--stats : Show detailed database statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze application performance and database optimization opportunities';

    /**
     * Execute the console command.
     */
    public function handle(QueryOptimizationService $optimizationService): int
    {
        $this->info('Starting performance analysis...');
        $this->newLine();
        
        // Database statistics
        if ($this->option('stats')) {
            $this->info('Database Statistics:');
            $stats = $optimizationService->getDatabaseStats();
            
            $this->line("  Driver: {$stats['driver']}");
            $this->line("  Database: {$stats['database']}");
            
            if (isset($stats['database_size_mb'])) {
                $this->line("  Size: {$stats['database_size_mb']} MB");
            }
            
            if (isset($stats['active_connections'])) {
                $this->line("  Active Connections: {$stats['active_connections']}");
            }
            
            if (isset($stats['largest_tables'])) {
                $this->newLine();
                $this->info('Largest Tables:');
                
                $tableData = collect($stats['largest_tables'])->map(function ($table) {
                    return [
                        $table->table_name,
                        number_format($table->table_rows),
                        $table->data_size_mb . ' MB',
                        $table->index_size_mb . ' MB',
                    ];
                })->toArray();
                
                $this->table(
                    ['Table', 'Rows', 'Data Size', 'Index Size'],
                    $tableData
                );
            }
            
            $this->newLine();
        }
        
        // Check for missing indexes
        if ($this->option('indexes')) {
            $this->info('Checking for missing indexes...');
            $indexCheck = $optimizationService->checkMissingIndexes();
            
            if (isset($indexCheck['supported']) && !$indexCheck['supported']) {
                $this->warn($indexCheck['message']);
            } elseif (isset($indexCheck['error'])) {
                $this->error('Error: ' . $indexCheck['error']);
            } else {
                $count = $indexCheck['count'] ?? 0;
                
                if ($count > 0) {
                    $this->warn("Found {$count} potential missing indexes:");
                    
                    $missingData = collect($indexCheck['potential_missing_indexes'])->map(function ($item) {
                        return [$item->TABLE_NAME, $item->COLUMN_NAME];
                    })->toArray();
                    
                    $this->table(['Table', 'Column'], $missingData);
                    
                    $this->newLine();
                    $this->line('Consider adding indexes for foreign key columns to improve join performance.');
                } else {
                    $this->info('✓ No obvious missing indexes detected');
                }
            }
            
            $this->newLine();
        }
        
        // Optimize tables
        if ($this->option('optimize')) {
            $this->warn('Optimizing database tables...');
            $this->warn('This may take several minutes for large databases.');
            $this->newLine();
            
            if ($this->confirm('Do you want to continue?', true)) {
                $result = $optimizationService->optimizeTables();
                
                if ($result['success']) {
                    $this->info("✓ Optimized {$result['tables_optimized']} tables");
                    
                    if (count($result['tables']) <= 20) {
                        $this->line('  - ' . implode("\n  - ", $result['tables']));
                    }
                } else {
                    $this->error('✗ Optimization failed: ' . $result['error']);
                    return Command::FAILURE;
                }
            } else {
                $this->info('Optimization cancelled');
            }
            
            $this->newLine();
        }
        
        // General recommendations
        $this->info('Performance Recommendations:');
        $this->newLine();
        
        $driver = config('database.default');
        $cache = config('cache.default');
        
        if ($cache === 'database') {
            $this->warn('⚠ Cache driver is set to database');
            $this->line('  Consider using Redis or Memcached for better performance');
        } else {
            $this->info("✓ Using {$cache} cache driver");
        }
        
        if (config('queue.default') === 'sync') {
            $this->warn('⚠ Queue driver is set to sync');
            $this->line('  Consider using database or Redis queue for background processing');
        } else {
            $this->info('✓ Using ' . config('queue.default') . ' queue driver');
        }
        
        $this->newLine();
        $this->info('✓ Performance analysis completed');
        
        return Command::SUCCESS;
    }
}
