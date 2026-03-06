<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Share extends Model
{
    protected $fillable = [
        'user_id',
        'shareable_type',
        'shareable_id',
        'message',
        'platform',
        'view_count',
        'click_count',
    ];

    protected $casts = [
        'view_count' => 'integer',
        'click_count' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── Factory ─────────────────────────────────────────────────────────────

    /**
     * Create a share record and return it with the full share payload
     * (OG title, description, image, canonical URL) ready for the frontend.
     */
    public static function createShare(
        User $user,
        Model $shareable,
        ?string $message,
        string $platform = 'internal'
    ): self {
        return self::create([
            'user_id' => $user->id,
            'shareable_type' => get_class($shareable),
            'shareable_id' => $shareable->id,
            'message' => $message,
            'platform' => $platform,
        ]);
    }

    // ─── Engagement tracking ──────────────────────────────────────────────────

    public function recordView(): void
    {
        $this->increment('view_count');
    }

    public function recordClick(): void
    {
        $this->increment('click_count');
    }

    // ─── Share payload ────────────────────────────────────────────────────────

    /**
     * Build the rich share payload that the frontend uses to:
     *  - Copy to clipboard (share_url + caption)
     *  - Pass to Web Share API / social deep-links
     *  - Render an OG preview card
     *
     * Frontend flow:
     *   1. POST /api/shares  →  receives { share_payload }
     *   2. Shows bottom sheet with og_image, og_title, caption, platform links
     *   3. User picks platform → copy or open deep-link
     */
    public function sharePayload(): array
    {
        $shareable = $this->shareable;
        $frontendBase = rtrim(config('app.frontend_url', config('app.url')), '/');

        return match (class_basename($shareable)) {
            'Song' => $this->songPayload($shareable, $frontendBase),
            'Album' => $this->albumPayload($shareable, $frontendBase),
            'Artist' => $this->artistPayload($shareable, $frontendBase),
            'Playlist' => $this->playlistPayload($shareable, $frontendBase),
            default => [
                'share_url' => $frontendBase,
                'og_title' => config('app.name'),
                'og_description' => null,
                'og_image' => null,
                'caption' => config('app.name'),
                'platform_links' => $this->platformLinks($frontendBase, config('app.name')),
            ],
        };
    }

    private function songPayload(Model $song, string $base): array
    {
        $shareUrl = "{$base}/songs/{$song->slug}";
        $artistName = $song->artist?->stage_name ?? $song->artist?->name ?? 'TesoTunes';
        $title = "{$song->title} — {$artistName}";
        $description = "Listen to {$song->title} by {$artistName} on TesoTunes";
        $image = $song->artwork_url ?? null;

        return [
            'share_url' => $shareUrl,
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            // caption is prefilled text for social posts
            'caption' => "🎵 {$title}\n\n{$description}\n\n{$shareUrl}",
            'platform_links' => $this->platformLinks($shareUrl, $title),
        ];
    }

    private function albumPayload(Model $album, string $base): array
    {
        $shareUrl = "{$base}/albums/{$album->slug}";
        $artistName = $album->artist?->stage_name ?? $album->artist?->name ?? 'TesoTunes';
        $title = "{$album->title} — {$artistName}";
        $description = "Listen to {$album->title} by {$artistName} on TesoTunes";
        $image = \App\Helpers\StorageHelper::url($album->artwork);

        return [
            'share_url' => $shareUrl,
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            'caption' => "💿 {$title}\n\n{$description}\n\n{$shareUrl}",
            'platform_links' => $this->platformLinks($shareUrl, $title),
        ];
    }

    private function artistPayload(Model $artist, string $base): array
    {
        $shareUrl = "{$base}/artists/{$artist->slug}";
        $name = $artist->stage_name ?? $artist->name;
        $title = "{$name} on TesoTunes";
        $description = "Discover music by {$name} on TesoTunes";
        $image = \App\Helpers\StorageHelper::avatarUrl($artist->avatar, $name);

        return [
            'share_url' => $shareUrl,
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            'caption' => "🎤 {$title}\n\n{$description}\n\n{$shareUrl}",
            'platform_links' => $this->platformLinks($shareUrl, $title),
        ];
    }

    private function playlistPayload(Model $playlist, string $base): array
    {
        $shareUrl = "{$base}/playlists/{$playlist->slug}";
        $title = $playlist->name ?? $playlist->title;
        $description = "Listen to {$title} on TesoTunes";
        $image = \App\Helpers\StorageHelper::url($playlist->cover_image ?? $playlist->artwork ?? null);

        return [
            'share_url' => $shareUrl,
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            'caption' => "🎶 {$title}\n\n{$description}\n\n{$shareUrl}",
            'platform_links' => $this->platformLinks($shareUrl, $title),
        ];
    }

    /**
     * Build ready-to-use deep-link URLs for each social platform.
     * The frontend simply opens these URLs or uses the Web Share API.
     */
    private function platformLinks(string $shareUrl, string $title): array
    {
        $encoded = urlencode($shareUrl);
        $encodedTitle = urlencode($title);

        return [
            'copy' => $shareUrl,
            'whatsapp' => "https://wa.me/?text={$encodedTitle}%20{$encoded}",
            'twitter' => "https://twitter.com/intent/tweet?text={$encodedTitle}&url={$encoded}&hashtags=TesoTunes",
            'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encoded}",
            'telegram' => "https://t.me/share/url?url={$encoded}&text={$encodedTitle}",
            'instagram' => null, // Instagram has no web share URL; handled natively on mobile
        ];
    }
}
