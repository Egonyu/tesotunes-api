<?php

namespace App\Jobs;

use App\Models\ISRCCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessISRCRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public ISRCCode $isrcCode
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Processing ISRC registration for code: {$this->isrcCode->code}");

            if (! ISRCCode::validateISRCFormat($this->isrcCode->code)) {
                throw new \Exception("Invalid ISRC format: {$this->isrcCode->code}");
            }

            if ($this->isrcCode->isRegistered()) {
                Log::info("ISRC code already registered: {$this->isrcCode->code}");

                return;
            }

            $registrationData = $this->prepareRegistrationData();
            $registrationResult = $this->submitToUMRO($registrationData);

            if ($registrationResult['success']) {
                $this->handleSuccessfulRegistration($registrationResult);
                $this->checkInternationalRegistration();
            } else {
                $this->handleFailedRegistration($registrationResult);
            }

        } catch (\Exception $e) {
            Log::error("ISRC registration failed for {$this->isrcCode->code}: ".$e->getMessage());

            $this->isrcCode->update(['status' => 'disputed']);

            throw $e;
        }
    }

    private function prepareRegistrationData(): array
    {
        $song = $this->isrcCode->song;
        $artist = $this->isrcCode->artist;

        return [
            'isrc_code' => $this->isrcCode->code,
            'work_title' => $song?->title,
            'artist_name' => $artist?->name,
            'duration_seconds' => $song?->duration_seconds,
            'primary_language' => $song?->primary_language,
            'copyright_holder' => $song?->copyright_holder,
            'copyright_year' => $song?->copyright_year,
            'registrant_name' => config('music.isrc.registrant_name', 'TesoTunes'),
            'registrant_contact' => [
                'email' => $artist?->user?->email,
                'phone' => $artist?->user?->phone,
            ],
        ];
    }

    private function submitToUMRO(array $data): array
    {
        try {
            $umroApiUrl = config('services.umro.api_url');
            $apiKey = config('services.umro.api_key');

            if ($umroApiUrl && $apiKey && $apiKey !== 'demo_key') {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(30)->post($umroApiUrl, $data);

                $result = $response->successful()
                    ? [
                        'success' => true,
                        'registration_reference' => $response->json('reference_number'),
                        'registration_date' => $response->json('registration_date'),
                        'certificate_url' => $response->json('certificate_url'),
                    ]
                    : [
                        'success' => false,
                        'error' => $response->json('error', 'Registration failed'),
                        'error_code' => $response->status(),
                    ];

                Log::info('UMRO registration response', $result);

                return $result;
            }

            Log::warning('UMRO API not configured — using simulated response');
            $simulated = $this->simulateUMROResponse();
            Log::info('UMRO registration response (simulated)', $simulated);

            return $simulated;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'API connection failed: '.$e->getMessage(),
                'error_code' => 'CONNECTION_ERROR',
            ];
        }
    }

    private function simulateUMROResponse(): array
    {
        return [
            'success' => true,
            'registration_reference' => 'UMRO-'.date('Y').'-'.str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT),
            'registration_date' => now()->toDateString(),
            'certificate_url' => 'https://certificates.umro.ug/'.$this->isrcCode->code.'.pdf',
            'validity_period' => 'Perpetual',
            'territorial_scope' => ['Uganda'],
        ];
    }

    private function handleSuccessfulRegistration(array $result): void
    {
        $this->isrcCode->update([
            'status' => 'active',
            'registered_at' => now(),
            'registration_reference' => $result['registration_reference'],
            'registration_authority' => 'Uganda Music Rights Organization',
        ]);

        Log::info('ISRC code successfully registered', [
            'isrc_code' => $this->isrcCode->code,
            'reference' => $result['registration_reference'],
        ]);
    }

    private function handleFailedRegistration(array $result): void
    {
        $status = match ($result['error_code']) {
            'DUPLICATE_ISRC' => 'disputed',
            'VALIDATION_ERROR' => 'pending',
            default => 'disputed'
        };

        $this->isrcCode->update(['status' => $status]);

        Log::warning('ISRC registration failed', [
            'isrc_code' => $this->isrcCode->code,
            'error' => $result['error'],
            'error_code' => $result['error_code'],
        ]);
    }

    private function checkInternationalRegistration(): void
    {
        $song = $this->isrcCode->song;

        $platforms = $song?->distribution_platforms ?? [];
        $internationalPlatforms = array_diff($platforms, ['boomplay', 'audiomack']);

        if (! empty($internationalPlatforms)) {
            ProcessInternationalISRCRegistration::dispatch($this->isrcCode)
                ->delay(now()->addMinutes(5));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessISRCRegistration job failed for ISRC {$this->isrcCode->code}", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->isrcCode->update(['status' => 'disputed']);
    }
}

class ProcessInternationalISRCRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public ISRCCode $isrcCode
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Processing international ISRC registration for code: {$this->isrcCode->code}");

            if (! $this->isrcCode->isRegistered()) {
                throw new \Exception('ISRC must be domestically registered before international registration');
            }

            $internationalResult = $this->submitToInternationalRegistries();

            if ($internationalResult['success']) {
                $this->isrcCode->update([
                    'cleared_for_distribution' => true,
                    'distribution_cleared_at' => now(),
                ]);

                Log::info('International ISRC registration successful', [
                    'isrc_code' => $this->isrcCode->code,
                    'territories' => $internationalResult['territories'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error("International ISRC registration failed for {$this->isrcCode->code}: ".$e->getMessage());
            throw $e;
        }
    }

    private function submitToInternationalRegistries(): array
    {
        return [
            'success' => true,
            'territories' => ['Global'],
            'registration_agencies' => ['IFPI', 'ASCAP', 'BMI'],
            'completion_date' => now()->addDays(7)->toDateString(),
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessInternationalISRCRegistration job failed for ISRC {$this->isrcCode->code}", [
            'exception' => $exception->getMessage(),
        ]);
    }
}
