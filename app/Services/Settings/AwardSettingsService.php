<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\Award;
use App\Models\AwardCategory;
use App\Models\AwardNomination;
use App\Models\AwardVote;
use App\Models\Genre;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Award Settings Service
 * 
 * Handles all business logic related to music awards system settings.
 * This service centralizes awards configuration management and provides
 * reusable methods for awards-related business rules.
 */
class AwardSettingsService
{
    /**
     * Get all awards-related settings.
     * 
     * @return array
     */
    public function getSettings(): array
    {
        return [
            // General settings
            'awards_enabled' => Setting::get('awards_enabled', true),
            'public_voting_enabled' => Setting::get('awards_public_voting_enabled', true),
            'season_duration' => Setting::get('awards_season_duration', 30),
            'nomination_period' => Setting::get('awards_nomination_period', 14),
            'voting_period' => Setting::get('awards_voting_period', 21),
            
            // Categories
            'auto_generate_categories' => Setting::get('awards_auto_generate_categories', true),
            'max_categories' => Setting::get('awards_max_categories', 10),
            'category_weight' => Setting::get('awards_category_weight', 'equal'),
            
            // Voting
            'max_votes_per_user' => Setting::get('awards_max_votes_per_user', 5),
            'require_registration' => Setting::get('awards_require_registration', true),
            'realtime_votes' => Setting::get('awards_realtime_votes', false),
            'vote_verification_required' => Setting::get('awards_vote_verification_required', false),
            'multiple_votes_per_category' => Setting::get('awards_multiple_votes_per_category', false),
            
            // Prizes
            'prizes_enabled' => Setting::get('awards_prizes_enabled', true),
            'cash_prizes_enabled' => Setting::get('awards_cash_prizes_enabled', true),
            'winner_announcement_delay' => Setting::get('awards_winner_announcement_delay', 7),
            'automatic_winner_selection' => Setting::get('awards_automatic_winner_selection', true),
            
            // Notifications
            'notify_nominees' => Setting::get('awards_notify_nominees', true),
            'notify_voters' => Setting::get('awards_notify_voters', false),
            'notify_winners' => Setting::get('awards_notify_winners', true),
        ];
    }

