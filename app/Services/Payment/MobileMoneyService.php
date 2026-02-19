<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobileMoneyService
{
    protected array $config;

    public function __construct()
    {
        $this->config = [
            'mtn' => [
                'api_url' => env('MTN_MOMO_API_URL', 'https://sandbox.momodeveloper.mtn.com'),
                'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
                'user_id' => env('MTN_MOMO_USER_ID'),
                'api_key' => env('MTN_MOMO_API_KEY'),
                'environment' => env('MTN_MOMO_ENVIRONMENT', 'demo'),
            ],
            'airtel' => [
                'api_url' => env('AIRTEL_MONEY_API_URL', 'https://openapiuat.airtel.africa'),
                'client_id' => env('AIRTEL_MONEY_CLIENT_ID'),
                'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),
                'environment' => env('AIRTEL_MONEY_ENVIRONMENT', 'demo'),
            ],
        ];
    }

    /**
     * Process a payment with the given parameters
     * This method is designed for API calls and testing
     */
    public function processPayment(array $params): array
    {
        try {
            // Validate parameters
            if (! isset($params['amount']) || ! isset($params['payment_method'])) {
                return [
                    'success' => false,
                    'message' => 'Missing required payment parameters',
                ];
            }

            $provider = $params['payment_method'];

            // Make HTTP request to provider API (will be faked in tests)
            $apiUrl = $provider === 'mtn'
                ? $this->config['mtn']['api_url']
                : $this->config['airtel']['api_url'];

            $response = Http::post("{$apiUrl}/collection", [
                'amount' => $params['amount'],
                'phone_number' => $params['phone_number'] ?? null,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Payment processed successfully',
                    'transaction_id' => $data['transactionId'] ?? 'TXN_'.uniqid(),
                    'reference' => strtoupper($provider).'_REF_'.uniqid(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment processing failed',
            ];
        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment processing failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Initiate a mobile money payment
     */
    public function initiatePayment(Payment $payment): array
    {
        try {
            $payment->markAsProcessing();

            $response = match ($payment->provider) {
                Payment::PROVIDER_MTN => $this->initiateMtnPayment($payment),
                Payment::PROVIDER_AIRTEL => $this->initiateAirtelPayment($payment),
                default => throw new \Exception('Unsupported mobile money provider: '.$payment->provider)
            };

            return $response;

        } catch (\Exception $e) {
            Log::error('Mobile money payment initiation failed', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'error' => $e->getMessage(),
            ]);

            $payment->markAsFailed($e->getMessage());

            return [
                'success' => false,
                'message' => 'Payment initiation failed: '.$e->getMessage(),
                'error_code' => 'INITIATION_FAILED',
            ];
        }
    }

    /**
     * Check the status of a mobile money payment
     */
    public function checkPaymentStatus(Payment $payment): array
    {
        try {
            $response = match ($payment->provider) {
                Payment::PROVIDER_MTN => $this->checkMtnPaymentStatus($payment),
                Payment::PROVIDER_AIRTEL => $this->checkAirtelPaymentStatus($payment),
                default => throw new \Exception('Unsupported mobile money provider: '.$payment->provider)
            };

            // Update payment status based on response
            if ($response['success'] && isset($response['status'])) {
                switch ($response['status']) {
                    case 'SUCCESSFUL':
                    case 'SUCCESS':
                        $payment->markAsCompleted([
                            'external_transaction_id' => $response['external_transaction_id'] ?? null,
                            'provider_reference' => $response['provider_reference'] ?? null,
                            'payment_data' => ['status_check_response' => $response],
                        ]);
                        break;

                    case 'FAILED':
                    case 'REJECTED':
                        $payment->markAsFailed(
                            $response['failure_reason'] ?? 'Payment failed',
                            ['payment_data' => ['status_check_response' => $response]]
                        );
                        break;

                    case 'PENDING':
                    case 'ONGOING':
                        // Keep as processing, no change needed
                        break;
                }
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Mobile money status check failed', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Status check failed: '.$e->getMessage(),
                'error_code' => 'STATUS_CHECK_FAILED',
            ];
        }
    }

    /**
     * Initiate MTN Mobile Money payment
     */
    protected function initiateMtnPayment(Payment $payment): array
    {
        // This is a simplified implementation for demonstration
        // In production, you would integrate with MTN MoMo API

        $requestData = [
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'externalId' => $payment->transaction_id,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $this->formatPhoneNumber($payment->phone_number, 'mtn'),
            ],
            'payerMessage' => 'Payment for event ticket',
            'payeeNote' => 'Tesotunes event ticket purchase',
        ];

        // For demo purposes, simulate API call
        if ($this->config['mtn']['environment'] === 'demo') {
            return $this->simulateMtnResponse($payment, $requestData);
        }

        // Real API implementation would go here
        $response = HttpClientFactory::make('mtn_momo')->withHeaders([
            'Authorization' => 'Bearer '.$this->getMtnAccessToken(),
            'X-Reference-Id' => $payment->transaction_id,
            'X-Target-Environment' => $this->config['mtn']['environment'],
            'Ocp-Apim-Subscription-Key' => $this->config['mtn']['subscription_key'],
            'Content-Type' => 'application/json',
        ])->post($this->config['mtn']['api_url'].'/collection/v1_0/requesttopay', $requestData);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Payment initiated successfully. Please check your phone for the payment prompt.',
                'transaction_id' => $payment->transaction_id,
                'provider_reference' => $response->header('X-Reference-Id'),
                'instructions' => 'Dial *165# or check your MTN MoMo app to complete the payment.',
            ];
        }

        throw new \Exception('MTN API Error: '.$response->body());
    }

    /**
     * Initiate Airtel Money payment
     */
    protected function initiateAirtelPayment(Payment $payment): array
    {
        // This is a simplified implementation for demonstration
        // In production, you would integrate with Airtel Money API

        $requestData = [
            'reference' => $payment->transaction_id,
            'subscriber' => [
                'country' => 'UG',
                'currency' => $payment->currency,
                'msisdn' => $this->formatPhoneNumber($payment->phone_number, 'airtel'),
            ],
            'transaction' => [
                'amount' => $payment->amount,
                'country' => 'UG',
                'currency' => $payment->currency,
                'id' => $payment->transaction_id,
            ],
        ];

        // For demo purposes, simulate API call
        if ($this->config['airtel']['environment'] === 'demo') {
            return $this->simulateAirtelResponse($payment, $requestData);
        }

        // Real API implementation would go here
        $accessToken = $this->getAirtelAccessToken();

        $response = HttpClientFactory::make('airtel_money')->withHeaders([
            'Authorization' => 'Bearer '.$accessToken,
            'Content-Type' => 'application/json',
            'X-Country' => 'UG',
            'X-Currency' => 'UGX',
        ])->post($this->config['airtel']['api_url'].'/merchant/v1/payments/', $requestData);

        if ($response->successful()) {
            $data = $response->json();

            return [
                'success' => true,
                'message' => 'Payment initiated successfully. Please check your phone for the payment prompt.',
                'transaction_id' => $payment->transaction_id,
                'provider_reference' => $data['data']['transaction']['id'] ?? null,
                'instructions' => 'Check your Airtel Money app or dial *185# to complete the payment.',
            ];
        }

        throw new \Exception('Airtel API Error: '.$response->body());
    }

    /**
     * Check MTN payment status
     */
    protected function checkMtnPaymentStatus(Payment $payment): array
    {
        if ($this->config['mtn']['environment'] === 'demo') {
            return $this->simulateMtnStatusCheck($payment);
        }

        $response = HttpClientFactory::make('mtn_momo')->withHeaders([
            'Authorization' => 'Bearer '.$this->getMtnAccessToken(),
            'X-Target-Environment' => $this->config['mtn']['environment'],
            'Ocp-Apim-Subscription-Key' => $this->config['mtn']['subscription_key'],
        ])->get($this->config['mtn']['api_url'].'/collection/v1_0/requesttopay/'.$payment->transaction_id);

        if ($response->successful()) {
            $data = $response->json();

            return [
                'success' => true,
                'status' => $data['status'],
                'external_transaction_id' => $data['financialTransactionId'] ?? null,
                'provider_reference' => $data['externalId'] ?? null,
                'failure_reason' => $data['reason'] ?? null,
            ];
        }

        throw new \Exception('MTN Status Check Failed: '.$response->body());
    }

    /**
     * Check Airtel payment status
     */
    protected function checkAirtelPaymentStatus(Payment $payment): array
    {
        if ($this->config['airtel']['environment'] === 'demo') {
            return $this->simulateAirtelStatusCheck($payment);
        }

        $accessToken = $this->getAirtelAccessToken();

        $response = HttpClientFactory::make('airtel_money')->withHeaders([
            'Authorization' => 'Bearer '.$accessToken,
            'X-Country' => 'UG',
            'X-Currency' => 'UGX',
        ])->get($this->config['airtel']['api_url'].'/standard/v1/payments/'.$payment->transaction_id);

        if ($response->successful()) {
            $data = $response->json();

            return [
                'success' => true,
                'status' => $data['data']['transaction']['status'],
                'external_transaction_id' => $data['data']['transaction']['airtel_money_id'] ?? null,
                'provider_reference' => $data['data']['transaction']['id'] ?? null,
            ];
        }

        throw new \Exception('Airtel Status Check Failed: '.$response->body());
    }

    /**
     * Demo/Simulation methods for testing
     */
    protected function simulateMtnResponse(Payment $payment, array $requestData): array
    {
        // Simulate different responses based on phone number for testing
        $phone = $payment->phone_number;

        if (str_ends_with($phone, '1111')) {
            // Simulate immediate failure
            throw new \Exception('Insufficient funds');
        }

        if (str_ends_with($phone, '2222')) {
            // Simulate timeout
            throw new \Exception('Request timeout');
        }

        return [
            'success' => true,
            'message' => 'Payment initiated successfully (Demo Mode). Please check your phone.',
            'transaction_id' => $payment->transaction_id,
            'provider_reference' => 'MTN_'.uniqid(),
            'instructions' => 'Demo Mode: Payment will be automatically completed in 30 seconds.',
        ];
    }

    protected function simulateAirtelResponse(Payment $payment, array $requestData): array
    {
        $phone = $payment->phone_number;

        if (str_ends_with($phone, '1111')) {
            throw new \Exception('Invalid phone number');
        }

        return [
            'success' => true,
            'message' => 'Payment initiated successfully (Demo Mode). Please check your phone.',
            'transaction_id' => $payment->transaction_id,
            'provider_reference' => 'AIRTEL_'.uniqid(),
            'instructions' => 'Demo Mode: Payment will be automatically completed in 30 seconds.',
        ];
    }

    protected function simulateMtnStatusCheck(Payment $payment): array
    {
        // In demo mode, mark payments as successful after 30 seconds
        $timeDiff = now()->diffInSeconds($payment->initiated_at);

        if ($timeDiff > 30) {
            return [
                'success' => true,
                'status' => 'SUCCESSFUL',
                'external_transaction_id' => 'MTN_'.uniqid(),
                'provider_reference' => $payment->transaction_id,
            ];
        }

        return [
            'success' => true,
            'status' => 'PENDING',
        ];
    }

    protected function simulateAirtelStatusCheck(Payment $payment): array
    {
        $timeDiff = now()->diffInSeconds($payment->initiated_at);

        if ($timeDiff > 30) {
            return [
                'success' => true,
                'status' => 'SUCCESS',
                'external_transaction_id' => 'AIRTEL_'.uniqid(),
                'provider_reference' => $payment->transaction_id,
            ];
        }

        return [
            'success' => true,
            'status' => 'PENDING',
        ];
    }

    /**
     * Utility methods
     */
    protected function formatPhoneNumber(string $phone, string $provider): string
    {
        // Remove any non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Ensure it starts with country code (256 for Uganda)
        if (! str_starts_with($phone, '256')) {
            if (str_starts_with($phone, '0')) {
                $phone = '256'.substr($phone, 1);
            } else {
                $phone = '256'.$phone;
            }
        }

        return $phone;
    }

    protected function getMtnAccessToken(): string
    {
        // In production, implement proper OAuth flow
        // This is a simplified version
        return 'demo_access_token';
    }

    protected function getAirtelAccessToken(): string
    {
        // In production, implement proper OAuth flow
        return 'demo_access_token';
    }

    /**
     * Validate phone number for specific provider
     */
    public function validatePhoneNumber(string $phone, string $provider): bool
    {
        $formatted = $this->formatPhoneNumber($phone, $provider);

        return match ($provider) {
            Payment::PROVIDER_MTN => $this->isValidMtnNumber($formatted),
            Payment::PROVIDER_AIRTEL => $this->isValidAirtelNumber($formatted),
            default => false
        };
    }

    protected function isValidMtnNumber(string $phone): bool
    {
        // MTN Uganda prefixes: 256-77, 256-78, 256-76
        return preg_match('/^256(77|78|76)\d{7}$/', $phone);
    }

    protected function isValidAirtelNumber(string $phone): bool
    {
        // Airtel Uganda prefixes: 256-75, 256-70, 256-74
        return preg_match('/^256(75|70|74)\d{7}$/', $phone);
    }
}
