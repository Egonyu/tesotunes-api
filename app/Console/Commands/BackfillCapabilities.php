<?php

namespace App\Console\Commands;

use App\Enums\Capability;
use App\Models\Artist;
use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Store\Models\Store;
use App\Services\Accounts\CapabilityService;
use Illuminate\Console\Command;

/**
 * Seeds capability grants from the pre-capability sources of truth:
 * approved artists, store owners, onboarded promoter profiles, and the
 * legacy event_organizer settings-JSON flag. Idempotent — granting an
 * already-granted capability is a no-op.
 */
class BackfillCapabilities extends Command
{
    protected $signature = 'capabilities:backfill';

    protected $description = 'Seed capability grants from existing artists, stores, promoter profiles, and legacy organizer flags';

    public function handle(CapabilityService $capabilities): int
    {
        $granted = ['artist' => 0, 'seller' => 0, 'promoter' => 0, 'organizer' => 0];

        Artist::query()
            ->whereIn('status', [\App\Enums\ArtistStatus::Approved->value, 'active', 'verified'])
            ->whereNotNull('user_id')
            ->with('user')
            ->chunkById(200, function ($artists) use ($capabilities, &$granted) {
                foreach ($artists as $artist) {
                    if ($artist->user) {
                        $capabilities->grant($artist->user, Capability::Artist, $artist);
                        $granted['artist']++;
                    }
                }
            });

        Store::query()
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->with('user')
            ->chunkById(200, function ($stores) use ($capabilities, &$granted) {
                foreach ($stores as $store) {
                    if ($store->user) {
                        $capabilities->grant($store->user, Capability::Seller, $store);
                        $granted['seller']++;
                    }
                }
            });

        PromoterProfile::query()
            ->whereNotNull('onboarded_at')
            ->where('status', PromoterProfile::STATUS_ACTIVE)
            ->with('user')
            ->chunkById(200, function ($profiles) use ($capabilities, &$granted) {
                foreach ($profiles as $profile) {
                    if ($profile->user) {
                        $capabilities->grant($profile->user, Capability::Promoter, $profile);
                        $granted['promoter']++;
                    }
                }
            });

        User::query()->chunkById(200, function ($users) use ($capabilities, &$granted) {
            foreach ($users as $user) {
                if ((bool) ($user->getEventOrganizerProfile()['enabled'] ?? false)) {
                    $capabilities->grant($user, Capability::Organizer);
                    $granted['organizer']++;
                }
            }
        });

        foreach ($granted as $capability => $count) {
            $this->info(str_pad($capability, 10).$count.' grant(s)');
        }

        return self::SUCCESS;
    }
}
