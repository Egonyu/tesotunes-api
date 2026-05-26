<?php

namespace App\Services\Auth;

use App\Models\Artist;
use App\Models\AuditLog;
use App\Models\KYCDocument;
use App\Models\Notification as AppNotification;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
    public function __construct(private readonly UserService $userService) {}

    /**
     * Apply for artist status
     *
     * @param  array  $data  Application data including documents
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

            $this->userService->syncArtistApplicationState($user, [
                'stage_name' => $data['stage_name'],
                'full_name' => $data['full_name'] ?? $user->full_name,
                'nin_number' => $data['nin_number'] ?? null,
                'phone' => $data['phone'] ?? $user->phone,
                'bio' => $data['bio'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'social_links' => $data['social_links'] ?? [],
                'mobile_money_number' => $data['mobile_money_number'] ?? null,
                'mobile_money_provider' => $data['mobile_money_provider'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'application_status' => 'pending',
                'genres' => array_values(array_filter([$data['genre_id'] ?? null])),
            ]);

            $user->artistProfile()->update(['artist_id' => $artist->id]);

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
     */
    public function approveArtist(Artist $artist, User $admin, ?string $notes = null): void
    {
        DB::transaction(function () use ($artist, $admin, $notes) {
            // AXIS 2 — artist application: approved, can upload
            $artist->update([
                'status' => \App\Enums\ArtistStatus::Approved->value,
                'is_verified' => true, // AXIS 3 — featured/blue-check toggled on by admin discretion
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'can_upload' => true,
                'rejection_reason' => null,
            ]);

            $this->userService->syncArtistReviewState($artist->user, $artist, [
                'application_status' => 'approved',
                'verified_at' => $artist->verified_at,
                'verified_by' => $admin->id,
                'rejection_reason' => null,
                'is_artist' => true,
                'artist_profile_active' => true,
                'phone_verified_at' => now(),
                'email_verified_at' => $artist->user->email_verified_at ?? now(),
            ]);

            $artist->user->forceFill([
                'role' => 'artist',
                'status' => 'active',
            ])->save();

            // Assign artist role in user_roles table
            $this->assignArtistRole($artist->user, $admin);

            // AXIS 1 — identity (KYC): delegate to KycService so all KYC state
            // changes flow through the single writer. This is a no-op if the user
            // submitted no docs; the admin can still approve them as an artist
            // without identity verification (that gate is on withdrawals/claims).
            if ($artist->user->kycDocuments()->where('status', 'pending')->exists()) {
                app(\App\Services\Kyc\KycService::class)
                    ->markVerified($artist->user, $admin, $notes);
            }

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
            $this->createAppNotification(
                $artist->user,
                'artist_application_approved',
                'artist_verification',
                'Artist Application Approved!',
                'Congratulations! Your artist application has been approved. You can now access your artist dashboard and start uploading music.',
                $this->resolveRoute('frontend.artist.dashboard', '/artist/dashboard'),
                [
                    'artist_id' => $artist->id,
                    'status' => 'approved',
                    'reviewed_by' => $admin->id,
                ],
                $admin->id,
                'high'
            );

            Log::info('Artist application approved', [
                'artist_id' => $artist->id,
                'user_id' => $artist->user_id,
                'approved_by' => $admin->id,
            ]);
        });
    }

    /**
     * Reject artist application
     */
    public function rejectArtist(Artist $artist, User $admin, string $reason): void
    {
        DB::transaction(function () use ($artist, $admin, $reason) {
            // AXIS 2 — artist application: rejected, no upload
            $artist->update([
                'status' => \App\Enums\ArtistStatus::Rejected->value,
                'is_verified' => false, // AXIS 3 — clear featured badge
                'verified_at' => now(),
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
                'can_upload' => false,
            ]);

            $this->userService->syncArtistReviewState($artist->user, $artist, [
                'application_status' => 'rejected',
                'verified_at' => $artist->verified_at,
                'verified_by' => $admin->id,
                'rejection_reason' => $reason,
                'is_artist' => false,
                'artist_profile_active' => true,
            ]);

            // AXIS 1 — identity (KYC): reject through the single writer
            if ($artist->user->kycDocuments()->where('status', 'pending')->exists()) {
                app(\App\Services\Kyc\KycService::class)
                    ->markRejected($artist->user, $admin, $reason);
            }

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
            $this->createAppNotification(
                $artist->user,
                'artist_application_rejected',
                'artist_verification',
                'Artist Application Update',
                "Your artist application has been reviewed. Reason: {$reason}",
                $this->resolveRoute('frontend.home', '/'),
                [
                    'artist_id' => $artist->id,
                    'status' => 'rejected',
                    'reason' => $reason,
                    'reviewed_by' => $admin->id,
                ],
                $admin->id,
                'high'
            );

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
     */
    public function requestMoreInfo(Artist $artist, User $admin, array $missingDocuments, string $notes): void
    {
        DB::transaction(function () use ($artist, $admin, $missingDocuments, $notes) {
            // Update artist record
            $artist->update([
                'status' => \App\Enums\ArtistStatus::Pending->value,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

            $this->userService->syncArtistReviewState($artist->user, $artist, [
                'application_status' => 'pending',
                'verified_at' => $artist->verified_at,
                'verified_by' => $admin->id,
                'rejection_reason' => null,
                'is_artist' => false,
                'artist_profile_active' => true,
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
            $this->createAppNotification(
                $artist->user,
                'artist_application_requires_info',
                'artist_verification',
                'Additional Information Required',
                "Please provide additional information for your artist application. {$notes}",
                $this->resolveRoute('frontend.home', '/'),
                [
                    'artist_id' => $artist->id,
                    'status' => 'pending',
                    'missing_documents' => $missingDocuments,
                    'notes' => $notes,
                    'reviewed_by' => $admin->id,
                ],
                $admin->id,
                'medium'
            );

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
                'songs' => fn ($q) => $q->latest()->limit(5),
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
            $slug = $originalSlug.'-'.$counter;
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
                ],
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
            $this->createAppNotification(
                $admin,
                'new_artist_application',
                'artist_verification',
                'New Artist Application',
                "{$artist->stage_name} has submitted an artist application",
                $this->resolveRoute('admin.artist-verification.show', "/admin/artist-verification/{$artist->id}", $artist->id),
                [
                    'artist_id' => $artist->id,
                    'applicant_user_id' => $artist->user_id,
                    'stage_name' => $artist->stage_name,
                ],
                $artist->user_id,
                'medium'
            );
        }
    }

    protected function createAppNotification(
        ?User $user,
        string $type,
        string $category,
        string $title,
        string $message,
        ?string $actionUrl = null,
        array $data = [],
        ?int $actorId = null,
        string $priority = 'normal'
    ): void {
        if (! $user) {
            return;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'notifiable_type' => Artist::class,
            'notifiable_id' => $data['artist_id'] ?? null,
            'actor_id' => $actorId,
            'priority' => $priority,
            'data' => $data,
        ]);
    }

    protected function resolveRoute(string $name, string $fallback, mixed ...$parameters): string
    {
        return Route::has($name)
            ? route($name, ...$parameters)
            : url($fallback);
    }

    /**
     * Get average approval time in hours
     */
    protected function getAverageApprovalTime(): float
    {
        $approvedArtists = Artist::whereIn('status', Artist::VISIBLE_STATUSES)
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
