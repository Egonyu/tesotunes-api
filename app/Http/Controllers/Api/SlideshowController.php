<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SlideshowController extends Controller
{
    public function index(Request $request)
    {
        $section = $request->route('section') ?? 'home';

        $slides = Cache::remember("slides.api.{$section}", 3600, function () use ($section) {
            $query = Slide::where('visibility', 1)
                ->with(['user:id,name,username']);

            if ($section !== 'all') {
                $query->where('allow_'.$section, 1);
            }

            return $query->get()->map(function ($slide) {
                return $this->formatSlide($slide);
            });
        });

        return response()->json([
            'data' => $slides,
            'meta' => [
                'section' => $section,
                'count' => $slides->count(),
            ],
        ]);
    }

    public function byGenre(Request $request, $genreSlug)
    {
        $genre = \App\Models\Genre::where('slug', $genreSlug)->firstOrFail();

        $slides = Cache::remember("slides.api.genre.{$genreSlug}", 3600, function () use ($genre) {
            return Slide::where('visibility', 1)
                ->where('genre', 'LIKE', '%'.$genre->id.'%')
                ->with(['user:id,name,username'])
                ->get()
                ->map(function ($slide) {
                    return $this->formatSlide($slide);
                });
        });

        return response()->json([
            'data' => $slides,
            'meta' => [
                'genre' => $genre->name,
                'count' => $slides->count(),
            ],
        ]);
    }

    public function byMood(Request $request, $moodSlug)
    {
        $mood = \App\Models\Mood::where('slug', $moodSlug)->firstOrFail();

        $slides = Cache::remember("slides.api.mood.{$moodSlug}", 3600, function () use ($mood) {
            return Slide::where('visibility', 1)
                ->where('mood', 'LIKE', '%'.$mood->id.'%')
                ->with(['user:id,name,username'])
                ->get()
                ->map(function ($slide) {
                    return $this->formatSlide($slide);
                });
        });

        return response()->json([
            'data' => $slides,
            'meta' => [
                'mood' => $mood->name,
                'count' => $slides->count(),
            ],
        ]);
    }

    private function formatSlide($slide)
    {
        $object = $slide->object;

        return [
            'id' => $slide->id,
            'title' => $slide->title,
            'description' => $slide->description,
            'title_link' => $slide->title_link,
            'artwork' => [
                'sm' => $slide->getFirstMediaUrl('artwork', 'sm'),
                'md' => $slide->getFirstMediaUrl('artwork', 'md'),
                'lg' => $slide->getFirstMediaUrl('artwork', 'lg'),
                'original' => $slide->getFirstMediaUrl('artwork'),
            ],
            'object_type' => $slide->object_type,
            'object' => $object ? $this->formatObject($slide->object_type, $object) : null,
            'creator' => $slide->user ? [
                'id' => $slide->user->id,
                'name' => $slide->user->name,
                'username' => $slide->user->username,
            ] : null,
            'priority' => $slide->priority,
            'created_at' => $slide->created_at->toIso8601String(),
        ];
    }

    private function formatObject($type, $object)
    {
        $baseData = [
            'id' => $object->id,
            'title' => $object->title ?? $object->name ?? null,
        ];

        switch ($type) {
            case 'song':
                return array_merge($baseData, [
                    'slug' => $object->slug ?? null,
                    'artist' => $object->artist->name ?? null,
                    'album' => $object->album->title ?? null,
                    'duration' => $object->duration ?? null,
                    'artwork_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('artwork') : null,
                ]);

            case 'album':
                return array_merge($baseData, [
                    'slug' => $object->slug ?? null,
                    'artist' => $object->artist->name ?? null,
                    'year' => $object->year ?? null,
                    'artwork_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('artwork') : null,
                ]);

            case 'artist':
                return array_merge($baseData, [
                    'slug' => $object->slug ?? null,
                    'bio' => $object->bio ?? null,
                    'artwork_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('artwork') : null,
                ]);

            case 'playlist':
                return array_merge($baseData, [
                    'slug' => $object->slug ?? null,
                    'description' => $object->description ?? null,
                    'songs_count' => $object->songs_count ?? $object->songs()->count() ?? null,
                    'artwork_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('artwork') : null,
                ]);

            case 'station':
                return array_merge($baseData, [
                    'slug' => $object->slug ?? null,
                    'description' => $object->description ?? null,
                    'artwork_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('artwork') : null,
                ]);

            case 'user':
                return array_merge($baseData, [
                    'username' => $object->username ?? null,
                    'name' => $object->name ?? null,
                    'avatar_url' => method_exists($object, 'getFirstMediaUrl') ? $object->getFirstMediaUrl('avatar') : null,
                ]);

            default:
                return $baseData;
        }
    }
}
