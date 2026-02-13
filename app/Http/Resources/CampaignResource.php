<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'story' => $this->when($this->story !== null, $this->story),

            // Classification
            'category' => $this->category,
            'urgency' => $this->urgency,
            'status' => $this->status,

            // Beneficiary
            'beneficiary' => [
                'name' => $this->beneficiary_name,
                'type' => $this->beneficiary_type,
                'relationship' => $this->beneficiary_relationship,
            ],

            // Contact
            'contact' => $this->when($request->user()?->role === 'admin' || $request->user()?->id === $this->user_id, [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'role' => $this->contact_role,
            ]),

            // Financials
            'target_amount' => (float) ($this->target_amount ?? 0),
            'total_raised' => (float) ($this->total_raised ?? $this->pledges_sum_amount ?? 0),
            'progress_percent' => $this->when($this->target_amount > 0, fn () => $this->progress_percent),
            'end_date' => $this->end_date?->toDateString(),

            // Stats
            'pledge_count' => $this->pledges_count ?? 0,
            'updates_count' => $this->updates_count ?? 0,
            'view_count' => $this->view_count ?? 0,
            'share_count' => $this->share_count ?? 0,

            // Flags
            'is_verified' => (bool) $this->is_verified,
            'is_featured' => (bool) $this->is_featured,

            // Creator
            'creator' => $this->when($this->relationLoaded('user') && $this->user, fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar ? url('storage/' . $this->user->avatar) : null,
            ]),

            // Approval/Rejection info (admin only)
            'moderation' => $this->when($request->user()?->role === 'admin', fn () => [
                'approved_at' => $this->approved_at?->toIso8601String(),
                'approved_by' => $this->approved_by,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'rejected_by' => $this->rejected_by,
                'rejection_reason' => $this->rejection_reason,
                'revision_requested_at' => $this->revision_requested_at?->toIso8601String(),
                'revision_feedback' => $this->revision_feedback,
            ]),

            // Timestamps
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
