<?php

namespace App\Jobs;

use App\Models\ISRCCode;
use App\Models\Song;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ProcessISRCRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    public function __construct(
        public ISRCCode $isrcCode
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Processing ISRC registration for code: {$this->isrcCode->isrc_code}");

            // Validate ISRC code format
            if (!ISRCCode::validateISRCFormat($this->isrcCode->isrc_code)) {
                throw new \Exception("Invalid ISRC format: {$this->isrcCode->isrc_code}");
            }

            // Check if already registered
            if ($this->isrcCode->isRegistered()) {
                Log::info("ISRC code already registered: {$this->isrcCode->isrc_code}");
                return;
            }

            // Prepare registration data
            $registrationData = $this->prepareRegistrationData();

            // Submit to Uganda Music Rights Organization (UMRO)
            $registrationResult = $this->submitToUMRO($registrationData);

            // Process registration response
            if ($registrationResult['success']) {
                $this->handleSuccessfulRegistration($registrationResult);
                $this->checkInternationalRegistration();
            } else {
                $this->handleFailedRegistration($registrationResult);
            }

        } catch (\Exception $e) {
            Log::error("ISRC registration failed for {$this->isrcCode->isrc_code}: " . $e->getMessage());

            $this->isrcCode->update([
                'status' => 'disputed',
                'notes' => "Registration failed: " . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function prepareRegistrationData(): array
    {
        $song = $this->isrcCode->song;
        $artist = $this->isrcCode->artist;

        return [
            'isrc_code' => $this->isrcCode->isrc_code,
            'work_title' => $this->isrcCode->work_title,
            'artist_name' => $artist->name,
            'duration_seconds' => $this->isrcCode->duration_seconds,
            'recording_date' => $this->isrcCode->recording_date->toDateString(),
            'recording_location' => $this->isrcCode->recording_location,
            'primary_language' => $this->isrcCode->primary_language,
            'genres' => $this->isrcCode->genres,
            'copyright_owner' => $this->isrcCode->copyright_owner,
            'copyright_year' => $this->isrcCode->copyright_year,
            'phonogram_producer' => $this->isrcCode->phonogram_producer,
            'phonogram_year' => $this->isrcCode->phonogram_year,
            'registrant_name' => $this->isrcCode->registrant_name,
            'registrant_contact' => [
                'email' => $artist->user->email,
                'phone' => $artist->user->phone,
                'address' => $artist->address,
            ],
            'alternative_titles' => $this->isrcCode->alternative_titles,
            'featured_artists' => $this->isrcCode->featured_artists,
            'version_info' => $this->isrcCode->version_info,
            'recording_details' => $this->isrcCode->recording_details,
        ];
    }

    private function submitToUMRO(array $data): array
    {
        // Simulate UMRO API call
        // In production, this would be an actual API call to Uganda Music Rights Organization

        try {
            // Simulate API endpoint
            $umroApiUrl = config('services.umro.api_url', 'https://api.umro.ug/isrc/register');
            $apiKey = config('services.umro.api_key', 'demo_key');

            // For now, simulate successful registration
            // TODO: Replace with actual UMRO API integration
            $simulatedResponse = $this->simulateUMROResponse($data);

            Log::info("UMRO registration response", $simulatedResponse);

            return $simulatedResponse;

            // Actual API call would look like:
            /*
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($umroApiUrl, $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'registration_reference' => $response->json('reference_number'),
                    'registration_date' => $response->json('registration_date'),
                    'certificate_url' => $response->json('certificate_url'),
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response->json('error', 'Registration failed'),
                    'error_code' => $response->status(),
                ];
            }
            */

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'API connection failed: ' . $e->getMessage(),
                'error_code' => 'CONNECTION_ERROR',
            ];
        }
    }

    private function simulateUMROResponse(array $data): array
    {
        // Simulate various response scenarios for testing
        $scenarios = ['success', 'duplicate', 'invalid_data'];
        $scenario = $scenarios[array_rand($scenarios)];

        // Force success for demo purposes
        $scenario = 'success';

        return match($scenario) {
            'success' => [
                'success' => true,
                'registration_reference' => 'UMRO-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT),
                'registration_date' => now()->toDateString(),
                'certificate_url' => 'https://certificates.umro.ug/' . $this->isrcCode->isrc_code . '.pdf',
                'validity_period' => 'Perpetual',
                'territorial_scope' => ['Uganda'],
            ],
            'duplicate' => [
                'success' => false,
                'error' => 'ISRC code already registered',
                'error_code' => 'DUPLICATE_ISRC',
                'existing_registration' => 'UMRO-2024-123456',
            ],
            'invalid_data' => [
                'success' => false,
                'error' => 'Invalid registration data provided',
                'error_code' => 'VALIDATION_ERROR',
                'validation_errors' => [
                    'work_title' => 'Work title is required',
                    'recording_date' => 'Recording date must be in the past',
                ],
            ],
        };
    }

    private function handleSuccessfulRegistration(array $result): void
    {
        $this->isrcCode->update([
            'status' => 'registered',
            'registered_at' => now(),
            'registration_reference' => $result['registration_reference'],
            'registration_authority' => 'Uganda Music Rights Organization',
        ]);

        Log::info("ISRC code successfully registered", [
            'isrc_code' => $this->isrcCode->isrc_code,
            'reference' => $result['registration_reference'],
        ]);
    }

    private function handleFailedRegistration(array $result): void
    {
        $status = match($result['error_code']) {
            'DUPLICATE_ISRC' => 'disputed',
            'VALIDATION_ERROR' => 'pending',
            default => 'disputed'
        };

        $this->isrcCode->update([
            'status' => $status,
            'notes' => "Registration failed: {$result['error']}",
        ]);

        Log::warning("ISRC registration failed", [
            'isrc_code' => $this->isrcCode->isrc_code,
            'error' => $result['error'],
            'error_code' => $result['error_code'],
        ]);
    }

    private function checkInternationalRegistration(): void
    {
        // Check if international registration is needed
        $song = $this->isrcCode->song;

        // If song is intended for international distribution
        if ($song->distribution_territories &&
            (in_array('Global', $song->distribution_territories) ||
             count(array_diff($song->distribution_territories, ['Uganda'])) > 0)) {

            // Queue international registration
            ProcessInternationalISRCRegistration::dispatch($this->isrcCode)
                ->delay(now()->addMinutes(5));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessISRCRegistration job failed for ISRC {$this->isrcCode->isrc_code}", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->isrcCode->update([
            'status' => 'disputed',
            'notes' => "Registration job failed: " . $exception->getMessage(),
        ]);
    }
}

class ProcessInternationalISRCRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 2;

    public function __construct(
        public ISRCCode $isrcCode
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Processing international ISRC registration for code: {$this->isrcCode->isrc_code}");

            if (!$this->isrcCode->isRegistered()) {
                throw new \Exception("ISRC must be domestically registered before international registration");
            }

            // Submit to international registries (IFPI, etc.)
            $internationalResult = $this->submitToInternationalRegistries();

            if ($internationalResult['success']) {
                $this->isrcCode->update([
                    'international_registration' => true,
                    'international_registered_at' => now(),
                    'international_territories' => $internationalResult['territories'],
                ]);

                Log::info("International ISRC registration successful", [
                    'isrc_code' => $this->isrcCode->isrc_code,
                    'territories' => $internationalResult['territories'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error("International ISRC registration failed for {$this->isrcCode->isrc_code}: " . $e->getMessage());
            throw $e;
        }
    }

    private function submitToInternationalRegistries(): array
    {
        // Simulate international registration
        return [
            'success' => true,
            'territories' => ['Global'],
            'registration_agencies' => ['IFPI', 'ASCAP', 'BMI'],
            'completion_date' => now()->addDays(7)->toDateString(),
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessInternationalISRCRegistration job failed for ISRC {$this->isrcCode->isrc_code}", [
            'exception' => $exception->getMessage(),
        ]);
    }
}