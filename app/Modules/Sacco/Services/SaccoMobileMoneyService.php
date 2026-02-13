<?php

namespace App\Modules\Sacco\Services;

use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SaccoMobileMoneyService
{
    protected string $mtnApiUrl;
    protected string $mtnApiKey;
    protected string $mtnApiSecret;
    protected string $airtelApiUrl;
    protected string $airtelApiKey;
    protected string $airtelApiSecret;

    public function __construct()
    {
        $this->mtnApiUrl = config('services.mtn.api_url', 'https://sandbox.momodeveloper.mtn.com');
        $this->mtnApiKey = config('services.mtn.api_key');
        $this->mtnApiSecret = config('services.mtn.api_secret');
        $this->airtelApiUrl = config('services.airtel.api_url', 'https://openapi.airtel.africa');
        $this->airtelApiKey = config('services.airtel.api_key');
        $this->airtelApiSecret = config('services.airtel.api_secret');
    }

    /**
     * Request payment from MTN Mobile Money
     */
    public function requestMTNPayment(string $phoneNumber, float $amount, string $reference): array
    {
        try {
            $accessToken = $this->getMTNAccessToken();
            $referenceId = Str::uuid()->toString();
            
            $response = HttpClientFactory::make('mtn_momo')->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => config('services.mtn.environment', 'sandbox'),
                'Content-Type' => 'application/json',
                'Ocp-Apim-Subscription-Key' => $this->mtnApiKey
            ])->post("{$this->mtnApiUrl}/collection/v1_0/requesttopay", [
                'amount' => (string)$amount,
                'currency' => 'UGX',
                'externalId' => $reference,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $this->formatPhoneNumber($phoneNumber)
                ],
                'payerMessage' => 'SACCO Payment',
                'payeeNote' => 'SACCO transaction'
            ]);
            
            if ($response->successful() || $response->status() === 202) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'status' => 'pending',
                    'message' => 'Payment request sent successfully'
                ];
            }
            
            Log::error('MTN Mobile Money request failed', [
                'response' => $response->body(),
                'status' => $response->status()
            ]);
            
            return [
                'success' => false,
                'error' => 'Payment request failed',
                'details' => $response->json()
            ];
            
        } catch (\Exception $e) {
            Log::error('MTN Mobile Money error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check MTN payment status
     */
    public function checkMTNPaymentStatus(string $referenceId): array
    {
        try {
            $accessToken = $this->getMTNAccessToken();
            
            $response = HttpClientFactory::make('mtn_momo')->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'X-Target-Environment' => config('services.mtn.environment', 'sandbox'),
                'Ocp-Apim-Subscription-Key' => $this->mtnApiKey
            ])->get("{$this->mtnApiUrl}/collection/v1_0/requesttopay/{$referenceId}");
            
            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'status' => strtolower($data['status'] ?? 'pending'),
                    'amount' => $data['amount'] ?? 0,
                    'currency' => $data['currency'] ?? 'UGX',
                    'data' => $data
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Failed to check payment status'
            ];
            
        } catch (\Exception $e) {
            Log::error('MTN payment status check error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send money via MTN Mobile Money (disbursement)
     */
    public function sendMTNMoney(string $phoneNumber, float $amount, string $reference): array
    {
        try {
            $accessToken = $this->getMTNAccessToken();
            $referenceId = Str::uuid()->toString();
            
            $response = HttpClientFactory::make('mtn_momo')->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => config('services.mtn.environment', 'sandbox'),
                'Content-Type' => 'application/json',
                'Ocp-Apim-Subscription-Key' => $this->mtnApiKey
            ])->post("{$this->mtnApiUrl}/disbursement/v1_0/transfer", [
                'amount' => (string)$amount,
                'currency' => 'UGX',
                'externalId' => $reference,
                'payee' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $this->formatPhoneNumber($phoneNumber)
                ],
                'payerMessage' => 'SACCO Disbursement',
                'payeeNote' => 'SACCO payment'
            ]);
            
            if ($response->successful() || $response->status() === 202) {
                return [
                    'success' => true,
                    'reference_id' => $referenceId,
                    'status' => 'pending',
                    'message' => 'Disbursement initiated successfully'
                ];
            }
            
            Log::error('MTN disbursement failed', [
                'response' => $response->body(),
                'status' => $response->status()
            ]);
            
            return [
                'success' => false,
                'error' => 'Disbursement failed'
            ];
            
        } catch (\Exception $e) {
            Log::error('MTN disbursement error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Request payment from Airtel Money
     */
    public function requestAirtelPayment(string $phoneNumber, float $amount, string $reference): array
    {
        try {
            $accessToken = $this->getAirtelAccessToken();
            
            $response = HttpClientFactory::make('airtel_money')->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
                'X-Country' => 'UG',
                'X-Currency' => 'UGX'
            ])->post("{$this->airtelApiUrl}/merchant/v1/payments/", [
                'reference' => $reference,
                'subscriber' => [
                    'country' => 'UG',
                    'currency' => 'UGX',
                    'msisdn' => $this->formatPhoneNumber($phoneNumber)
                ],
                'transaction' => [
                    'amount' => $amount,
                    'country' => 'UG',
                    'currency' => 'UGX',
                    'id' => $reference
                ]
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'reference_id' => $data['data']['transaction']['id'] ?? $reference,
                    'status' => 'pending',
                    'message' => 'Payment request sent successfully'
                ];
            }
            
            Log::error('Airtel Money request failed', [
                'response' => $response->body(),
                'status' => $response->status()
            ]);
            
            return [
                'success' => false,
                'error' => 'Payment request failed'
            ];
            
        } catch (\Exception $e) {
            Log::error('Airtel Money error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get MTN Mobile Money access token
     */
    protected function getMTNAccessToken(): string
    {
        $response = HttpClientFactory::make('mtn_momo')->withBasicAuth($this->mtnApiKey, $this->mtnApiSecret)
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->mtnApiKey
            ])
            ->post("{$this->mtnApiUrl}/collection/token/");
        
        if ($response->successful()) {
            return $response->json()['access_token'];
        }
        
        throw new \Exception('Failed to obtain MTN access token');
    }

    /**
     * Get Airtel Money access token
     */
    protected function getAirtelAccessToken(): string
    {
        $response = HttpClientFactory::make('airtel_money')->withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => '*/*'
        ])->post("{$this->airtelApiUrl}/auth/oauth2/token", [
            'client_id' => $this->airtelApiKey,
            'client_secret' => $this->airtelApiSecret,
            'grant_type' => 'client_credentials'
        ]);
        
        if ($response->successful()) {
            return $response->json()['access_token'];
        }
        
        throw new \Exception('Failed to obtain Airtel access token');
    }

    /**
     * Format phone number to international format
     */
    protected function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If starts with 0, replace with 256 (Uganda code)
        if (substr($phoneNumber, 0, 1) === '0') {
            $phoneNumber = '256' . substr($phoneNumber, 1);
        }
        
        // If doesn't start with 256, add it
        if (substr($phoneNumber, 0, 3) !== '256') {
            $phoneNumber = '256' . $phoneNumber;
        }
        
        return $phoneNumber;
    }

    /**
     * Process payment based on provider
     */
    public function processPayment(string $provider, string $phoneNumber, float $amount, string $reference): array
    {
        return match(strtolower($provider)) {
            'mtn' => $this->requestMTNPayment($phoneNumber, $amount, $reference),
            'airtel' => $this->requestAirtelPayment($phoneNumber, $amount, $reference),
            default => [
                'success' => false,
                'error' => 'Unsupported payment provider'
            ]
        };
    }
}
