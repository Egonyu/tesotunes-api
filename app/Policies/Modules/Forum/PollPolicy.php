<?php

namespace App\Policies\Modules\Forum;

use App\Models\Modules\Forum\Poll;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PollPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Poll $poll): bool
    {
        return $poll->status === 'active' || $poll->status === 'closed';
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Check daily poll limit
        $config = \App\Models\ModuleSetting::getConfiguration('polls');
        $maxPollsPerDay = $config['max_polls_per_day'] ?? 5;
        $todayCount = Poll::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        return $todayCount < $maxPollsPerDay;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Poll $poll): bool
    {
        // Can only update own polls before they receive votes
        return $user->id === $poll->user_id && $poll->total_votes === 0;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Poll $poll): bool
    {
        // Users can delete own polls, moderators can delete any
        return $user->id === $poll->user_id || 
               $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can vote on the poll.
     */
    public function vote(User $user, Poll $poll): bool
    {
        // Check if poll is active
        if (!$poll->isActive()) {
            return false;
        }

        // Check if user already voted
        if ($poll->userHasVoted($user)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can view results.
     */
    public function viewResults(?User $user, Poll $poll): bool
    {
        // Can view if: show_results_before_vote OR user voted OR poll is closed
        if ($poll->show_results_before_vote || $poll->status === 'closed') {
            return true;
        }

        if ($user && $poll->userHasVoted($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can close the poll.
     */
    public function close(User $user, Poll $poll): bool
    {
        // Creator or moderators can close
        return $user->id === $poll->user_id ||
               $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }
}
