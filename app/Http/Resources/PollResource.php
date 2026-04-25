<?php

namespace App\Http\Resources;

use App\Helpers\StorageHelper;
use App\Models\Modules\Forum\Poll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $hasVoted = $user ? $this->userHasVoted($user) : false;

        $showResults = $this->status === 'closed'
            || $hasVoted
            || $this->show_results_before_vote;

        PollOptionResource::$showResults = $showResults;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,

            // Gamification
            'poll_type' => $this->poll_type ?? Poll::TYPE_GENERAL,
            'category' => $this->category,
            'category_label' => Poll::CATEGORIES[$this->category] ?? null,
            'credits_reward' => $this->credits_reward ?? 3,

            // Settings
            'allow_multiple_votes' => (bool) $this->allow_multiple_votes,
            'show_results_before_vote' => (bool) $this->show_results_before_vote,
            'is_anonymous' => (bool) $this->is_anonymous,

            // Status & timing
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            // Stats
            'total_votes' => $this->total_votes,

            // Options — eager-load with song/artist for typed polls
            'options' => PollOptionResource::collection($this->whenLoaded('options')),

            // User context
            'has_voted' => $this->when($user !== null, $hasVoted),
            'user_vote' => $this->when(
                $user && $hasVoted,
                fn () => $this->votes->where('user_id', $user->id)->pluck('option_id')
            ),

            // Creator
            'creator' => $this->when($this->relationLoaded('user') && ! $this->is_anonymous, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => StorageHelper::avatarUrl($this->user->avatar, $this->user->name),
                'is_verified' => (bool) ($this->user->is_verified ?? false),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
