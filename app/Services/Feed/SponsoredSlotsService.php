<?php

namespace App\Services\Feed;

use App\Helpers\StorageHelper;
use App\Models\FeaturedContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Platform-sponsored cards woven into the Edula feed — the console earning
 * as a promoter. Inventory comes from the admin Featured Content tool;
 * every card is explicitly labeled sponsored on the client.
 */
class SponsoredSlotsService
{
    /**
     * Insert sponsored cards into a page of post-shaped feed items, one
     * after every N organic items. Page-aware so infinite scroll keeps the
     * cadence without repeating the same card back to back.
     *
     * @param  Collection<int, array>  $pageItems
     * @return Collection<int, array>
     */
    public function injectInto(Collection $pageItems, int $page = 1): Collection
    {
        if (! config('feed.sponsored.enabled', true) || $pageItems->isEmpty()) {
            return $pageItems;
        }

        $every = max(2, (int) config('feed.sponsored.every', 5));
        $slotsOnPage = (int) floor($pageItems->count() / $every);

        if ($slotsOnPage === 0) {
            return $pageItems;
        }

        $cards = $this->activeCards();

        if ($cards->isEmpty()) {
            return $pageItems;
        }

        $result = collect();
        $cardCursor = ($page - 1) * $slotsOnPage;

        foreach ($pageItems as $index => $item) {
            $result->push($item);

            if (($index + 1) % $every === 0) {
                $result->push($cards[$cardCursor % $cards->count()]);
                $cardCursor++;
            }
        }

        return $result->values();
    }

    /**
     * Active featured-content entries as post-shaped sponsored cards.
     *
     * @return Collection<int, array>
     */
    public function activeCards(): Collection
    {
        return FeaturedContent::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')
            ->limit(10)
            ->get()
            ->map(fn (FeaturedContent $content) => $this->toCard($content))
            ->values();
    }

    private function toCard(FeaturedContent $content): array
    {
        $imageUrl = $content->image_path ? StorageHelper::url($content->image_path) : null;

        return [
            // Negative id keeps client list keys unique without colliding
            // with real posts or feed items.
            'id' => -1 * $content->id,
            'uuid' => 'sponsored-'.($content->uuid ?: Str::uuid()->toString()),
            'source' => 'sponsored',
            'feed_type' => 'sponsored',
            'module' => 'platform',
            'is_sponsored' => true,
            'author' => [
                'id' => 0,
                'name' => 'TesoTunes',
                'username' => 'tesotunes',
                'avatar_url' => '',
                'is_verified' => true,
            ],
            'content' => $content->subtitle ?? '',
            'title' => $content->title,
            'link' => $content->link,
            'media' => $imageUrl ? [
                'type' => 'image',
                'url' => $imageUrl,
                'thumbnail_url' => $imageUrl,
            ] : null,
            'visibility' => 'public',
            'created_at' => now()->toIso8601String(),
            'likes_count' => 0,
            'comments_count' => 0,
            'reposts_count' => 0,
            'views_count' => 0,
            'is_liked' => false,
            'is_reposted' => false,
            'is_bookmarked' => false,
        ];
    }
}