    /**
     * Update general awards settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateGeneralSettings(array $data): bool
    {
        try {
            $settings = [
                'awards_enabled' => $data['awards_enabled'] ?? true,
                'awards_public_voting_enabled' => $data['public_voting_enabled'] ?? true,
                'awards_season_duration' => (int) ($data['season_duration'] ?? 30),
                'awards_nomination_period' => (int) ($data['nomination_period'] ?? 14),
                'awards_voting_period' => (int) ($data['voting_period'] ?? 21),
            ];

            // Validate season duration
            if ($settings['awards_season_duration'] < 7 || $settings['awards_season_duration'] > 365) {
                Log::warning('Invalid season duration', ['value' => $settings['awards_season_duration']]);
                return false;
            }

            // Validate nomination period
            if ($settings['awards_nomination_period'] < 1 || $settings['awards_nomination_period'] > $settings['awards_season_duration']) {
                Log::warning('Invalid nomination period', ['value' => $settings['awards_nomination_period']]);
                return false;
            }

            // Validate voting period
            if ($settings['awards_voting_period'] < 1 || $settings['awards_voting_period'] > $settings['awards_season_duration']) {
                Log::warning('Invalid voting period', ['value' => $settings['awards_voting_period']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_AWARDS);
            }

            Log::info('Awards general settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update awards general settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update category settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateCategorySettings(array $data): bool
    {
        try {
            $settings = [
                'awards_auto_generate_categories' => $data['auto_generate_categories'] ?? true,
                'awards_max_categories' => (int) ($data['max_categories'] ?? 10),
                'awards_category_weight' => $data['category_weight'] ?? 'equal',
            ];

            // Validate max categories
            if ($settings['awards_max_categories'] < 1 || $settings['awards_max_categories'] > 50) {
                Log::warning('Invalid max categories', ['value' => $settings['awards_max_categories']]);
                return false;
            }

            // Validate category weight
            $validWeights = ['equal', 'popularity', 'custom'];
            if (!in_array($settings['awards_category_weight'], $validWeights)) {
                Log::warning('Invalid category weight', ['value' => $settings['awards_category_weight']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_NUMBER : Setting::TYPE_STRING);
                Setting::set($key, $value, $type, Setting::GROUP_AWARDS);
            }

            // Auto-generate categories if enabled
            if ($settings['awards_auto_generate_categories']) {
                $this->autoGenerateCategories();
            }

            Log::info('Awards category settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update awards category settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update voting settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateVotingSettings(array $data): bool
    {
        try {
            $settings = [
                'awards_max_votes_per_user' => (int) ($data['max_votes_per_user'] ?? 5),
                'awards_require_registration' => $data['require_registration'] ?? true,
                'awards_realtime_votes' => $data['realtime_votes'] ?? false,
                'awards_vote_verification_required' => $data['vote_verification_required'] ?? false,
                'awards_multiple_votes_per_category' => $data['multiple_votes_per_category'] ?? false,
            ];

            // Validate max votes per user
            if ($settings['awards_max_votes_per_user'] < 1 || $settings['awards_max_votes_per_user'] > 100) {
                Log::warning('Invalid max votes per user', ['value' => $settings['awards_max_votes_per_user']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_AWARDS);
            }

            Log::info('Awards voting settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update awards voting settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update prizes settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updatePrizesSettings(array $data): bool
    {
        try {
            $settings = [
                'awards_prizes_enabled' => $data['prizes_enabled'] ?? true,
                'awards_cash_prizes_enabled' => $data['cash_prizes_enabled'] ?? true,
                'awards_winner_announcement_delay' => (int) ($data['winner_announcement_delay'] ?? 7),
                'awards_automatic_winner_selection' => $data['automatic_winner_selection'] ?? true,
            ];

            // Validate winner announcement delay
            if ($settings['awards_winner_announcement_delay'] < 0 || $settings['awards_winner_announcement_delay'] > 90) {
                Log::warning('Invalid winner announcement delay', ['value' => $settings['awards_winner_announcement_delay']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_AWARDS);
            }

            Log::info('Awards prizes settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update awards prizes settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // ==================== Business Logic Methods ====================

    /**
     * Check if awards system is enabled.
     * 
     * @return bool
     */
    public function isAwardsEnabled(): bool
    {
        return Setting::get('awards_enabled', true);
    }

    /**
     * Check if public voting is enabled.
     * 
     * @return bool
     */
    public function isPublicVotingEnabled(): bool
    {
        return Setting::get('awards_public_voting_enabled', true);
    }

    /**
     * Get season duration in days.
     * 
     * @return int
     */
    public function getSeasonDuration(): int
    {
        return Setting::get('awards_season_duration', 30);
    }

    /**
     * Get nomination period in days.
     * 
     * @return int
     */
    public function getNominationPeriod(): int
    {
        return Setting::get('awards_nomination_period', 14);
    }

    /**
     * Get voting period in days.
     * 
     * @return int
     */
    public function getVotingPeriod(): int
    {
        return Setting::get('awards_voting_period', 21);
    }

    /**
     * Get maximum votes per user.
     * 
     * @return int
     */
    public function getMaxVotesPerUser(): int
    {
        return Setting::get('awards_max_votes_per_user', 5);
    }

    /**
     * Check if registration is required to vote.
     * 
     * @return bool
     */
    public function isRegistrationRequired(): bool
    {
        return Setting::get('awards_require_registration', true);
    }

    /**
     * Check if real-time vote display is enabled.
     * 
     * @return bool
     */
    public function isRealtimeVotesEnabled(): bool
    {
        return Setting::get('awards_realtime_votes', false);
    }

