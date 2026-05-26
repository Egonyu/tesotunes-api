<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\KYCDocument;
use App\Notifications\AdminArtistApplicationPendingNotification;
use App\Notifications\ArtistApplicationNotification;
use App\Services\NotificationRoutingService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArtistApplicationApiController extends Controller
{
    public function __construct(
        private readonly NotificationRoutingService $notificationRoutingService,
        private readonly UserService $userService,
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get current application status
     */
    public function status()
    {
        $user = Auth::user()->load(['artist']);

        if ($user->artist && in_array($user->artist->status, ['active', 'verified'], true)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'approved',
                    'is_artist' => true,
                    'artist' => [
                        'id' => $user->artist->id,
                        'stage_name' => $user->artist->stage_name,
                        'slug' => $user->artist->slug,
                        'is_verified' => (bool) $user->artist->is_verified,
                        'can_upload' => (bool) $user->artist->can_upload,
                    ],
                    'approved_at' => optional($user->artist->verified_at ?? $user->verified_at)->toIso8601String(),
                ],
            ]);
        }

        if ($user->artist && $user->artist->status === 'rejected') {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'rejected',
                    'is_artist' => false,
                    'artist' => [
                        'id' => $user->artist->id,
                        'stage_name' => $user->artist->stage_name,
                        'slug' => $user->artist->slug,
                    ],
                    'rejection_reason' => $user->artist->rejection_reason ?? $user->rejection_reason,
                    'submitted_at' => optional($user->artist->created_at)->toIso8601String(),
                    'can_reapply' => true,
                ],
            ]);
        }

        if ($user->artist && in_array($user->artist->status, ['pending', 'review'], true)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'pending',
                    'is_artist' => false,
                    'artist' => [
                        'id' => $user->artist->id,
                        'stage_name' => $user->artist->stage_name,
                        'slug' => $user->artist->slug,
                    ],
                    'submitted_at' => optional($user->artist->created_at)->toIso8601String(),
                    'message' => 'Your application is being reviewed.',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'none',
                'is_artist' => false,
                'message' => 'No application submitted',
            ],
        ]);
    }

    /**
     * Submit artist application
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $legacyPayoutMethod = $request->input('payout_method');

        if ($legacyPayoutMethod === 'mtn_momo') {
            $request->merge([
                'payout_method' => 'mobile_money',
                'mobile_money_provider' => $request->input('mobile_money_provider') ?: 'mtn',
            ]);
        } elseif ($legacyPayoutMethod === 'airtel_money') {
            $request->merge([
                'payout_method' => 'mobile_money',
                'mobile_money_provider' => $request->input('mobile_money_provider') ?: 'airtel',
            ]);
        }

        // Frontend compatibility: legacy clients may send payment_option instead of payout_method.
        if (! $request->filled('payout_method') && $request->filled('payment_option')) {
            $request->merge(['payout_method' => $request->input('payment_option')]);
        }

        if (in_array($request->input('payout_method'), ['zengapay', 'mobile_money'], true)) {
            $request->merge([
                'mobile_money_number' => $request->input('mobile_money_number') ?: $request->input('phone'),
            ]);
        }

        if ($request->input('payout_method') === 'zengapay') {
            $request->merge([
                'mobile_money_provider' => 'zengapay',
            ]);
        }

        if ($user->artist && $user->artist->status !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'You already have an artist account',
            ], 400);
        }

        try {
            // Validate request
            $validated = $request->validate([
                'stage_name' => 'required|string|max:255',
                'bio' => 'required|string|min:50|max:2000',
                'primary_genre' => 'required|exists:genres,id',
                'secondary_genres' => 'nullable|array|max:5',
                'secondary_genres.*' => 'exists:genres,id',
                'full_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'payout_method' => 'nullable|in:bank,zengapay,mobile_money',
                'mobile_money_number' => 'nullable|string|max:20',
                'mobile_money_provider' => 'nullable|in:mtn,airtel,zengapay',
                'bank_name' => 'nullable|required_if:payout_method,bank',
                'bank_account' => 'nullable|required_if:payout_method,bank',
                'country' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:255',
                'website_url' => 'nullable|url|max:255',
                'career_start_year' => 'nullable|integer|min:1900|max:2100',
                'nin_number' => 'nullable|string|max:255',
                'social_links' => 'nullable|array',
                'social_links.*' => 'nullable|string|max:255',
                'terms_accepted' => 'required|accepted',
                'artist_agreement_accepted' => 'required|accepted',
                'avatar' => 'nullable|image|max:5120',
                'national_id_front' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
                'national_id_back' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
                'selfie_with_id' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $validated['payout_method'] = $validated['payout_method'] ?? 'zengapay';

        if ($validated['payout_method'] === 'zengapay') {
            $validated['mobile_money_provider'] = 'zengapay';
            $validated['mobile_money_number'] = $validated['mobile_money_number'] ?? $validated['phone'];
        } elseif ($validated['payout_method'] === 'mobile_money') {
            $validated['mobile_money_provider'] = $validated['mobile_money_provider'] ?? 'mtn';
            $validated['mobile_money_number'] = $validated['mobile_money_number'] ?? $validated['phone'];
        }

        DB::beginTransaction();

        try {
            $genreIds = [$validated['primary_genre']];
            if (! empty($validated['secondary_genres'])) {
                $genreIds = array_merge($genreIds, $validated['secondary_genres']);
            }

            $existingApplication = $user->artist;
            if ($existingApplication && in_array($existingApplication->status, ['pending', 'active', 'verified'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an active artist application',
                ], 409);
            }

            $artistAttributes = [
                'user_id' => $user->id,
                'stage_name' => $validated['stage_name'],
                'slug' => $existingApplication?->slug ?: Str::slug($validated['stage_name']).'-'.Str::random(6),
                'bio' => $validated['bio'],
                'primary_genre_id' => $validated['primary_genre'],
                'social_links' => $validated['social_links'] ?? [],
                'is_verified' => false,
                'status' => \App\Enums\ArtistStatus::Pending->value,
                'can_upload' => false,
                'website_url' => $validated['website_url'] ?? null,
                'career_start_year' => $validated['career_start_year'] ?? null,
                'payout_phone_number' => $validated['mobile_money_number'] ?? $validated['phone'],
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => null,
            ];

            $artist = $existingApplication
                ? tap($existingApplication)->update($artistAttributes)
                : Artist::create($artistAttributes);

            $artist = $artist->fresh();

            if ($request->hasFile('avatar')) {
                $artist->addMediaFromRequest('avatar')->toMediaCollection('avatar');
            }

            $verificationDocuments = $this->storeVerificationDocuments($request, $user);

            $this->userService->syncArtistApplicationState($user, [
                'stage_name' => $validated['stage_name'],
                'full_name' => $validated['full_name'],
                'nin_number' => $validated['nin_number'] ?? null,
                'phone' => $validated['phone'],
                'bio' => $validated['bio'],
                'country' => $validated['country'] ?? null,
                'city' => $validated['city'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                'social_links' => $validated['social_links'] ?? [],
                'mobile_money_number' => $validated['mobile_money_number'] ?? $validated['phone'],
                'mobile_money_provider' => $validated['mobile_money_provider'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'bank_account' => $validated['bank_account'] ?? null,
                'application_status' => 'pending',
                'genres' => $genreIds,
                'artist_profile_payout_method' => $validated['payout_method'] === 'bank' ? 'bank_transfer' : 'mobile_money',
                'profile_completed' => true,
                'rejection_reason' => null,
            ]);

            if (Schema::hasTable('artist_profiles')) {
                $user->artistProfile()->update(['artist_id' => $artist->id]);
            }

            DB::commit();

            $artist->loadMissing('user');

            $user->notify(new ArtistApplicationNotification(ArtistApplicationNotification::SUBMITTED));

            foreach ($this->notificationRoutingService->artistApplicationReviewers() as $reviewer) {
                $reviewer->notify(new AdminArtistApplicationPendingNotification($user, $artist));
            }

            return response()->json([
                'success' => true,
                'message' => 'Artist application submitted successfully.',
                'data' => [
                    'application_status' => 'pending',
                    'artist_id' => $artist->id,
                    'stage_name' => $artist->stage_name,
                    'slug' => $artist->slug,
                    'submitted_at' => optional($artist->created_at)->toIso8601String(),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Artist application failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit application.',
                'errors' => [
                    'general' => [$e->getMessage()],
                ],
            ], 500);
        }
    }

    private function storeVerificationDocuments(Request $request, $user): array
    {
        $storedDocuments = [];

        foreach ([KYCDocument::TYPE_NATIONAL_ID_FRONT, KYCDocument::TYPE_NATIONAL_ID_BACK, KYCDocument::TYPE_SELFIE_WITH_ID] as $type) {
            if (! $request->hasFile($type)) {
                continue;
            }

            $file = $request->file($type);
            $path = $this->storeDocumentFile($file, $user->id);

            KYCDocument::create([
                'user_id' => $user->id,
                'document_type' => $type,
                'document_number' => $request->input('nin_number'),
                'document_front' => $path,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => KYCDocument::STATUS_PENDING,
                'ip_address' => $request->ip(),
            ]);

            $storedDocuments[$type] = [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'disk' => 'private',
            ];
        }

        if (! empty($storedDocuments)) {
            app(\App\Services\Kyc\KycService::class)
                ->refreshStatusFromEvidence($user, actorId: $user->id, reason: 'artist_application_documents_submitted');
        }

        return $storedDocuments;
    }

    private function storeDocumentFile($file, int $userId): string
    {
        $directory = "kyc/{$userId}";

        try {
            return $file->store($directory, 'private');
        } catch (\ValueError) {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $path = "{$directory}/{$filename}";
            Storage::disk('private')->put($path, $file->get());

            return $path;
        }
    }
}
