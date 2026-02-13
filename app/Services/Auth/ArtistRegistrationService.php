<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\Artist;
use App\Models\KYCDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

/**
 * Artist Registration Service
 * 
 * Manages multi-step artist registration process
 * Handles session management, file uploads, and account creation
 */
class ArtistRegistrationService
{
    /**
     * Initialize registration session
     */
    public function initializeSession(): void
    {
        session([
            'artist_registration' => [
                'current_step' => 1,
                'completed_steps' => [],
                'data' => [
                    'step1' => [],
                    'step2' => [],
                    'step3' => [],
                ],
                'started_at' => now()->toDateTimeString(),
            ]
        ]);
    }

    /**
     * Get current session data
     */
    public function getSessionData(): array
    {
        return session('artist_registration', [
            'current_step' => 1,
            'completed_steps' => [],
            'data' => [],
            'started_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Save step data to session
     */
    public function saveStepData(int $step, array $data): void
    {
        $sessionData = $this->getSessionData();
        
        $sessionData['data']['step' . $step] = array_merge(
            $sessionData['data']['step' . $step] ?? [],
            $data
        );
        
        $sessionData['current_step'] = $step + 1;
        
        if (!in_array($step, $sessionData['completed_steps'])) {
            $sessionData['completed_steps'][] = $step;
        }
        
        session(['artist_registration' => $sessionData]);
    }

    /**
     * Update specific field in step data
     */
    public function updateStepData(int $step, string $key, $value): void
    {
        $sessionData = $this->getSessionData();
        $sessionData['data']['step' . $step][$key] = $value;
        session(['artist_registration' => $sessionData]);
    }

    /**
     * Check if step is completed
     */
    public function isStepCompleted(int $step): bool
    {
        $sessionData = $this->getSessionData();
        return in_array($step, $sessionData['completed_steps'] ?? []);
    }

    /**
     * Get current step
     */
    public function getCurrentStep(): int
    {
        $sessionData = $this->getSessionData();
        return $sessionData['current_step'] ?? 1;
    }

    /**
     * Get progress percentage
     */
    public function getProgress(): array
    {
        $sessionData = $this->getSessionData();
        $totalSteps = 3;
        $completedSteps = count($sessionData['completed_steps'] ?? []);
        
        return [
            'total_steps' => $totalSteps,
            'completed_steps' => $completedSteps,
            'current_step' => $sessionData['current_step'] ?? 1,
            'percentage' => round(($completedSteps / $totalSteps) * 100),
        ];
    }

    /**
     * Upload file and return metadata
     */
    public function uploadFile(UploadedFile $file, string $directory): array
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, 'public');

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * Create artist account from all step data
     */
    public function createArtistAccount(array $step3Data): User
    {
        $sessionData = $this->getSessionData();
        $step1Data = $sessionData['data']['step1'] ?? [];
        $step2Data = $sessionData['data']['step2'] ?? [];

        return DB::transaction(function () use ($step1Data, $step2Data, $step3Data) {
            // Generate username from stage name
            $baseUsername = Str::slug($step1Data['stage_name'], '_');
            $username = $baseUsername;
            $counter = 1;
            while (User::where('username', $username)->exists()) {
                $username = $baseUsername . '_' . $counter;
                $counter++;
            }

            // Create user account
            $user = User::create([
                // From Step 1
                'stage_name' => $step1Data['stage_name'],
                'display_name' => $step1Data['stage_name'],
                'username' => $username,
                'bio' => $step1Data['bio'] ?? null,
                'avatar' => $step1Data['avatar_path'] ?? null,
                
                // From Step 2
                'full_name' => $step2Data['full_name'],
                'nin_number' => $step2Data['nin_number'],
                'phone' => $step2Data['phone_number'], // Correct field name
                
                // From Step 3
                'email' => $step3Data['email'],
                'password' => Hash::make($step3Data['password']),
                'mobile_money_provider' => $step3Data['mobile_money_provider'],
                'mobile_money_number' => $step3Data['mobile_money_number'],
                
                // System fields
                'is_artist' => true,
                'status' => 'active', // User is active
                'application_status' => 'pending', // Artist application pending
            ]);

            // Create artist profile (pending verification)
            $slug = Str::slug($step1Data['stage_name']) . '-' . Str::random(6);
            
            $artist = Artist::create([
                'user_id' => $user->id,
                'stage_name' => $step1Data['stage_name'],
                'slug' => $slug,
                'bio' => $step1Data['bio'] ?? null,
                'avatar' => $step1Data['avatar_path'] ?? null,
                'primary_genre_id' => $step1Data['genre_id'],
                'status' => 'pending', // Artist status pending verification
                'is_verified' => false,
            ]);

            // Assign Artist role to user
            $artistRole = \App\Models\Role::whereRaw('LOWER(name) = ?', ['artist'])->first();
            if ($artistRole) {
                $user->roles()->attach($artistRole->id, [
                    'assigned_at' => now(),
                ]);
            }

            // Store KYC documents
            $this->storeKycDocuments($user, $step2Data);

            // Log application
            logger()->info('New artist registration', [
                'user_id' => $user->id,
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
            ]);

            return $user;
        });
    }

    /**
     * Store KYC documents
     */
    protected function storeKycDocuments(User $user, array $step2Data): void
    {
        $documents = [
            'national_id_front' => [
                'type' => 'national_id_front',
                'metadata' => $step2Data['national_id_front_metadata'] ?? null,
            ],
            'national_id_back' => [
                'type' => 'national_id_back',
                'metadata' => $step2Data['national_id_back_metadata'] ?? null,
            ],
            'selfie_with_id' => [
                'type' => 'selfie_with_id',
                'metadata' => $step2Data['selfie_with_id_metadata'] ?? null,
            ],
        ];

        foreach ($documents as $document) {
            if ($document['metadata'] && isset($document['metadata']['path'])) {
                $metadata = $document['metadata'];
                KYCDocument::create([
                    'user_id' => $user->id,
                    'document_type' => $document['type'],
                    'document_front' => $metadata['path'],
                    'status' => 'pending',
                ]);
            }
        }
    }

    /**
     * Clear registration session
     */
    public function clearSession(): void
    {
        session()->forget('artist_registration');
    }

    /**
     * Get all registration data (for review)
     */
    public function getAllData(): array
    {
        $sessionData = $this->getSessionData();
        
        return array_merge(
            $sessionData['data']['step1'] ?? [],
            $sessionData['data']['step2'] ?? [],
            $sessionData['data']['step3'] ?? []
        );
    }

    /**
     * Validate session is active
     */
    public function hasActiveSession(): bool
    {
        $sessionData = $this->getSessionData();
        
        if (empty($sessionData['started_at'])) {
            return false;
        }

        // Session expires after 2 hours
        $startedAt = \Carbon\Carbon::parse($sessionData['started_at']);
        return $startedAt->diffInHours(now()) < 2;
    }

    /**
     * Resume registration from saved step
     */
    public function resumeFromStep(int $step): void
    {
        $sessionData = $this->getSessionData();
        $sessionData['current_step'] = $step;
        session(['artist_registration' => $sessionData]);
    }
}
