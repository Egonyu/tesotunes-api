<?php

namespace App\Services\Sacco;

use App\Models\Notification;
use App\Models\Sacco\SaccoMeeting;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoNotification;

class SaccoGovernanceNotificationService
{
    public function notifyMeetingScheduled(SaccoMeeting $meeting): void
    {
        $this->notifyMembers(
            'governance_meeting_scheduled',
            'New SACCO meeting scheduled',
            sprintf('%s has been scheduled for %s.', $meeting->title, optional($meeting->scheduled_at)->format('M j, Y g:i A')),
            $meeting
        );
    }

    public function notifyMeetingUpdated(SaccoMeeting $meeting): void
    {
        $this->notifyMembers(
            'governance_meeting_updated',
            'SACCO meeting updated',
            sprintf('%s has new governance details. Review the updated agenda, time, or venue.', $meeting->title),
            $meeting
        );
    }

    public function notifyResolutionsPublished(SaccoMeeting $meeting): void
    {
        $this->notifyMembers(
            'governance_resolutions_published',
            'Meeting resolutions published',
            sprintf('Resolutions and minutes for %s are now available to members.', $meeting->title),
            $meeting
        );
    }

    public function notifyRsvpConfirmed(SaccoMeeting $meeting, SaccoMember $member): void
    {
        if (! $member->user_id) {
            return;
        }

        Notification::createForUser(
            $member->user,
            'governance_rsvp_confirmed',
            'RSVP confirmed',
            sprintf('You are marked as attending %s.', $meeting->title),
            [
                'meeting_id' => $meeting->id,
                'meeting_title' => $meeting->title,
                'scheduled_at' => optional($meeting->scheduled_at)->toISOString(),
            ],
            null,
            'sacco'
        );
    }

    private function notifyMembers(string $type, string $title, string $message, SaccoMeeting $meeting): void
    {
        $data = [
            'meeting_id' => $meeting->id,
            'meeting_title' => $meeting->title,
            'meeting_type' => $meeting->meeting_type,
            'scheduled_at' => optional($meeting->scheduled_at)->toISOString(),
            'status' => $meeting->status,
        ];

        $members = SaccoMember::query()
            ->where('status', SaccoMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id']);

        if ($members->isEmpty()) {
            return;
        }

        foreach ($members as $member) {
            SaccoNotification::create([
                'member_id' => $member->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'sent_at' => now(),
            ]);
        }

        Notification::createBatchForUsers(
            $members->pluck('user_id')->toArray(),
            $type,
            $title,
            $message,
            $data,
            null,
            'sacco'
        );
    }
}
