<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFollow;
use App\Services\CrossModuleNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReengageInactiveUsersCommand extends Command
{
    protected $signature = 'notifications:reengage-inactive
                            {--days=7 : Days of inactivity before targeting a user}
                            {--dry-run : Show counts without sending}';

    protected $description = 'Notify users inactive for N days about new releases from artists they follow';

    public function handle(CrossModuleNotificationService $notificationService): int
    {
        $inactiveDays = (int) $this->option('days');
        $isDryRun = $this->option('dry-run');
        $cutoff = now()->subDays($inactiveDays);

        // Users who have played at least once ever, but not in the last N days
        $inactiveUserIds = DB::table('play_histories')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('MAX(played_at) < ?', [$cutoff])
            ->pluck('user_id');

        $this->info("Found {$inactiveUserIds->count()} users inactive for {$inactiveDays}+ days.");

        if ($inactiveUserIds->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($inactiveUserIds->chunk(100) as $chunk) {
            $users = User::whereIn('id', $chunk)->get();

            foreach ($users as $user) {
                $song = $this->findNewReleaseForUser($user, $cutoff);

                if (! $song) {
                    $skipped++;

                    continue;
                }

                $artist = $song->artist;

                if ($isDryRun) {
                    $this->line("  [{$user->name}] → \"{$song->title}\" by {$artist->name}");

                    continue;
                }

                try {
                    $notificationService->sendToUser(
                        $user,
                        'music',
                        'artist_release',
                        'Your favourite artists dropped new music',
                        "{$artist->name} released \"{$song->title}\" while you were away",
                        [
                            'song_id' => $song->id,
                            'artist_id' => $artist->id,
                            'artist_name' => $artist->name,
                            'song_title' => $song->title,
                        ]
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning("Re-engagement notification failed for user {$user->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info($isDryRun
            ? "Dry run complete. Would notify {$inactiveUserIds->count()} users ({$skipped} skipped — no new releases)."
            : "Sent {$sent} re-engagement notifications. Skipped {$skipped} users (no new releases from followed artists)."
        );

        return self::SUCCESS;
    }

    private function findNewReleaseForUser(User $user, \Carbon\Carbon $since): ?Song
    {
        $followedArtistIds = UserFollow::query()
            ->where('follower_id', $user->id)
            ->where('followable_type', Artist::class)
            ->pluck('followable_id');

        if ($followedArtistIds->isEmpty()) {
            return null;
        }

        return Song::query()
            ->whereIn('artist_id', $followedArtistIds)
            ->where('status', 'approved')
            ->where('created_at', '>=', $since)
            ->with('artist')
            ->latest('created_at')
            ->first();
    }
}
