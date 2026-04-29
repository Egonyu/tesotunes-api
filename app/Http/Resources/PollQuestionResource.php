<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollQuestionResource extends JsonResource
{
    public static bool $showResults = false;

    public function toArray(Request $request): array
    {
        PollOptionResource::$showResults = static::$showResults;

        return [
            'id' => $this->id,
            'position' => $this->position,
            'question_text' => $this->question_text,
            'description' => $this->description,
            'question_type' => $this->question_type,
            'is_required' => (bool) $this->is_required,
            'allow_multiple' => (bool) $this->allow_multiple,

            // Scale config — only relevant for rating / likert
            'settings' => $this->when($this->isScaleBased(), fn () => [
                'scale_min' => $this->scaleMin(),
                'scale_max' => $this->scaleMax(),
                'min_label' => $this->settings['min_label'] ?? null,
                'max_label' => $this->settings['max_label'] ?? null,
            ]),

            // Options — only for choice-based questions
            'options' => $this->when(
                $this->isChoiceBased() && $this->relationLoaded('options'),
                fn () => PollOptionResource::collection($this->options)
            ),

            // Answer distribution — for results view
            'answer_distribution' => $this->when(static::$showResults && $this->isScaleBased(), fn () => $this->answers()
                ->selectRaw('rating_value, COUNT(*) as count')
                ->groupBy('rating_value')
                ->orderBy('rating_value')
                ->pluck('count', 'rating_value')
            ),
        ];
    }
}
