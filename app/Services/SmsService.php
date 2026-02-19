<?php

namespace App\Services;

use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $provider;

    protected array $config;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', 'mock');
        $this->config = config('services.sms', []);
    }

    /**
     * Send verification code via SMS
     */
    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $message = "Your verification code is: {$code}. Valid for 10 minutes. Do not share this code.";

        return $this->sendSms($phoneNumber, $message);
    }

    /**
     * Send SMS based on configured provider
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        // Format phone number for Uganda
        $formattedNumber = $this->formatUgandanPhoneNumber($phoneNumber);

        try {
            return match ($this->provider) {
                'africastalking' => $this->sendViaAfricasTalking($formattedNumber, $message),
                'twilio' => $this->sendViaTwilio($formattedNumber, $message),
                'mock' => $this->sendViaMockService($formattedNumber, $message),
                default => $this->sendViaMockService($formattedNumber, $message)
            };

        } catch (\Exception $e) {
            Log::error('SMS sending failed', [
                'provider' => $this->provider,
                'phone' => $formattedNumber,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send SMS via Africa's Talking (Popular in Uganda)
     */
    protected function sendViaAfricasTalking(string $phoneNumber, string $message): bool
    {
        $apiKey = $this->config['africastalking']['api_key'] ?? '';
        $username = $this->config['africastalking']['username'] ?? '';
        $senderId = $this->config['africastalking']['sender_id'] ?? 'MUSICAPP';

        if (empty($apiKey) || empty($username)) {
            Log::warning('Africa\'s Talking credentials not configured, using mock service');

            return $this->sendViaMockService($phoneNumber, $message);
        }

        $response = HttpClientFactory::make('africastalking')->withHeaders([
            'ApiKey' => $apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post('https://api.africastalking.com/version1/messaging', [
            'username' => $username,
            'to' => $phoneNumber,
            'message' => $message,
            'from' => $senderId,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $success = isset($data['SMSMessageData']['Recipients'][0]['status']) &&
                      $data['SMSMessageData']['Recipients'][0]['status'] === 'Success';

            if ($success) {
                Log::info('SMS sent successfully via Africa\'s Talking', [
                    'phone' => $phoneNumber,
                    'response' => $data,
                ]);

                return true;
            }
        }

        Log::error('Africa\'s Talking SMS failed', [
            'phone' => $phoneNumber,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    /**
     * Send SMS via Twilio (International fallback)
     */
    protected function sendViaTwilio(string $phoneNumber, string $message): bool
    {
        $accountSid = $this->config['twilio']['account_sid'] ?? '';
        $authToken = $this->config['twilio']['auth_token'] ?? '';
        $fromNumber = $this->config['twilio']['from_number'] ?? '';

        if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
            Log::warning('Twilio credentials not configured, using mock service');

            return $this->sendViaMockService($phoneNumber, $message);
        }

        $response = HttpClientFactory::make('twilio')->withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'From' => $fromNumber,
                'To' => $phoneNumber,
                'Body' => $message,
            ]);

        if ($response->successful()) {
            Log::info('SMS sent successfully via Twilio', [
                'phone' => $phoneNumber,
                'sid' => $response->json('sid'),
            ]);

            return true;
        }

        Log::error('Twilio SMS failed', [
            'phone' => $phoneNumber,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    /**
     * Mock SMS service for development/testing
     */
    protected function sendViaMockService(string $phoneNumber, string $message): bool
    {
        // In development, just log the SMS instead of sending
        Log::info('Mock SMS sent', [
            'to' => $phoneNumber,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ]);

        // Simulate network delay
        if (app()->environment('local')) {
            sleep(1);
        }

        return true;
    }

    /**
     * Format phone number for Ugandan standards
     */
    protected function formatUgandanPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Handle different Ugandan phone number formats
        if (str_starts_with($cleaned, '256')) {
            // Already has country code
            return '+'.$cleaned;
        }

        if (str_starts_with($cleaned, '0')) {
            // Local format (0xxx xxx xxx) - replace leading 0 with +256
            return '+256'.substr($cleaned, 1);
        }

        if (strlen($cleaned) === 9) {
            // 9 digits without leading 0 - add +256
            return '+256'.$cleaned;
        }

        // Return as-is if we can't determine format
        return '+256'.$cleaned;
    }

    /**
     * Validate Ugandan phone number format
     */
    public function isValidUgandanPhoneNumber(string $phoneNumber): bool
    {
        $formatted = $this->formatUgandanPhoneNumber($phoneNumber);

        // Ugandan mobile numbers: +256 7XX XXX XXX or +256 3XX XXX XXX
        return preg_match('/^\+256[37][0-9]{8}$/', $formatted);
    }

    /**
     * Get supported mobile network operators in Uganda
     */
    public function getUgandanOperators(): array
    {
        return [
            'mtn' => [
                'name' => 'MTN Uganda',
                'prefixes' => ['77', '78', '39'],
                'supports_mobile_money' => true,
            ],
            'airtel' => [
                'name' => 'Airtel Uganda',
                'prefixes' => ['75', '70'],
                'supports_mobile_money' => true,
            ],
            'utl' => [
                'name' => 'Uganda Telecom',
                'prefixes' => ['71'],
                'supports_mobile_money' => false,
            ],
            'africell' => [
                'name' => 'Africell',
                'prefixes' => ['79'],
                'supports_mobile_money' => false,
            ],
        ];
    }

    /**
     * Detect mobile operator from phone number
     */
    public function detectOperator(string $phoneNumber): ?string
    {
        $formatted = $this->formatUgandanPhoneNumber($phoneNumber);
        $operators = $this->getUgandanOperators();

        // Extract the network prefix (first 2 digits after +256)
        if (preg_match('/^\+256([0-9]{2})/', $formatted, $matches)) {
            $prefix = $matches[1];

            foreach ($operators as $code => $operator) {
                if (in_array($prefix, $operator['prefixes'])) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Check if number supports mobile money
     */
    public function supportsMobileMoney(string $phoneNumber): bool
    {
        $operator = $this->detectOperator($phoneNumber);

        if (! $operator) {
            return false;
        }

        $operators = $this->getUgandanOperators();

        return $operators[$operator]['supports_mobile_money'] ?? false;
    }

    /**
     * Send bulk SMS to multiple recipients
     */
    public function sendBulkSms(array $phoneNumbers, string $message): array
    {
        $results = [];

        foreach ($phoneNumbers as $phoneNumber) {
            $results[$phoneNumber] = $this->sendSms($phoneNumber, $message);
        }

        return $results;
    }

    /**
     * Send SMS notification for specific events
     */
    public function sendNotification(string $phoneNumber, string $type, array $data = []): bool
    {
        $message = $this->getNotificationMessage($type, $data);

        if (! $message) {
            return false;
        }

        return $this->sendSms($phoneNumber, $message);
    }

    /**
     * Get predefined notification messages
     */
    protected function getNotificationMessage(string $type, array $data): ?string
    {
        return match ($type) {
            'artist_approved' => 'Congratulations! Your artist account has been approved. You can now start uploading music and earning money.',

            'artist_rejected' => 'Your artist application has been rejected. Reason: '.($data['reason'] ?? 'Please contact support for more information.'),

            'payout_approved' => 'Your payout request of UGX '.number_format($data['amount'] ?? 0).' has been approved and will be processed within 24 hours.',

            'payout_completed' => 'Your payout of UGX '.number_format($data['amount'] ?? 0).' has been sent to your '.($data['method'] ?? 'account').'.',

            'track_approved' => "Your track '".($data['title'] ?? 'Unknown')."' has been approved and is now live on the platform!",

            'track_rejected' => "Your track '".($data['title'] ?? 'Unknown')."' was rejected. Reason: ".($data['reason'] ?? 'Quality guidelines not met.'),

            'earnings_milestone' => "Congratulations! You've earned UGX ".number_format($data['amount'] ?? 0).' from your music. Keep creating!',

            'loan_approved' => 'Your SACCO loan of UGX '.number_format($data['amount'] ?? 0).' has been approved. Funds will be disbursed shortly.',

            'loan_payment_due' => 'Your loan payment of UGX '.number_format($data['amount'] ?? 0).' is due on '.($data['due_date'] ?? 'soon').'.',

            'order_shipped' => 'Your order #'.($data['order_id'] ?? 'N/A').' has been shipped and is on its way!',

            default => null
        };
    }

    /**
     * Check if user has opted out of SMS
     */
    public function hasOptedOut(string $phoneNumber): bool
    {
        $formatted = $this->formatUgandanPhoneNumber($phoneNumber);

        // Check opt-out status in database
        return \Illuminate\Support\Facades\Cache::remember(
            "sms_optout:{$formatted}",
            3600,
            function () use ($formatted) {
                return \Illuminate\Support\Facades\DB::table('sms_opt_outs')
                    ->where('phone_number', $formatted)
                    ->where('opted_out', true)
                    ->exists();
            }
        );
    }

    /**
     * Register SMS opt-out
     */
    public function optOut(string $phoneNumber, ?string $reason = null): bool
    {
        $formatted = $this->formatUgandanPhoneNumber($phoneNumber);

        try {
            DB::table('sms_opt_outs')->updateOrInsert(
                ['phone_number' => $formatted],
                [
                    'opted_out' => true,
                    'opted_out_at' => now(),
                    'reason' => $reason,
                    'updated_at' => now(),
                ]
            );

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget("sms_optout:{$formatted}");

            Log::info('SMS opt-out registered', ['phone' => $formatted]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to register SMS opt-out', [
                'phone' => $formatted,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Register SMS opt-in (undo opt-out)
     */
    public function optIn(string $phoneNumber): bool
    {
        $formatted = $this->formatUgandanPhoneNumber($phoneNumber);

        try {
            DB::table('sms_opt_outs')->updateOrInsert(
                ['phone_number' => $formatted],
                [
                    'opted_out' => false,
                    'opted_in_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget("sms_optout:{$formatted}");

            Log::info('SMS opt-in registered', ['phone' => $formatted]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to register SMS opt-in', [
                'phone' => $formatted,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Track SMS delivery status
     */
    public function trackDelivery(string $messageId, string $status, array $metadata = []): void
    {
        try {
            DB::table('sms_delivery_logs')->insert([
                'message_id' => $messageId,
                'status' => $status,
                'metadata' => json_encode($metadata),
                'tracked_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track SMS delivery', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get SMS delivery statistics
     */
    public function getDeliveryStats(string $period = '24h'): array
    {
        $since = match ($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay(),
        };

        try {
            $stats = DB::table('sms_delivery_logs')
                ->where('created_at', '>=', $since)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            return [
                'total' => array_sum($stats),
                'delivered' => $stats['delivered'] ?? 0,
                'failed' => $stats['failed'] ?? 0,
                'pending' => $stats['pending'] ?? 0,
                'by_status' => $stats,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get SMS delivery stats', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Send SMS with validation and opt-out check
     */
    public function send(string $phoneNumber, string $message): bool
    {
        // Validate phone number
        if (! $this->isValidUgandanPhoneNumber($phoneNumber)) {
            Log::warning('Invalid phone number format', ['phone' => $phoneNumber]);

            return false;
        }

        // Check opt-out status
        if ($this->hasOptedOut($phoneNumber)) {
            Log::info('SMS not sent - user opted out', ['phone' => $phoneNumber]);

            return false;
        }

        // Send SMS
        return $this->sendSms($phoneNumber, $message);
    }
}
