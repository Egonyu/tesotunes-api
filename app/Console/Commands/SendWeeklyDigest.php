<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendWeeklyDigest extends Command
{
    protected $signature = 'notifications:weekly-digest {--dry-run : Show stats without sending}';

    protected $description = 'Send weekly music digest emails to active users';

    public function handle(): int
    {
        $weekAgo = now()->subWeek();
        $isDryRun = $this->option('dry-run');

        // Get active users who have play history in the last week
        $activeUserIds = DB::table('play_history')
            ->where('created_at', '>=', $weekAgo)
            ->distinct('user_id')
            ->pluck('user_id');

        $this->info("Found {$activeUserIds->count()} active users for weekly digest.");

        if ($activeUserIds->isEmpty()) {
            $this->info('No active users found. Skipping digest.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($activeUserIds->chunk(100) as $chunk) {
            $users = User::whereIn('id', $chunk)->get();

            foreach ($users as $user) {
                $digest = $this->buildDigestForUser($user, $weekAgo);

                if ($isDryRun) {
                    $this->line("  [{$user->display_name}] songs={$digest['songs_listened']}, min={$digest['minutes_listened']}");

                    continue;
                }

                try {
                    $user->notify(new WeeklyDigestNotification($digest));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning("Weekly digest failed for user {$user->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info($isDryRun ? 'Dry run complete.' : "Sent {$sent} weekly digest notifications.");

        return self::SUCCESS;
    }

    private function buildDigestForUser(User $user, $since): array
    {
        $plays = DB::table('play_history')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->get();

        $songsListened = $plays->unique('song_id')->count();
        $totalSeconds = $plays->sum('duration_played');
        $minutesListened = (int) round($totalSeconds / 60);

        // Top song
        $topSongId = $plays->groupBy('song_id')
            ->sortByDesc(fn ($group) => $group->count())
            ->keys()
            ->first();

        $topSong = $topSongId
            ? DB::table('songs')->where('id', $topSongId)->value('title')
            : null;

        // Top artist
        $topArtistId = $topSongId
            ? DB::table('songs')->where('id', $topSongId)->value('artist_id')
            : null;

        $topArtist = $topArtistId
            ? DB::table('artists')->where('id', $topArtistId)->value('name')
            : null;

        // New followers
        $newFollowers = DB::table('user_follows')
            ->where('following_id', $user->id)
            ->where('created_at', '>=', $since)
            ->count();

        // Credits earned
        $creditsEarned = DB::table('credit_transactions')
            ->where('user_id', $user->id)
            ->where('type', 'earn')
            ->where('created_at', '>=', $since)
            ->sum('amount');

        return [
            'songs_listened' => $songsListened,
            'minutes_listened' => $minutesListened,
            'top_song' => $topSong,
            'top_artist' => $topArtist,
            'new_followers' => $newFollowers,
            'credits_earned' => (float) $creditsEarned,
        ];
    }
}
