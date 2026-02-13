<?php

namespace App\Listeners;

use App\Services\CreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class AwardCreditsForActivity implements ShouldQueue
{
    use InteractsWithQueue;

    protected CreditService $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }

    /**
     * Handle song play completion
     */
    public function handleSongPlayCompleted($event): void
    {
        if (isset($event->user, $event->song, $event->duration)) {
            $this->creditService->awardListeningCredits(
                $event->user,
                $event->song->id,
                $event->duration
            );
        }
    }

    /**
     * Handle social interactions
     */
    public function handleSongLiked($event): void
    {
        if (isset($event->user, $event->song)) {
            $this->creditService->awardSocialCredits(
                $event->user,
                'song_like',
                $event->song->id
            );
        }
    }

    public function handleSongShared($event): void
    {
        if (isset($event->user, $event->song)) {
            $this->creditService->awardSocialCredits(
                $event->user,
                'song_share',
                $event->song->id
            );
        }
    }

    public function handlePlaylistCreated($event): void
    {
        if (isset($event->user, $event->playlist)) {
            $this->creditService->awardSocialCredits(
                $event->user,
                'playlist_create',
                $event->playlist->id
            );
        }
    }

    public function handleUserFollowed($event): void
    {
        if (isset($event->follower, $event->following)) {
            $this->creditService->awardSocialCredits(
                $event->follower,
                'user_follow',
                $event->following->id
            );
        }
    }

    public function handleCommentCreated($event): void
    {
        if (isset($event->user, $event->comment)) {
            $this->creditService->awardSocialCredits(
                $event->user,
                'comment_create',
                $event->comment->id
            );
        }
    }

    /**
     * Handle user login
     */
    public function handleUserLoggedIn($event): void
    {
        if (isset($event->user)) {
            $this->creditService->awardDailyLoginBonus($event->user);
        }
    }

    /**
     * Handle referrals
     */
    public function handleUserRegistered($event): void
    {
        if (isset($event->user) && $event->user->referrer_id) {
            $referrer = \App\Models\User::find($event->user->referrer_id);
            if ($referrer) {
                $this->creditService->awardReferralCredits($referrer, $event->user);
            }
        }
    }
}