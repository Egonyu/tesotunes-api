<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

class ModuleHealthCheckCommand extends Command
{
    protected $signature = 'modules:health-check
                           {--module= : Specific module to check (music|podcast|store|sacco)}
                           {--fix : Attempt to fix detected issues}';

    protected $description = 'Check health status of all modules and their database integrity';

    protected array $modules = [
        'music' => [
            'tables' => ['songs', 'albums', 'genres', 'moods', 'play_histories'],
            'models' => ['Song', 'Album', 'Genre', 'Mood'],
            'traits' => [],
            'config' => 'music',
        ],
        'podcast' => [
            'tables' => ['podcasts', 'podcast_categories', 'podcast_episodes', 'podcast_sponsors', 'podcast_collaborators'],
            'models' => ['Podcast', 'PodcastCategory', 'PodcastEpisode', 'PodcastSponsor', 'PodcastCollaborator'],
            'traits' => ['HasPodcast'],
            'config' => 'podcast',
        ],
        'store' => [
            'tables' => ['store_products', 'store_categories', 'store_orders'],
            'models' => ['StoreProduct', 'StoreCategory', 'StoreOrder'],
            'traits' => ['HasStore'],
            'config' => 'store',
        ],
        'sacco' => [
            'tables' => ['sacco_members', 'loans', 'loan_payments'],
            'models' => ['SaccoMember', 'Loan', 'LoanPayment'],
            'traits' => ['HasSaccoMembership'],
            'config' => 'sacco',
        ],
    ];

    public function handle(): int
    {
        $this->info('🔍 Starting module health check...');

        $specificModule = $this->option('module');
        $shouldFix = $this->option('fix');

        $modulesToCheck = $specificModule
            ? [$specificModule => $this->modules[$specificModule] ?? null]
            : $this->modules;

        if ($specificModule && !isset($this->modules[$specificModule])) {
            $this->error("❌ Unknown module: {$specificModule}");
            $this->info("Available modules: " . implode(', ', array_keys($this->modules)));
            return 1;
        }

        $overallHealth = true;
        $issues = [];

        foreach ($modulesToCheck as $moduleName => $moduleConfig) {
            if (!$moduleConfig) continue;

            $this->info("\n📦 Checking {$moduleName} module...");

            $moduleHealth = $this->checkModuleHealth($moduleName, $moduleConfig, $shouldFix);

            if (!$moduleHealth['healthy']) {
                $overallHealth = false;
                $issues = array_merge($issues, $moduleHealth['issues']);
            }
        }

        // Cross-module integration checks
        $this->info("\n🔗 Checking cross-module integration...");
        $integrationHealth = $this->checkCrossModuleIntegration($shouldFix);

        if (!$integrationHealth['healthy']) {
            $overallHealth = false;
            $issues = array_merge($issues, $integrationHealth['issues']);
        }

        // Summary
        $this->info("\n" . str_repeat('=', 50));

        if ($overallHealth) {
            $this->info('✅ All modules are healthy!');
        } else {
            $this->error('❌ Issues detected:');
            foreach ($issues as $issue) {
                $this->warn("  • {$issue}");
            }

            if (!$shouldFix) {
                $this->info("\n💡 Run with --fix to attempt automatic fixes");
            }
        }

        return $overallHealth ? 0 : 1;
    }

    protected function checkModuleHealth(string $moduleName, array $config, bool $shouldFix): array
    {
        $issues = [];
        $healthy = true;

        // Check database tables
        foreach ($config['tables'] as $table) {
            if (!Schema::hasTable($table)) {
                $issues[] = "Missing table: {$table}";
                $healthy = false;

                if ($shouldFix) {
                    $this->warn("  ⚠️  Table {$table} is missing - run migrations to fix");
                }
            } else {
                $this->line("  ✅ Table {$table} exists");
            }
        }

        // Check model files
        foreach ($config['models'] as $model) {
            $modelPaths = [
                "app/Models/{$model}.php",
                "app/Modules/" . ucfirst($moduleName) . "/Models/{$model}.php",
            ];

            $modelExists = false;
            foreach ($modelPaths as $path) {
                if (file_exists(base_path($path))) {
                    $modelExists = true;
                    $this->line("  ✅ Model {$model} exists at {$path}");
                    break;
                }
            }

            if (!$modelExists) {
                $issues[] = "Missing model: {$model}";
                $healthy = false;
                $this->error("  ❌ Model {$model} not found");
            }
        }

        // Check traits integration
        foreach ($config['traits'] as $trait) {
            if (!$this->checkTraitIntegration($trait)) {
                $issues[] = "Trait {$trait} not integrated in User model";
                $healthy = false;

                if ($shouldFix) {
                    $this->warn("  ⚠️  Trait {$trait} needs to be added to User model");
                }
            } else {
                $this->line("  ✅ Trait {$trait} integrated");
            }
        }

        // Check configuration
        if ($config['config'] && !config($config['config'])) {
            $issues[] = "Missing configuration: {$config['config']}.php";
            $healthy = false;
            $this->error("  ❌ Config file {$config['config']}.php not found");
        } else {
            $this->line("  ✅ Configuration {$config['config']}.php exists");
        }

        // Check data integrity
        $dataIntegrityIssues = $this->checkDataIntegrity($moduleName, $config);
        if (!empty($dataIntegrityIssues)) {
            $issues = array_merge($issues, $dataIntegrityIssues);
            $healthy = false;
        }

        return ['healthy' => $healthy, 'issues' => $issues];
    }

