<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\Artist;
use App\Models\KYCDocument;
use App\Models\Genre;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Artist Verification Service
 *
 * Handles artist application workflow:
 * - Application submission with KYC documents
 * - Admin review and approval/rejection
 * - Artist profile creation
 * - Integration with existing artist system
 */
class ArtistVerificationService
{
    /**
     * Apply for artist status
     *
     * @param User $user
     * @param array $data Application data including documents
     * @return Artist
     */
    public function applyForArtistStatus(User $user, array $data): Artist
    {
        // Check if user already has an artist profile
        if ($user->artist) {
            throw new \Exception('User already has an artist profile');
        }

        return DB::transaction(function () use ($user, $data) {
            // Create artist entry with pending status
            $artist = Artist::create([
                'user_id' => $user->id,
                'stage_name' => $data['stage_name'],
                'slug' => $this->generateUniqueSlug($data['stage_name']),
                'bio' => $data['bio'] ?? null,
                'status' => 'pending', // Proper status field
                'is_verified' => false,
                'application_submitted_at' => now(), // Track submission
                'primary_genre_id' => $data['genre_id'] ?? null,
            ]);

            // Upload KYC documents
            $this->uploadKYCDocuments($user, $data);

            // Update user to mark as artist applicant
            $user->update([
                'is_artist' => true,
                'phone' => $data['phone'] ?? $user->phone,
            ]);

            // Log activity
            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'artist_application_submitted',
                'auditable_type' => Artist::class,
                'auditable_id' => $artist->id,
                'new_values' => [
                    'stage_name' => $data['stage_name'],
                    'genre_id' => $data['genre_id'] ?? null,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'severity' => 'medium',
            ]);

            // Notify admins
            $this->notifyAdmins($artist);

            Log::info('Artist application submitted', [
                'user_id' => $user->id,
                'artist_id' => $artist->id,
                'stage_name' => $data['stage_name'],
            ]);

            return $artist;
        });
    }

    /**
     * Upload KYC documents for verification
     */
    public function uploadKYCDocuments(User $user, array $data): void
    {
        $documentTypes = [
            'national_id_front',
            'national_id_back',
            'selfie_with_id',
        ];

        foreach ($documentTypes as $type) {
            if (isset($data[$type]) && $data[$type]) {
                $file = $data[$type];

                // Store file in private storage
                $path = $file->store("kyc/{$user->id}", 'private');

                // Create KYC document record
                KYCDocument::create([
                    'user_id' => $user->id,
                    'document_type' => $type,
                    'document_number' => $data['national_id'] ?? null,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'status' => 'pending',
                    'ip_address' => request()->ip(),
                ]);

                Log::info('KYC document uploaded', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'path' => $path,
                ]);
            }
        }
    }

    /**
     * Approve artist application
     *
     * @param Artist $artist
     * @param User $admin
     * @param string|null $notes
     * @return void
     */
    public function approveArtist(Artist $artist, User $admin, ?string $notes = null): void
    {
        DB::transaction(function () use ($artist, $admin, $notes) {
            // Update artist record
            $artist->update([
                'status' => 'active',
                'is_verified' => true,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'can_upload' => true,
                'rejection_reason' => null,
            ]);

            // Update user role and status
            $artist->user->update([
                'role' => 'artist',
                'status' => 'active',
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'artist_application_notes' => $notes,
                // Verify email and phone number when approving artist
                'email_verified_at' => $artist->user->email_verified_at ?? now(),
                'phone_verified_at' => now(),
            ]);

            // Assign artist role in user_roles table
            $this->assignArtistRole($artist->user, $admin);

            // Verify all KYC documents
            KYCDocument::where('user_id', $artist->user_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'active',
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                ]);

            // Log activity
            AuditLog::create([
                'user_id' => $admin->id,
                'event' => 'artist_approved',
                'auditable_type' => Artist::class,
                'auditable_id' => $artist->id,
                'new_values' => [
                    'verified_by' => $admin->id,
                    'notes' => $notes,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'severity' => 'high',
            ]);

            // Send notification to user
            $artist->user->notifications()->create([
                'notification_type' => 'artist_application_approved',
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $artist->user_id,
                'title' => 'Artist Application Approved! 🎉',
                'message' => 'Congratulations! Your artist application has been approved. You can now access your artist dashboard and start uploading music.',
                'action_url' => route('frontend.artist.dashboard'),
                'actor_id' => $admin->id,
                'priority' => 'high',
                'is_read' => false,
            ]);

            Log::info('Artist application approved', [
                'artist_id' => $artist->id,
                'user_id' => $artist->user_id,
                'approved_by' => $admin->id,
            ]);
        });
    }

    /**
     * Reject artist application
     *
     * @param Artist $artist
     * @param User $admin
     * @param string $reason
     * @return void
     */
    public function rejectArtist(Artist $artist, User $admin, string $reason): void
    {
        DB::transaction(function () use ($artist, $admin, $reason) {
            // Update artist record
            $artist->update([
                'status' => 'rejected',
                'is_verified' => false,
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
                'can_upload' => false,
            ]);

            // Update user record
            $artist->user->update([
                'artist_application_notes' => "Rejected: {$reason}",
            ]);

            // Mark KYC documents as rejected
            KYCDocument::where('user_id', $artist->user_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                    'rejection_reason' => $reason,
                ]);

            // Log activity
            AuditLog::create([
                'user_id' => $admin->id,
                'event' => 'artist_rejected',
                'auditable_type' => Artist::class,
                'auditable_id' => $artist->id,
                'new_values' => [
                    'rejected_by' => $admin->id,
                    'reason' => $reason,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'severity' => 'medium',
            ]);

            // Send notification to user
            $artist->user->notifications()->create([
                'notification_type' => 'artist_application_rejected',
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $artist->user_id,
                'title' => 'Artist Application Update',
                'message' => "Your artist application has been reviewed. Reason: {$reason}",
                'action_url' => route('frontend.home'),
                'actor_id' => $admin->id,
                'priority' => 'high',
                'is_read' => false,
            ]);

            Log::info('Artist application rejected', [
                'artist_id' => $artist->id,
                'user_id' => $artist->user_id,
                'rejected_by' => $admin->id,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Request more information from artist
     *
     * @param Artist $artist
     * @param User $admin
     * @param array $missingDocuments
     * @param string $notes
     * @return void
     */
    public function requestMoreInfo(Artist $artist, User $admin, array $missingDocuments, string $notes): void
    {
        DB::transaction(function () use ($artist, $admin, $missingDocuments, $notes) {
            // Update artist record
            $artist->update([
                'status' => 'pending',
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

            // Update user record with admin notes
            $artist->user->update([
                'artist_application_notes' => $notes,
            ]);

            // Mark specific documents as requiring resubmission
            foreach ($missingDocuments as $docType) {
                KYCDocument::where('user_id', $artist->user_id)
                    ->where('document_type', $docType)
                    ->update([
                        'status' => 'rejected',
                        'rejection_reason' => 'Resubmission required',
                    ]);
            }

            // Log activity
            AuditLog::create([
                'user_id' => $admin->id,
                'event' => 'artist_info_requested',
                'auditable_type' => Artist::class,
                'auditable_id' => $artist->id,
                'new_values' => [
                    'requested_by' => $admin->id,
                    'missing_documents' => $missingDocuments,
                    'notes' => $notes,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'severity' => 'low',
            ]);

            // Send notification to user
            $artist->user->notifications()->create([
                'notification_type' => 'artist_application_requires_info',
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $artist->user_id,
                'title' => 'Additional Information Required',
                'message' => "Please provide additional information for your artist application. {$notes}",
                'action_url' => route('frontend.home'), // Update when application edit route exists
                'actor_id' => $admin->id,
                'priority' => 'medium',
                'is_read' => false,
            ]);

            Log::info('More info requested for artist application', [
                'artist_id' => $artist->id,
                'user_id' => $artist->user_id,
                'requested_by' => $admin->id,
            ]);
        });
    }

    /**
     * Get pending artist applications
     */
    public function getPendingApplications(int $perPage = 20)
    {
        return Artist::where('status', 'pending')
            ->with([
                'user.kycDocuments',
                'primaryGenre',
                'songs' => fn($q) => $q->latest()->limit(5),
            ])
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * Get application statistics
     */
    public function getApplicationStatistics(): array
    {
        return [
            'total' => Artist::count(),
            'total_applications' => Artist::count(), // Keep for backward compatibility
            'pending' => Artist::where('is_verified', false)->count(), // Unverified artists
            'verified' => Artist::where('is_verified', true)->count(), // Verified artists
            'rejected' => Artist::where('status', 'rejected')->count(),
            'pending_this_week' => Artist::where('is_verified', false)
                ->where('created_at', '>=', now()->subWeek())
                ->count(),
            'average_approval_time' => $this->getAverageApprovalTime(),
        ];
    }

    /**
     * Generate unique slug for artist
     */
    protected function generateUniqueSlug(string $stageName): string
    {
        $slug = Str::slug($stageName);
        $originalSlug = $slug;
        $counter = 1;

        while (Artist::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Assign artist role to user
     */
    protected function assignArtistRole(User $user, User $admin): void
    {
        // Check if user already has artist role
        if ($user->hasRole('artist')) {
            return;
        }

        // Find artist role
        $artistRole = \App\Models\Role::where('name', 'artist')->first();

        if ($artistRole) {
            $user->roles()->syncWithoutDetaching([
                $artistRole->id => [
                    'assigned_at' => now(),
                    'assigned_by' => $admin->id,
                    'is_active' => true,
                ]
            ]);

            // Clear permission cache
            $user->clearPermissionCache();
        }
    }

    /**
     * Notify admins of new application
     */
    protected function notifyAdmins(Artist $artist): void
    {
        // Find users with verification permissions
        $admins = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'super_admin', 'moderator']);
        })->where('is_active', true)->get();

        foreach ($admins as $admin) {
            $admin->notifications()->create([
                'notification_type' => 'new_artist_application',
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $admin->id,
                'title' => 'New Artist Application',
                'message' => "{$artist->stage_name} has submitted an artist application",
                'action_url' => route('admin.artist-verification.show', $artist->id),
                'actor_id' => $artist->user_id,
                'priority' => 'medium',
                'is_read' => false,
            ]);
        }
    }

    /**
     * Get average approval time in hours
     */
    protected function getAverageApprovalTime(): float
    {
        $approvedArtists = Artist::where('status', 'active')
            ->whereNotNull('verified_at')
            ->get();

        if ($approvedArtists->isEmpty()) {
            return 0;
        }

        $totalHours = $approvedArtists->sum(function ($artist) {
            return $artist->created_at->diffInHours($artist->verified_at);
        });

        return round($totalHours / $approvedArtists->count(), 2);
    }
}
