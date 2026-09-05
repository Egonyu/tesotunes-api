<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\Like;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The activity spine feeds both the dashboard timeline and the social feed.
 *
 * It had timestamps disabled on a table that declares them, so only writers
 * passing created_at by hand produced a dated row — 72% of production rows
 * were NULL, sorted out of `latest()`, and rendered a blank date.
 */
class ActivitySpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_activities_are_dated_without_the_writer_passing_a_timestamp(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        $activity = $user->activities()->create([
            'type' => 'shared_song',
            'subject_type' => Song::class,
            'subject_id' => $song->id,
        ]);

        $this->assertNotNull($activity->fresh()->created_at);
    }

    public function test_create_for_user_still_honours_an_explicit_timestamp(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        $activity = Activity::createForUser($user, 'liked_song', $song);

        $this->assertNotNull($activity->created_at);
    }

    public function test_liking_writes_the_type_the_feed_actually_maps(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Like::toggle($user, $song);

        $this->assertDatabaseHas('activities', [
            'user_id' => $user->id,
            'type' => 'liked_song',
        ]);
    }

    public function test_unliking_writes_a_lowercase_type_too(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Like::toggle($user, $song);
        Like::toggle($user, $song);

        $this->assertDatabaseHas('activities', [
            'user_id' => $user->id,
            'type' => 'unliked_song',
        ]);
    }

    public function test_repair_command_renames_legacy_types(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        DB::table('activities')->insert([
            'user_id' => $user->id,
            'type' => 'commented_on_song',
            'subject_type' => Song::class,
            'subject_id' => $song->id,
            'created_at' => now(),
        ]);

        $this->artisan('activities:repair-spine')->assertSuccessful();

        $this->assertDatabaseHas('activities', ['type' => 'commented_song']);
        $this->assertDatabaseMissing('activities', ['type' => 'commented_on_song']);
    }

    public function test_repair_command_dates_an_activity_from_its_subject(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create(['created_at' => now()->subDays(9)]);

        DB::table('activities')->insert([
            'user_id' => $user->id,
            'type' => 'uploaded_song',
            'subject_type' => Song::class,
            'subject_id' => $song->id,
            'created_at' => null,
        ]);

        $this->artisan('activities:repair-spine')->assertSuccessful();

        $repaired = DB::table('activities')->where('type', 'uploaded_song')->first();

        $this->assertNotNull($repaired->created_at);
        $this->assertSame(
            $song->created_at->toDateString(),
            substr((string) $repaired->created_at, 0, 10)
        );
    }

    public function test_repair_command_dry_run_writes_nothing(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        DB::table('activities')->insert([
            'user_id' => $user->id,
            'type' => 'created_store',
            'subject_type' => Song::class,
            'subject_id' => $song->id,
            'created_at' => null,
        ]);

        $this->artisan('activities:repair-spine', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('activities', ['type' => 'created_store']);
        $this->assertNull(DB::table('activities')->value('created_at'));
    }
}
