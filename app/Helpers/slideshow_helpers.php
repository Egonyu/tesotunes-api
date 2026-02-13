<?php

use App\Models\Slide;
use Illuminate\Support\Facades\Cache;

if (!function_exists('getSlidesBySection')) {
    /**
     * Get slides for a specific section
     *
     * @param string $section Section name (home, discover, radio, etc.)
     * @param bool $cache Whether to use cache
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function getSlidesBySection($section, $cache = true)
    {
        if ($cache) {
            return Cache::remember("slides.{$section}", 3600, function () use ($section) {
                return Slide::where('visibility', 1)
                    ->where("allow_{$section}", 1)
                    ->with(['user'])
                    ->get();
            });
        }

        return Slide::where('visibility', 1)
            ->where("allow_{$section}", 1)
            ->with(['user'])
            ->get();
    }
}

if (!function_exists('getSlidesByGenre')) {
    /**
     * Get slides for a specific genre
     *
     * @param int|string $genreId Genre ID or slug
     * @param bool $cache Whether to use cache
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function getSlidesByGenre($genreId, $cache = true)
    {
        // If slug is passed, get the ID
        if (!is_numeric($genreId)) {
            $genre = \App\Models\Genre::where('slug', $genreId)->first();
            $genreId = $genre ? $genre->id : null;
        }

        if (!$genreId) {
            return collect([]);
        }

        if ($cache) {
            return Cache::remember("slides.genre.{$genreId}", 3600, function () use ($genreId) {
                return Slide::where('visibility', 1)
                    ->where('genre', 'LIKE', "%{$genreId}%")
                    ->with(['user'])
                    ->get();
            });
        }

        return Slide::where('visibility', 1)
            ->where('genre', 'LIKE', "%{$genreId}%")
            ->with(['user'])
            ->get();
    }
}

if (!function_exists('getSlidesByMood')) {
    /**
     * Get slides for a specific mood
     *
     * @param int|string $moodId Mood ID or slug
     * @param bool $cache Whether to use cache
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function getSlidesByMood($moodId, $cache = true)
    {
        // If slug is passed, get the ID
        if (!is_numeric($moodId)) {
            $mood = \App\Models\Mood::where('slug', $moodId)->first();
            $moodId = $mood ? $mood->id : null;
        }

        if (!$moodId) {
            return collect([]);
        }

        if ($cache) {
            return Cache::remember("slides.mood.{$moodId}", 3600, function () use ($moodId) {
                return Slide::where('visibility', 1)
                    ->where('mood', 'LIKE', "%{$moodId}%")
                    ->with(['user'])
                    ->get();
            });
        }

        return Slide::where('visibility', 1)
            ->where('mood', 'LIKE', "%{$moodId}%")
            ->with(['user'])
            ->get();
    }
}

if (!function_exists('getFeaturedSlides')) {
    /**
     * Get all visible featured slides
     *
     * @param int $limit Number of slides to return
     * @param bool $cache Whether to use cache
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function getFeaturedSlides($limit = 10, $cache = true)
    {
        if ($cache) {
            return Cache::remember("slides.featured.{$limit}", 3600, function () use ($limit) {
                return Slide::where('visibility', 1)
                    ->with(['user'])
                    ->limit($limit)
                    ->get();
            });
        }

        return Slide::where('visibility', 1)
            ->with(['user'])
            ->limit($limit)
            ->get();
    }
}

if (!function_exists('clearSlidesCache')) {
    /**
     * Clear all slides cache
     *
     * @return void
     */
    function clearSlidesCache()
    {
        $sections = ['home', 'discover', 'radio', 'community', 'trending', 'channels'];
        
        foreach ($sections as $section) {
            Cache::forget("slides.{$section}");
            Cache::forget("slides.api.{$section}");
        }

        // Clear genre and mood caches (we don't know all IDs, so we'll let them expire naturally)
        Cache::flush(); // or use a more targeted approach with cache tags
    }
}

if (!function_exists('getSlideArtwork')) {
    /**
     * Get slide artwork URL
     *
     * @param \App\Models\Slide $slide
     * @param string $conversion Size conversion (sm, md, lg)
     * @return string|null
     */
    function getSlideArtwork($slide, $conversion = 'lg')
    {
        if (!$slide) {
            return null;
        }

        return $slide->getFirstMediaUrl('artwork', $conversion) ?: $slide->getFirstMediaUrl('artwork');
    }
}

if (!function_exists('formatSlideForDisplay')) {
    /**
     * Format slide data for display
     *
     * @param \App\Models\Slide $slide
     * @return array
     */
    function formatSlideForDisplay($slide)
    {
        return [
            'id' => $slide->id,
            'title' => $slide->title,
            'description' => $slide->description,
            'link' => $slide->title_link,
            'artwork' => getSlideArtwork($slide),
            'type' => $slide->object_type,
            'object' => $slide->object,
        ];
    }
}
