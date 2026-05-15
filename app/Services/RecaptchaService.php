<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    private bool $enabled;

    private bool $enterprise;

    private string $siteKey;

    private string $secretKey;

    private string $projectId;

    private string $apiKey;

    private float $minScore;

    public function __construct()
    {
        $this->enabled = (bool) config('recaptcha.enabled', true);
        $this->enterprise = (bool) config('recaptcha.enterprise', false);
        $this->siteKey = (string) config('recaptcha.site_key', '');
        $this->secretKey = (string) config('recaptcha.secret_key', '');
        $this->projectId = (string) config('recaptcha.project_id', '');
        $this->apiKey = (string) config('recaptcha.api_key', '');
        $this->minScore = (float) config('recaptcha.min_score', 0.5);
    }

    /**
     * Verify a reCAPTCHA token (standard v3 or Enterprise).
     *
     * Returns true when:
     *  - reCAPTCHA is disabled (dev/test)
     *  - required keys are not configured (fail open — don't block users due to misconfiguration)
     *  - Google reports success and score meets the threshold
     *  - Google is unreachable (fail open to prevent lockout)
     *
     * Returns false only when Google explicitly reports failure or a low score.
     */
    public function verify(?string $token, string $action = ''): bool
    {
        if (! $this->enabled) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        return $this->enterprise
            ? $this->verifyEnterprise($token, $action)
            : $this->verifyStandard($token, $action);
    }

    private function verifyStandard(string $token, string $action): bool
    {
        if (empty($this->secretKey)) {
            Log::warning('recaptcha.secret_key not configured — skipping standard verification');

            return true;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                ['secret' => $this->secretKey, 'response' => $token]
            );

            $data = $response->json();

            if (! ($data['success'] ?? false)) {
                Log::info('recaptcha.standard.verification_failed', [
                    'action' => $action,
                    'errors' => $data['error-codes'] ?? [],
                ]);

                return false;
            }

            return $this->scorePass((float) ($data['score'] ?? 0), $action);
        } catch (\Throwable $e) {
            Log::error('recaptcha.standard.exception', ['error' => $e->getMessage()]);

            return true; // fail open on network errors
        }
    }

    private function verifyEnterprise(string $token, string $action): bool
    {
        if (empty($this->projectId) || empty($this->apiKey)) {
            Log::warning('recaptcha.enterprise: RECAPTCHA_PROJECT_ID or RECAPTCHA_API_KEY not configured — skipping verification');

            return true;
        }

        try {
            $url = "https://recaptchaenterprise.googleapis.com/v1/projects/{$this->projectId}/assessments?key={$this->apiKey}";

            $response = Http::timeout(5)->post($url, [
                'event' => array_filter([
                    'token' => $token,
                    'siteKey' => $this->siteKey,
                    'expectedAction' => $action ?: null,
                ]),
            ]);

            $data = $response->json();

            // Enterprise returns tokenProperties.valid instead of success
            if (! ($data['tokenProperties']['valid'] ?? false)) {
                Log::info('recaptcha.enterprise.verification_failed', [
                    'action' => $action,
                    'invalidReason' => $data['tokenProperties']['invalidReason'] ?? 'unknown',
                ]);

                return false;
            }

            return $this->scorePass((float) ($data['riskAnalysis']['score'] ?? 0), $action);
        } catch (\Throwable $e) {
            Log::error('recaptcha.enterprise.exception', ['error' => $e->getMessage()]);

            return true; // fail open on network errors
        }
    }

    private function scorePass(float $score, string $action): bool
    {
        if ($score < $this->minScore) {
            Log::info('recaptcha.score_too_low', [
                'score' => $score,
                'min' => $this->minScore,
                'action' => $action,
            ]);

            return false;
        }

        return true;
    }
}
