<?php

namespace Tests\Feature\Api;

use App\Models\Download;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /api/user/library ordered downloads by created_at, a column the
 * downloads table does not have, so every signed-in request returned 500 —
 * with or without any downloads.
 */
class UserLibraryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_library_loads_for_a_user_without_downloads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/user/library')
            ->assertOk()
            ->assertJsonPath('data.downloads', [])
            ->assertJsonPath('data.counts.downloads', 0);
    }

    public function test_library_lists_song_downloads_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Song::factory()->create();
        $newer = Song::factory()->create();

        foreach ([[$older, now()->subDay()], [$newer, now()]] as [$song, $at]) {
            (new Download)->forceFill([
                'user_id' => $user->id,
                'downloadable_type' => $song->getMorphClass(),
                'downloadable_id' => $song->id,
                'downloaded_at' => $at,
            ])->save();
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/user/library')
            ->assertOk()
            ->assertJsonPath('data.downloads.0.id', $newer->id)
            ->assertJsonPath('data.downloads.1.id', $older->id)
            ->assertJsonPath('data.counts.downloads', 2);
    }
}
