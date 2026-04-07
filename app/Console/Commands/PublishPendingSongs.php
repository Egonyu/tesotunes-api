<?php

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishPendingSongs extends Command
{
    protected $signature = 'songs:publish-pending {--dry-run : Show what would be published without making changes}';

    protected $description = 'Publish all pending songs and sync genre pivot entries';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 1. Find and publish pending songs
        $pendingSongs = Song::whereIn('status', ['pending', 'pending_review'])->get();
        $this->info("Found {$pendingSongs->count()} pending songs");

        if ($pendingSongs->isEmpty()) {
            $this->info('No pending songs to publish.');
        } else {
            foreach ($pendingSongs as $song) {
                $this->line("  - [{$song->id}] {$song->title} (artist_id: {$song->artist_id})");
            }

            if (! $dryRun) {
                Song::whereIn('status', ['pending', 'pending_review'])->update([
                    'status' => 'published',
                    'distribution_status' => 'approved',
                    'approved_at' => now(),
                    'published_at' => now(),
                ]);
                $this->info("Published {$pendingSongs->count()} songs.");
            } else {
                $this->warn('Dry run — no changes made.');
            }
        }

        // 2. Sync song_genres pivot for songs with primary_genre_id but no pivot entry
        $songsWithGenre = Song::whereNotNull('primary_genre_id')->get();
        $synced = 0;

        foreach ($songsWithGenre as $song) {
            $exists = DB::table('song_genres')
                ->where('song_id', $song->id)
                ->where('genre_id', $song->primary_genre_id)
                ->exists();

            if (! $exists) {
                $this->line("  Missing pivot: song #{$song->id} ({$song->title}) → genre #{$song->primary_genre_id}");
                if (! $dryRun) {
                    DB::table('song_genres')->insert([
                        'song_id' => $song->id,
                        'genre_id' => $song->primary_genre_id,
                        'is_primary' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $synced++;
            }
        }

        if ($synced > 0) {
            $this->info("Synced {$synced} genre pivot entries".($dryRun ? ' (dry run).' : '.'));
        } else {
            $this->info('All genre pivots are in sync.');
        }

        // 3. Refresh artist stats
        if (! $dryRun) {
            $artistIds = Song::distinct()->pluck('artist_id');
            foreach ($artistIds as $artistId) {
                $artist = \App\Models\Artist::find($artistId);
                if ($artist) {
                    $artist->refreshCachedStats();
                    $this->line("  Refreshed stats for artist #{$artist->id} ({$artist->stage_name})");
                }
            }
            $this->info('Artist stats refreshed.');
        }

        return Command::SUCCESS;
    }
}
