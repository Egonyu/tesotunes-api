<?php

namespace App\Console\Commands;

use App\Models\Song;
use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OptimizeImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'images:optimize 
                            {--model=Song : The model to optimize images for}
                            {--field=artwork : The field containing the image}
                            {--limit=100 : Number of records to process at once}
                            {--force : Force re-optimization of already optimized images}';

    /**
     * The console command description.
     */
    protected $description = 'Optimize images for better performance (generate responsive sizes and WebP)';

    protected ImageOptimizationService $imageOptimizer;

    public function __construct(ImageOptimizationService $imageOptimizer)
    {
        parent::__construct();
        $this->imageOptimizer = $imageOptimizer;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->option('model');
        $field = $this->option('field');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');

        $this->info("Starting image optimization for {$model}...");
        $this->newLine();

        // Get records to process
        $query = match ($model) {
            'Song' => Song::query(),
            default => throw new \InvalidArgumentException("Unsupported model: {$model}"),
        };

        // Filter unoptimized or all if force
        if (!$force) {
            $query->where('images_optimized', false)
                  ->orWhereNull('images_optimized');
        }

        $total = $query->count();
        
        if ($total === 0) {
            $this->info('No images to optimize!');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} records to process");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('verbose');
        
        $processed = 0;
        $errors = 0;

        $query->chunk($limit, function ($records) use ($field, $progressBar, &$processed, &$errors) {
            foreach ($records as $record) {
                try {
                    $imagePath = $record->{$field};
                    
                    if (!$imagePath || !Storage::exists($imagePath)) {
                        $this->warn("\nSkipping {$record->id}: No image found");
                        $errors++;
                        $progressBar->advance();
                        continue;
                    }

                    // Generate responsive images
                    $folder = strtolower(class_basename($record));
                    $result = $this->imageOptimizer->optimizeExisting($imagePath, $folder);

                    // Update record
                    $record->update([
                        'artwork_urls' => $result['urls'],
                        'artwork_placeholder' => $result['placeholder'],
                        'images_optimized' => true,
                    ]);

                    $processed++;
                } catch (\Exception $e) {
                    $this->error("\nError processing {$record->id}: " . $e->getMessage());
                    $errors++;
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("✅ Optimization complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Records', $total],
                ['Successfully Processed', $processed],
                ['Errors', $errors],
                ['Success Rate', round(($processed / $total) * 100, 2) . '%'],
            ]
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
