<?php

namespace App\Services;

use App\Models\Song;
use Illuminate\Support\Str;

class SongSlugService
{
    public function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug !== '' ? $baseSlug : 'song';
        $originalSlug = $slug;
        $counter = 1;

        while (Song::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        return $slug;
    }

    public function releaseForSoftDelete(Song $song): void
    {
        if (! $song->slug) {
            return;
        }

        $releasedSlug = $song->slug.'-deleted-'.$song->id.'-'.now()->timestamp;

        $song->forceFill([
            'slug' => $releasedSlug,
        ])->saveQuietly();
    }
}