    protected function checkTraitIntegration(string $traitName): bool
    {
        $userModelPath = app_path('Models/User.php');

        if (!file_exists($userModelPath)) {
            return false;
        }

        $content = file_get_contents($userModelPath);

        // Check if trait is imported and used
        $traitImported = str_contains($content, "use App\\Modules\\");
        $traitUsed = str_contains($content, $traitName);

        return $traitImported && $traitUsed;
    }

    protected function checkDataIntegrity(string $moduleName, array $config): array
    {
        $issues = [];

        try {
            foreach ($config['tables'] as $table) {
                if (!Schema::hasTable($table)) {
                    continue;
                }

                // Check for orphaned records (basic foreign key integrity)
                if ($table === 'songs' && Schema::hasTable('users')) {
                    $orphanedSongs = DB::table('songs')
                        ->leftJoin('users', 'songs.user_id', '=', 'users.id')
                        ->whereNull('users.id')
                        ->count();

                    if ($orphanedSongs > 0) {
                        $issues[] = "{$orphanedSongs} orphaned songs without valid user";
                    }
                }

                if ($table === 'podcasts' && Schema::hasTable('users')) {
                    $orphanedPodcasts = DB::table('podcasts')
                        ->leftJoin('users', 'podcasts.user_id', '=', 'users.id')
                        ->whereNull('users.id')
                        ->count();

                    if ($orphanedPodcasts > 0) {
                        $issues[] = "{$orphanedPodcasts} orphaned podcasts without valid user";
                    }
                }

                // Check for duplicate slugs
                if (Schema::hasColumn($table, 'slug')) {
                    $duplicateSlugs = DB::table($table)
                        ->select('slug')
                        ->groupBy('slug')
                        ->havingRaw('COUNT(*) > 1')
                        ->count();

                    if ($duplicateSlugs > 0) {
                        $issues[] = "{$duplicateSlugs} duplicate slugs in {$table}";
                    }
                }
            }
        } catch (\Exception $e) {
            $issues[] = "Error checking data integrity for {$moduleName}: " . $e->getMessage();
        }

        return $issues;
    }

    protected function checkCrossModuleIntegration(bool $shouldFix): array
    {
        $issues = [];
        $healthy = true;

        // Check if User model has all module traits
        $requiredTraits = ['HasSaccoMembership', 'HasStore', 'HasPodcast'];

        foreach ($requiredTraits as $trait) {
            if (!$this->checkTraitIntegration($trait)) {
                $issues[] = "User model missing trait: {$trait}";
                $healthy = false;
            }
        }

        // Check CrossModuleRevenueService
        $revenueServicePath = app_path('Services/CrossModuleRevenueService.php');
        if (!file_exists($revenueServicePath)) {
            $issues[] = "CrossModuleRevenueService not found";
            $healthy = false;
        } else {
            $this->line("  ✅ CrossModuleRevenueService exists");
        }

        // Test basic cross-module functionality
        try {
            $testUser = User::first();
            if ($testUser) {
                // Test if user can access module relationships
                if (method_exists($testUser, 'ownedPodcasts')) {
                    $this->line("  ✅ User->ownedPodcasts() relationship works");
                } else {
                    $issues[] = "User model missing ownedPodcasts() relationship";
                    $healthy = false;
                }

                if (method_exists($testUser, 'storeProducts')) {
                    $this->line("  ✅ User->storeProducts() relationship works");
                } else {
                    $issues[] = "User model missing storeProducts() relationship";
                    $healthy = false;
                }

                if (method_exists($testUser, 'saccoMembership')) {
                    $this->line("  ✅ User->saccoMembership() relationship works");
                } else {
                    $issues[] = "User model missing saccoMembership() relationship";
                    $healthy = false;
                }
            }
        } catch (\Exception $e) {
            $issues[] = "Error testing cross-module relationships: " . $e->getMessage();
            $healthy = false;
        }

        return ['healthy' => $healthy, 'issues' => $issues];
    }
}