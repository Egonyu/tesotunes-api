<?php

namespace App\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPERATIONAL COMMAND — applies the wallet PIN columns to an environment whose
 * users table was created before they were added to the identity/access
 * baseline migration.
 *
 * The baseline migration remains the schema source of truth (fresh installs get
 * these columns automatically); this brings already-migrated databases in line
 * without introducing a patch migration.
 *
 * Every step is guarded by hasColumn(), so the command is idempotent and a safe
 * no-op on databases that already have the columns.
 */
class InstallWalletPinColumnsCommand extends Command
{
    protected $signature = 'wallet:install-pin-columns';

    protected $description = 'Add the wallet PIN columns to users (idempotent; no-op when already present)';

    public function handle(): int
    {
        if (! Schema::hasTable('users')) {
            $this->error('No users table found on connection: '.DB::connection()->getDatabaseName());

            return self::FAILURE;
        }

        $missing = collect([
            'wallet_pin',
            'wallet_pin_set_at',
            'wallet_pin_failed_attempts',
            'wallet_pin_locked_until',
        ])->reject(fn (string $column) => Schema::hasColumn('users', $column));

        if ($missing->isEmpty()) {
            $this->info('Wallet PIN columns already present — nothing to do.');

            return self::SUCCESS;
        }

        Schema::table('users', function (Blueprint $table) use ($missing) {
            if ($missing->contains('wallet_pin')) {
                $table->string('wallet_pin')->nullable();
            }
            if ($missing->contains('wallet_pin_set_at')) {
                $table->timestamp('wallet_pin_set_at')->nullable();
            }
            if ($missing->contains('wallet_pin_failed_attempts')) {
                $table->unsignedTinyInteger('wallet_pin_failed_attempts')->default(0);
            }
            if ($missing->contains('wallet_pin_locked_until')) {
                $table->timestamp('wallet_pin_locked_until')->nullable();
            }
        });

        $this->info('Added: '.$missing->implode(', '));

        return self::SUCCESS;
    }
}
