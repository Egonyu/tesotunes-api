<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Profile Completion Service
 * 
 * Tracks and calculates user profile completion percentage
 * Encourages progressive profiling without forcing it
 */
class ProfileCompletionService
{
    /**
     * Profile completion steps with their weights
     * Total should add up to 100%
     */
    protected array $steps = [
        'basic_info' => [
            'weight' => 20,
            'fields' => ['name', 'email'],
            'label' => 'Basic Information',
            'description' => 'Your name and email address',
            'importance' => 'high',
        ],
        'phone' => [
            'weight' => 10,
            'fields' => ['phone_number'],
            'label' => 'Phone Number',
            'description' => 'For mobile money payments and security',
            'importance' => 'high',
        ],
        'phone_verified' => [
            'weight' => 15,
            'fields' => ['phone_verified_at'],
            'label' => 'Verify Phone',
            'description' => 'Verify your phone number with SMS code',
            'importance' => 'medium',
            'requires' => 'phone', // Depends on phone step
        ],
        'location' => [
            'weight' => 10,
            'fields' => ['country', 'city'],
            'label' => 'Location',
            'description' => 'Get personalized local content',
            'importance' => 'medium',
        ],
        'avatar' => [
            'weight' => 10,
            'fields' => ['avatar'],
            'label' => 'Profile Picture',
            'description' => 'Show your personality',
            'importance' => 'low',
        ],
        'bio' => [
            'weight' => 10,
            'fields' => ['bio'],
            'label' => 'About You',
            'description' => 'Tell us about yourself',
            'importance' => 'low',
        ],
        'social_links' => [
            'weight' => 10,
            'fields' => ['facebook_url', 'instagram_url'],
            'label' => 'Social Media',
            'description' => 'Connect your social media accounts',
            'importance' => 'low',
            'partial' => true, // At least one field required
        ],
        'payment_method' => [
            'weight' => 15,
            'fields' => ['mobile_money_number', 'mobile_money_provider'],
            'label' => 'Payment Method',
            'description' => 'Required for subscriptions and payouts',
            'importance' => 'high',
        ],
    ];

    /**
     * Calculate profile completion percentage
     */
    public function calculateCompletion(User $user): int
    {
        $totalCompleted = 0;

        foreach ($this->steps as $stepKey => $step) {
            if ($this->isStepCompleted($user, $step)) {
                $totalCompleted += $step['weight'];
            }
        }

        return min(100, max(0, $totalCompleted));
    }

    /**
     * Get list of completed steps
     */
    public function getCompletedSteps(User $user): array
    {
        $completed = [];

        foreach ($this->steps as $stepKey => $step) {
            if ($this->isStepCompleted($user, $step)) {
                $completed[] = $stepKey;
            }
        }

        return $completed;
    }

    /**
     * Get list of pending steps with details
     */
    public function getPendingSteps(User $user): array
    {
        $pending = [];

        foreach ($this->steps as $stepKey => $step) {
            if (!$this->isStepCompleted($user, $step)) {
                // Check if prerequisites are met
                if (isset($step['requires'])) {
                    $prerequisite = $this->steps[$step['requires']] ?? null;
                    if ($prerequisite && !$this->isStepCompleted($user, $prerequisite)) {
                        continue; // Skip if prerequisite not met
                    }
                }

                $pending[] = [
                    'step' => $stepKey,
                    'label' => $step['label'],
                    'description' => $step['description'],
                    'importance' => $step['importance'],
                    'weight' => $step['weight'],
                    'route' => $this->getStepRoute($stepKey),
                ];
            }
        }

        // Sort by importance then weight
        usort($pending, function ($a, $b) {
            $importanceOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            $aImportance = $importanceOrder[$a['importance']] ?? 3;
            $bImportance = $importanceOrder[$b['importance']] ?? 3;

            if ($aImportance === $bImportance) {
                return $b['weight'] - $a['weight'];
            }

            return $aImportance - $bImportance;
        });

        return $pending;
    }

    /**
     * Check if a specific step is completed
     */
    protected function isStepCompleted(User $user, array $step): bool
    {
        $fields = $step['fields'];
        $isPartial = $step['partial'] ?? false;

        if ($isPartial) {
            // At least one field must be filled
            foreach ($fields as $field) {
                if (!empty($user->$field)) {
                    return true;
                }
            }
            return false;
        } else {
            // All fields must be filled
            foreach ($fields as $field) {
                if (empty($user->$field)) {
                    return false;
                }
            }
            return true;
        }
    }

    /**
     * Get route for completing a step
     */
    protected function getStepRoute(string $stepKey): ?string
    {
        $routes = [
            'basic_info' => 'frontend.profile.edit',
            'phone' => 'frontend.profile.edit#phone',
            'phone_verified' => 'frontend.auth.phone-verification',
            'location' => 'frontend.profile.edit#location',
            'avatar' => 'frontend.profile.edit#avatar',
            'bio' => 'frontend.profile.edit#bio',
            'social_links' => 'frontend.profile.edit#social',
            'payment_method' => 'frontend.profile.edit#payment',
        ];

        return $routes[$stepKey] ?? null;
    }

    /**
     * Update user's profile completion tracking
     */
    public function updateCompletion(User $user): void
    {
        $percentage = $this->calculateCompletion($user);
        $steps = $this->getCompletedSteps($user);

        $user->update([
            'profile_completion_percentage' => $percentage,
            'profile_steps_completed' => json_encode($steps),
        ]);

        // Clear cache
        Cache::forget("user:{$user->id}:profile_completion");
    }

    /**
     * Get profile completion data with caching
     */
    public function getCompletionData(User $user): array
    {
        $cacheKey = "user:{$user->id}:profile_completion";

        return Cache::remember($cacheKey, 3600, function () use ($user) {
            return [
                'percentage' => $user->profile_completion_percentage ?? $this->calculateCompletion($user),
                'completed_steps' => $this->getCompletedSteps($user),
                'pending_steps' => $this->getPendingSteps($user),
                'next_step' => $this->getNextMostImportantStep($user),
            ];
        });
    }

    /**
     * Get the next most important step to complete
     */
    public function getNextMostImportantStep(User $user): ?array
    {
        $pending = $this->getPendingSteps($user);
        return $pending[0] ?? null;
    }

    /**
     * Check if profile meets minimum completion for specific action
     */
    public function meetsMinimumFor(User $user, string $action): bool
    {
        $minimums = [
            'artist_application' => 50,
            'sacco_membership' => 60,
            'store_creation' => 60,
            'event_creation' => 50,
            'podcast_creation' => 50,
        ];

        $requiredPercentage = $minimums[$action] ?? 0;
        return $user->profile_completion_percentage >= $requiredPercentage;
    }

    /**
     * Get completion requirements for an action
     */
    public function getRequirementsFor(string $action): array
    {
        $requirements = [
            'artist_application' => [
                'minimum_percentage' => 50,
                'required_steps' => ['basic_info', 'phone', 'payment_method'],
                'recommended_steps' => ['phone_verified', 'avatar', 'bio'],
            ],
            'sacco_membership' => [
                'minimum_percentage' => 60,
                'required_steps' => ['basic_info', 'phone', 'phone_verified', 'payment_method'],
                'recommended_steps' => ['location'],
            ],
            'store_creation' => [
                'minimum_percentage' => 60,
                'required_steps' => ['basic_info', 'phone', 'phone_verified', 'payment_method'],
                'recommended_steps' => ['location', 'avatar'],
            ],
        ];

        return $requirements[$action] ?? [
            'minimum_percentage' => 0,
            'required_steps' => [],
            'recommended_steps' => [],
        ];
    }
}
