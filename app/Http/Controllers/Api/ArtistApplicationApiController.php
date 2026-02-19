<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArtistApplicationApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get current application status
     */
    public function status()
    {
        $user = Auth::user();

        // Check if user already has artist profile
        if ($user->artist) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'approved',
                    'is_artist' => true,
                    'artist' => $user->artist,
                ],
            ]);
        }

        // No pending application (instant approval flow)
        return response()->json([
            'success' => true,
            'data' => [
                'status' => null,
                'is_artist' => false,
            ],
        ]);
    }

    /**
     * Submit artist application
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Check if already an artist
        if ($user->artist) {
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
                'payout_method' => 'required|in:mtn_momo,airtel_money,bank',
                'mobile_money_number' => 'required_if:payout_method,mtn_momo,airtel_money',
                'mobile_money_provider' => 'required_if:payout_method,mtn_momo,airtel_money|in:mtn,airtel',
                'bank_name' => 'required_if:payout_method,bank',
                'bank_account' => 'required_if:payout_method,bank',
                'country' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:255',
                'social_links' => 'nullable|array',
                'terms_accepted' => 'required|accepted',
                'artist_agreement_accepted' => 'required|accepted',
                // REMOVED avatar validation - handle it separately
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Create artist record directly (instant approval for better UX)
            $genreIds = [$validated['primary_genre']];
            if (! empty($validated['secondary_genres'])) {
                $genreIds = array_merge($genreIds, $validated['secondary_genres']);
            }

            $artist = Artist::create([
                'user_id' => $user->id,
                'stage_name' => $validated['stage_name'],
                'slug' => Str::slug($validated['stage_name']).'-'.Str::random(6),
                'bio' => $validated['bio'],
                'primary_genre_id' => $validated['primary_genre'],
                'social_links' => $validated['social_links'] ?? [],
                'is_verified' => false,
                'status' => 'active',
            ]);

            // Handle avatar upload
            if ($request->hasFile('avatar')) {
                $artist->addMediaFromRequest('avatar')->toMediaCollection('avatar');
            }

            // Update user phone and artist payout info
            $user->update([
                'phone' => $validated['phone'],
                'mobile_money_number' => $validated['mobile_money_number'] ?? null,
                'mobile_money_provider' => $validated['mobile_money_provider'] ?? null,
            ]);

            // Store payout phone on artist record
            $artist->update([
                'payout_phone_number' => $validated['mobile_money_number'] ?? $validated['phone'],
            ]);

            // Assign artist role
            $artistRole = DB::table('roles')->where('name', 'Artist')->first();
            if ($artistRole) {
                DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $artistRole->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Artist application submitted successfully! Welcome to TesoTunes!',
                'data' => [
                    'artist' => $artist->fresh(),
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
                'message' => 'Failed to submit application: '.$e->getMessage(),
                'errors' => [
                    'general' => [$e->getMessage()],
                ],
            ], 500);
        }
    }
}
