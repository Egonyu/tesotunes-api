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
        $sessionToken = $request->cookie('poll_session_token');

        $hasResponded = match (true) {
            $user !== null => $this->hasUserResponded($user->id),
            $sessionToken !== null => $this->hasGuestResponded($sessionToken),
            default => false,
        };

        $showResults = $this->status === Poll::STATUS_CLOSED
            || $hasResponded
            || (bool) $this->show_results_before_completion;

        PollQuestionResource::$showResults = $showResults;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'poll_type' => $this->poll_type,
            'category' => $this->category,
            'category_label' => Poll::CATEGORIES[$this->category] ?? null,
            'audience' => $this->audience,

            // Settings
            'allow_guest_responses' => (bool) $this->allow_guest_responses,
            'show_results_before_completion' => (bool) $this->show_results_before_completion,
            'is_anonymous' => (bool) $this->is_anonymous,

            // Gamification — only meaningful for community poll types
            'credits_reward' => $this->isCommunityPoll() ? (int) $this->credits_reward : null,

            // Status & scheduling
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'total_responses' => $this->total_responses,
            'question_count' => $this->when($this->relationLoaded('questions'), fn () => $this->questions->count()),

            // Questions with options
            'questions' => $this->when(
                $this->relationLoaded('questions'),
                fn () => PollQuestionResource::collection($this->questions)
            ),

            // Current respondent context
            'has_responded' => $hasResponded,

            // Creator
            'creator' => $this->when($this->relationLoaded('user') && ! $this->is_anonymous, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => StorageHelper::avatarUrl($this->user->avatar, $this->user->name),
                'is_verified' => (bool) ($this->user->is_verified ?? false),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
