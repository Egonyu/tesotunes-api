<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    private string $secretKey;

    private bool $enabled;

    private float $minScore;

    public function __construct()
    {
        $this->secretKey = (string) config('recaptcha.secret_key', '');
        $this->enabled = (bool) config('recaptcha.enabled', true);
        $this->minScore = (float) config('recaptcha.min_score', 0.5);
    }

    /**
     * Verify a reCAPTCHA v3 token.
     *
     * Returns true when:
     *  - reCAPTCHA is disabled (dev/test)
     *  - secret key is missing (fail open, don't block users due to misconfiguration)
     *  - Google reports success and score meets the threshold
     *  - Google is unreachable (fail open to prevent lockout)
     *
     * Returns false when Google explicitly reports failure or a low score.
     */
    public function verify(?string $token, string $action = ''): bool
    {
        if (! $this->enabled) {
            return true;
        }

        if (empty($this->secretKey)) {
            Log::warning('recaptcha.secret_key not configured — skipping verification');

            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $this->secretKey,
                    'response' => $token,
                ]
            );

            $data = $response->json();

            if (! ($data['success'] ?? false)) {
                Log::info('recaptcha.verification_failed', [
                    'action' => $action,
                    'errors' => $data['error-codes'] ?? [],
                ]);

                return false;
            }

            $score = (float) ($data['score'] ?? 0);

            if ($score < $this->minScore) {
                Log::info('recaptcha.score_too_low', [
                    'score' => $score,
                    'min' => $this->minScore,
                    'action' => $action,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Fail open on network errors — don't lock users out because Google is slow
            Log::error('recaptcha.verification_exception', ['error' => $e->getMessage()]);

            return true;
        }
    }
}
