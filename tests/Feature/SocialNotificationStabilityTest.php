<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Song;
use App\Models\Artist;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SocialNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_follow_creates_custom_notification_record(): void
    {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        $follower->follow($target);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $target->id,
            'type' => 'new_follower',
            'category' => 'social',
            'actor_id' => $follower->id,
        ]);
    }

    public function test_like_creates_custom_notification_record(): void
    {
        $owner = User::factory()->create();
        $liker = User::factory()->create();
        $song = $this->createSong($owner);

        Like::toggle($liker, $song);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'content_liked',
            'category' => 'social',
            'actor_id' => $liker->id,
        ]);
    }

    public function test_post_comment_and_share_create_custom_notification_records(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $post = Post::create([
            'user_id' => $owner->id,
            'uuid' => (string) \Str::uuid(),
            'content' => 'Original post',
            'type' => 'text',
            'privacy' => 'public',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $comment = $post->addComment($actor, 'Nice post');
        $sharedPost = $post->share($actor, 'Sharing this');

        $this->assertNotNull($comment);
        $this->assertNotNull($sharedPost);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'post_comment',
            'category' => 'social',
            'actor_id' => $actor->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'post_shared',
            'category' => 'social',
            'actor_id' => $actor->id,
        ]);
    }

    public function test_playlist_activity_creates_custom_notification_record(): void
    {
        $owner = User::factory()->create();
        $follower = User::factory()->create();
        $addedBy = User::factory()->create();
        $song = $this->createSong($addedBy);
        $playlist = Playlist::create([
            'user_id' => $owner->id,
            'name' => 'Tracker Playlist',
            'visibility' => 'public',
        ]);

        UserFollow::create([
            'follower_id' => $follower->id,
            'followable_type' => Playlist::class,
            'followable_id' => $playlist->id,
        ]);

        $playlist->addSong($song, $addedBy);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $follower->id,
            'type' => 'playlist_activity',
            'category' => 'social',
            'actor_id' => $addedBy->id,
        ]);
    }

    public function test_comment_controller_creates_custom_notification_records_for_comment_and_reply(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $replier = User::factory()->create();
        $song = $this->createSong($owner);

        $this->actingAs($commenter)->postJson('/api/comments', [
            'commentable_type' => 'song',
            'commentable_id' => $song->id,
            'content' => 'Top level comment',
        ])->assertCreated();

        $comment = Comment::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'new_comment',
            'category' => 'social',
            'actor_id' => $commenter->id,
        ]);

        $this->actingAs($replier)->postJson('/api/comments', [
            'commentable_type' => 'song',
            'commentable_id' => $song->id,
            'content' => 'Reply comment',
            'parent_id' => $comment->id,
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $commenter->id,
            'type' => 'comment_reply',
            'category' => 'social',
            'actor_id' => $replier->id,
        ]);
    }

    protected function createSong(User $owner): Song
    {
        $artist = Artist::factory()->create([
            'user_id' => $owner->id,
        ]);

        return Song::create([
            'user_id' => $owner->id,
            'uuid' => (string) \Str::uuid(),
            'artist_id' => $artist->id,
            'title' => 'Tracker Song',
            'slug' => 'tracker-song-'.$owner->id.'-'.strtolower(\Str::random(6)),
            'duration_seconds' => 180,
            'file_size_bytes' => 1234567,
            'status' => 'published',
            'visibility' => 'public',
            'price' => 0,
            'currency' => 'UGX',
            'play_count' => 0,
            'download_count' => 0,
            'like_count' => 0,
            'share_count' => 0,
            'is_downloadable' => true,
        ]);
    }
}