    /**
     * Check if user can vote for a nomination.
     * 
     * @param int $userId
     * @param int $nominationId
     * @return array ['can_vote' => bool, 'reason' => string|null]
     */
    public function canUserVote(int $userId, int $nominationId): array
    {
        // Check if awards system is enabled
        if (!$this->isAwardsEnabled()) {
            return ['can_vote' => false, 'reason' => 'Awards system is currently disabled'];
        }

        // Check if public voting is enabled
        if (!$this->isPublicVotingEnabled()) {
            return ['can_vote' => false, 'reason' => 'Public voting is not enabled'];
        }

        // Check if nomination exists
        $nomination = AwardNomination::find($nominationId);
        if (!$nomination) {
            return ['can_vote' => false, 'reason' => 'Nomination not found'];
        }

        // Check if award is in voting period
        $award = $nomination->award;
        if (!$this->isAwardInVotingPeriod($award->id)) {
            return ['can_vote' => false, 'reason' => 'Award is not in voting period'];
        }

        // Check if user has already voted for this nomination
        if ($this->hasUserVotedForNomination($userId, $nominationId)) {
            return ['can_vote' => false, 'reason' => 'You have already voted for this nomination'];
        }

        // Check if user has reached max votes for this category
        if (!Setting::get('awards_multiple_votes_per_category', false)) {
            $categoryVotes = AwardVote::where('user_id', $userId)
                ->whereHas('nomination', function($query) use ($nomination) {
                    $query->where('award_category_id', $nomination->award_category_id);
                })
                ->count();

            if ($categoryVotes >= 1) {
                return ['can_vote' => false, 'reason' => 'You have already voted in this category'];
            }
        }

        // Check if user has reached max total votes
        $totalVotes = $this->getUserVoteCount($userId, $award->id);
        if ($totalVotes >= $this->getMaxVotesPerUser()) {
            return ['can_vote' => false, 'reason' => 'You have reached the maximum number of votes'];
        }

        return ['can_vote' => true, 'reason' => null];
    }

    /**
     * Check if award is in voting period.
     * 
     * @param int $awardId
     * @return bool
     */
    public function isAwardInVotingPeriod(int $awardId): bool
    {
        $award = Award::find($awardId);
        if (!$award) {
            return false;
        }

        $now = now();
        return $award->voting_starts_at && $award->voting_ends_at &&
               $now->greaterThanOrEqualTo($award->voting_starts_at) &&
               $now->lessThanOrEqualTo($award->voting_ends_at);
    }

    /**
     * Check if award is in nomination period.
     * 
     * @param int $awardId
     * @return bool
     */
    public function isAwardInNominationPeriod(int $awardId): bool
    {
        $award = Award::find($awardId);
        if (!$award) {
            return false;
        }

        $now = now();
        return $award->nomination_starts_at && $award->nomination_ends_at &&
               $now->greaterThanOrEqualTo($award->nomination_starts_at) &&
               $now->lessThanOrEqualTo($award->nomination_ends_at);
    }

    /**
     * Check if user has voted for a nomination.
     * 
     * @param int $userId
     * @param int $nominationId
     * @return bool
     */
    public function hasUserVotedForNomination(int $userId, int $nominationId): bool
    {
        return AwardVote::where('user_id', $userId)
            ->where('award_nomination_id', $nominationId)
            ->exists();
    }

    /**
     * Get user's vote count for an award.
     * 
     * @param int $userId
     * @param int $awardId
     * @return int
     */
    public function getUserVoteCount(int $userId, int $awardId): int
    {
        return AwardVote::where('user_id', $userId)
            ->whereHas('nomination.award', function($query) use ($awardId) {
                $query->where('id', $awardId);
            })
            ->count();
    }

    /**
     * Get remaining votes for a user.
     * 
     * @param int $userId
     * @param int $awardId
     * @return int
     */
    public function getRemainingVotes(int $userId, int $awardId): int
    {
        $maxVotes = $this->getMaxVotesPerUser();
        $usedVotes = $this->getUserVoteCount($userId, $awardId);
        
        return max(0, $maxVotes - $usedVotes);
    }

