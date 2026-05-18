<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill promoter_profiles from stores.metadata->promoter_profile JSON.
     *
     * Idempotent: uses firstOrCreate on user_id so re-running is safe.
     * The original stores.metadata is preserved until the dual-write window ends.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        $stores = DB::table('stores')
            ->whereNotNull('metadata')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.promoter_profile')) IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.promoter_profile')) != 'null'")
            ->get(['id', 'user_id', 'metadata']);

        foreach ($stores as $store) {
            $metadata = json_decode($store->metadata, true);
            $profile = $metadata['promoter_profile'] ?? null;

            if (empty($profile) || ! is_array($profile)) {
                continue;
            }

            // Skip if this user already has a promoter_profile row (idempotency)
            $exists = DB::table('promoter_profiles')
                ->where('user_id', $store->user_id)
                ->exists();

            if ($exists) {
                continue;
            }

            // Derive display_name from user record
            $user = DB::table('users')->where('id', $store->user_id)->first(['name', 'username']);
            $displayName = $user?->name ?? $user?->username ?? 'Promoter';

            // Build a slug unique across the table
            $baseSlug = Str::slug($user?->username ?? $displayName);
            $slug = $baseSlug;
            $suffix = 1;
            while (DB::table('promoter_profiles')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            DB::table('promoter_profiles')->insert([
                'uuid' => Str::uuid()->toString(),
                'user_id' => $store->user_id,
                'store_id' => $store->id,
                'display_name' => $displayName,
                'slug' => $slug,
                'bio' => $profile['bio'] ?? null,
                'platforms' => isset($profile['platforms']) ? json_encode($profile['platforms']) : null,
                'niches' => isset($profile['niches']) ? json_encode($profile['niches']) : null,
                'audience_regions' => isset($profile['audience_regions']) ? json_encode($profile['audience_regions']) : null,
                'audience_summary' => $profile['audience_summary'] ?? null,
                'social_links' => isset($profile['social_links']) ? json_encode($profile['social_links']) : null,
                'portfolio_items' => isset($profile['portfolio_items']) ? json_encode($profile['portfolio_items']) : null,
                'proof_points' => isset($profile['proof_points']) ? json_encode($profile['proof_points']) : null,
                'campaign_highlights' => isset($profile['campaign_highlights']) ? json_encode($profile['campaign_highlights']) : null,
                'response_time_hours' => isset($profile['response_time_hours']) ? (int) $profile['response_time_hours'] : null,
                'tier' => 'starter',
                'is_verified' => false,
                'status' => 'active',
                'onboarded_at' => now(),
                'metadata' => json_encode(['migrated_from' => 'stores.metadata', 'store_id' => $store->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Down: remove only rows that were created by this backfill (identified by metadata flag).
     * Rows created by real onboarding after migration are left untouched.
     */
    public function down(): void
    {
        DB::table('promoter_profiles')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.migrated_from')) = 'stores.metadata'")
            ->delete();
    }
};
