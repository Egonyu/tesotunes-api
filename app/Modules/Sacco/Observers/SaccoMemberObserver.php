<?php

namespace App\Modules\Sacco\Observers;

use App\Models\Sacco\SaccoMember;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class SaccoMemberObserver
{
    public function created(SaccoMember $member): void
    {
        try {
            $user = $member->user;
            if (! $user) {
                return;
            }

            ActivityService::log(
                actor: $user,
                action: 'joined_sacco',
                subject: $member,
                metadata: [
                    'member_number' => $member->member_number,
                    'member_type' => $member->member_type,
                ]
            );

            FeedItemService::create([
                'type' => 'sacco_joined',
                'module' => 'sacco',
                'title' => ($user->name ?? 'Someone').' joined the SACCO',
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'actor_name' => $user->name,
                'actor_avatar_url' => $user->avatar_url ?? null,
                'subject_type' => SaccoMember::class,
                'subject_id' => $member->id,
                'visibility' => 'members',
            ]);
        } catch (\Exception $e) {
            Log::error('SaccoMemberObserver: Failed to create feed item', ['member_id' => $member->id, 'error' => $e->getMessage()]);
        }
    }
}