    /**
     * Cast a vote for a nomination.
     * 
     * @param int $userId
     * @param int $nominationId
     * @return array ['success' => bool, 'message' => string]
     */
    public function castVote(int $userId, int $nominationId): array
    {
        $canVote = $this->canUserVote($userId, $nominationId);
        
        if (!$canVote['can_vote']) {
            return ['success' => false, 'message' => $canVote['reason']];
        }

        try {
            DB::beginTransaction();

            $vote = AwardVote::create([
                'user_id' => $userId,
                'award_nomination_id' => $nominationId,
                'voted_at' => now(),
                'ip_address' => request()->ip(),
            ]);

            // Update nomination vote count
            $nomination = AwardNomination::find($nominationId);
            $nomination->increment('votes_count');

            DB::commit();

            Log::info('Vote cast successfully', [
                'user_id' => $userId,
                'nomination_id' => $nominationId,
                'vote_id' => $vote->id
            ]);

            return ['success' => true, 'message' => 'Vote cast successfully'];
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to cast vote', [
                'user_id' => $userId,
                'nomination_id' => $nominationId,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => 'Failed to cast vote'];
        }
    }

    /**
     * Auto-generate award categories based on genres.
     * 
     * @return int Number of categories created
     */
    public function autoGenerateCategories(): int
    {
        $maxCategories = Setting::get('awards_max_categories', 10);
        $genres = Genre::orderBy('name')->limit($maxCategories)->get();
        
        $categoriesCreated = 0;
        
        foreach ($genres as $genre) {
            $categoryName = "Best {$genre->name} Song";
            
            // Check if category already exists
            $exists = AwardCategory::where('name', $categoryName)->exists();
            
            if (!$exists) {
                AwardCategory::create([
                    'name' => $categoryName,
                    'description' => "Best song in the {$genre->name} genre",
                    'genre_id' => $genre->id,
                    'is_active' => true,
                ]);
                
                $categoriesCreated++;
            }
        }

        Log::info('Auto-generated award categories', [
            'categories_created' => $categoriesCreated,
            'admin_id' => auth()->id()
        ]);

        return $categoriesCreated;
    }

    /**
     * Get award statistics.
     * 
     * @param int|null $awardId
     * @return array
     */
    public function getAwardStatistics(?int $awardId = null): array
    {
        $query = AwardNomination::query();
        
        if ($awardId) {
            $query->whereHas('award', function($q) use ($awardId) {
                $q->where('id', $awardId);
            });
        }

        $totalNominations = $query->count();
        $totalVotes = AwardVote::when($awardId, function($q) use ($awardId) {
            $q->whereHas('nomination.award', function($query) use ($awardId) {
                $query->where('id', $awardId);
            });
        })->count();

        $uniqueVoters = AwardVote::when($awardId, function($q) use ($awardId) {
            $q->whereHas('nomination.award', function($query) use ($awardId) {
                $query->where('id', $awardId);
            });
        })->distinct('user_id')->count('user_id');

        return [
            'total_nominations' => $totalNominations,
            'total_votes' => $totalVotes,
            'unique_voters' => $uniqueVoters,
            'average_votes_per_nomination' => $totalNominations > 0 ? round($totalVotes / $totalNominations, 2) : 0,
        ];
    }

    /**
     * Get top nominations by vote count.
     * 
     * @param int $awardId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getTopNominations(int $awardId, int $limit = 10)
    {
        return AwardNomination::whereHas('award', function($query) use ($awardId) {
                $query->where('id', $awardId);
            })
            ->with(['nominee', 'category'])
            ->orderBy('votes_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Determine winners for an award automatically.
     * 
     * @param int $awardId
     * @return array
     */
    public function determineWinners(int $awardId): array
    {
        if (!Setting::get('awards_automatic_winner_selection', true)) {
            return ['success' => false, 'message' => 'Automatic winner selection is disabled'];
        }

        try {
            $categories = AwardCategory::whereHas('nominations', function($query) use ($awardId) {
                $query->whereHas('award', function($q) use ($awardId) {
                    $q->where('id', $awardId);
                });
            })->get();

            $winners = [];

            foreach ($categories as $category) {
                $winner = AwardNomination::where('award_category_id', $category->id)
                    ->whereHas('award', function($query) use ($awardId) {
                        $query->where('id', $awardId);
                    })
                    ->orderBy('votes_count', 'desc')
                    ->first();

                if ($winner) {
                    $winner->update(['is_winner' => true]);
                    $winners[] = $winner;
                }
            }

            Log::info('Award winners determined', [
                'award_id' => $awardId,
                'winners_count' => count($winners),
                'admin_id' => auth()->id()
            ]);

            return [
                'success' => true,
                'message' => 'Winners determined successfully',
                'winners' => $winners
            ];
        } catch (\Exception $e) {
            Log::error('Failed to determine winners', [
                'award_id' => $awardId,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => 'Failed to determine winners'];
        }
    }
}
