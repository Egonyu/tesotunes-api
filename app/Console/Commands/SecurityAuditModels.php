<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SecurityAuditModels extends Command
{
    protected $signature = 'security:audit-models {--fail-on-issues : Exit with non-zero code if issues found}';

    protected $description = 'Audit model $fillable arrays for privilege escalation fields and other security issues';

    /**
     * Fields that should NEVER be in $fillable on the User model
     * (direct privilege escalation risk)
     */
    private array $dangerousUserFields = [
        'role',
        'is_admin',
        'is_super_admin',
        'is_moderator',
    ];

    /**
     * Fields on User model that should be set only via dedicated service methods
     */
    private array $cautionUserFields = [
        'is_active',
        'is_premium',
        'credits',
        'ugx_balance',
        'permissions',
        'email_verified_at',
    ];

    /**
     * Fields that are universally dangerous regardless of model
     */
    private array $dangerousFields = [
        'is_admin',
        'is_super_admin',
    ];

    public function handle(): int
    {
        $this->info('🔒 TesoTunes Model Security Audit');
        $this->info('=================================');
        $this->newLine();

        $modelPath = app_path('Models');
        $issues = [];

        $files = File::allFiles($modelPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = File::get($file->getPathname());
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $modelName = $file->getFilenameWithoutExtension();
            $isUserModel = $modelName === 'User';
            $fillableContent = null;

            // Extract $fillable array content
            if (preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\]/s', $content, $matches)) {
                $fillableContent = $matches[1];

                // Parse only uncommented, active fillable fields
                $activeFields = $this->extractActiveFields($fillableContent);

                if ($isUserModel) {
                    // User model: check dangerous privilege fields
                    foreach ($this->dangerousUserFields as $field) {
                        if (in_array($field, $activeFields)) {
                            $issues[] = [
                                'severity' => 'CRITICAL',
                                'file' => $relativePath,
                                'field' => $field,
                                'issue' => "Privilege field '{$field}' in User \$fillable — direct privilege escalation",
                            ];
                        }
                    }

                    // User model: check caution fields (should use service methods)
                    foreach ($this->cautionUserFields as $field) {
                        if (in_array($field, $activeFields)) {
                            $issues[] = [
                                'severity' => 'HIGH',
                                'file' => $relativePath,
                                'field' => $field,
                                'issue' => "Sensitive field '{$field}' in User \$fillable — use dedicated service methods",
                            ];
                        }
                    }
                } else {
                    // Non-User models: only flag universally dangerous fields
                    foreach ($this->dangerousFields as $field) {
                        if (in_array($field, $activeFields)) {
                            $issues[] = [
                                'severity' => 'CRITICAL',
                                'file' => $relativePath,
                                'field' => $field,
                                'issue' => "Privilege field '{$field}' in \$fillable — potential escalation risk",
                            ];
                        }
                    }
                }
            }

            // Check for sensitive data in $hidden
            if ($isUserModel) {
                $hasFillablePassword = false;
                if ($fillableContent !== null) {
                    $activeFields = $this->extractActiveFields($fillableContent);
                    $hasFillablePassword = in_array('password', $activeFields);
                }

                if ($hasFillablePassword && ! preg_match("/protected\s+\\\$hidden\s*=.*?password/s", $content)) {
                    $issues[] = [
                        'severity' => 'MEDIUM',
                        'file' => $relativePath,
                        'field' => 'password',
                        'issue' => 'User model has password in $fillable but it may not be in $hidden',
                    ];
                }
            }
        }

        // Check sanctum config
        $sanctumConfig = File::get(config_path('sanctum.php'));
        if (str_contains($sanctumConfig, "'expiration' => null")) {
            $issues[] = [
                'severity' => 'CRITICAL',
                'file' => 'config/sanctum.php',
                'field' => 'expiration',
                'issue' => 'Sanctum token expiration is null — tokens never expire',
            ];
        }

        // Display results
        if (empty($issues)) {
            $this->info('✅ No model security issues found!');

            return Command::SUCCESS;
        }

        $criticals = array_filter($issues, fn ($i) => $i['severity'] === 'CRITICAL');
        $highs = array_filter($issues, fn ($i) => $i['severity'] === 'HIGH');
        $mediums = array_filter($issues, fn ($i) => $i['severity'] === 'MEDIUM');

        if (! empty($criticals)) {
            $this->error('🔴 CRITICAL Issues ('.count($criticals).')');
            $this->table(['Severity', 'File', 'Field', 'Issue'], array_map(fn ($i) => [
                $i['severity'], $i['file'], $i['field'], $i['issue'],
            ], $criticals));
        }

        if (! empty($highs)) {
            $this->warn('🟠 HIGH Issues ('.count($highs).')');
            $this->table(['Severity', 'File', 'Field', 'Issue'], array_map(fn ($i) => [
                $i['severity'], $i['file'], $i['field'], $i['issue'],
            ], $highs));
        }

        if (! empty($mediums)) {
            $this->line('🟡 MEDIUM Issues ('.count($mediums).')');
            $this->table(['Severity', 'File', 'Field', 'Issue'], array_map(fn ($i) => [
                $i['severity'], $i['file'], $i['field'], $i['issue'],
            ], $mediums));
        }

        $this->newLine();

        if ($this->option('fail-on-issues') && count($criticals) > 0) {
            $this->error('Model security audit FAILED.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Extract only uncommented field names from a $fillable block.
     * Skips lines that are commented out with // or wrapped in /* ... * /
     */
    private function extractActiveFields(string $fillableContent): array
    {
        $fields = [];
        $lines = explode("\n", $fillableContent);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip fully commented-out lines
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                continue;
            }

            // Remove inline comments before parsing
            $withoutInlineComment = preg_replace('/\/\/.*$/', '', $trimmed);
            $withoutInlineComment = preg_replace('/\/\*.*?\*\//', '', $withoutInlineComment);

            // Extract quoted field names
            if (preg_match_all("/['\"]([a-z_]+)['\"]/", $withoutInlineComment, $matches)) {
                $fields = array_merge($fields, $matches[1]);
            }
        }

        return $fields;
    }
}
