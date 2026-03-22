<?php

namespace App\Services\Payment\Adapters;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ZengaPay Gateway Adapter
 *
 * Integrates with ZengaPay API for:
 * - Collections (charge users via MTN MoMo, Airtel Money, Bank, Cards)
 * - Disbursements (payouts to artists, refunds)
 * - Transaction status checks
 * - Account balance queries
 *
 * @see https://developers.zengapay.com
 */
class ZengaPayGatewayAdapter
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $environment;

    public function __construct()
    {
        $config = config('services.zengapay');

        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.zengapay.com/v1';
        $this->environment = $config['environment'] ?? 'production';

        if (empty($this->apiKey)) {
            Log::warning('ZengaPay API key is not configured');
        }
    }

    /**
     * Initiate a collection (charge the user)
     *
     * Used by PaymentService::processZengaPayPayment() and processMethodRefund()
     *
     * @param  array  $data  [amount, phone, reference, description]
     * @return array [success, transaction_id, reference, message]
     */
    public function charge(array $data): array
    {
        try {
            $this->validateChargeData($data);

            $payload = [
                'msisdn' => $this->formatPhoneNumber($data['phone']),
                'amount' => (int) $data['amount'],
                'external_reference' => $data['reference'],
                'narration' => $data['description'] ?? 'TesoTunes Payment',
            ];

            $response = $this->makeRequest('POST', '/collections', $payload);

            if ($response['success']) {
                $normalized = $response['data'] ?? [];

                return [
                    'success' => true,
                    'transaction_id' => $this->extractTransactionIdentifier($normalized),
                    'reference' => $this->extractExternalReference($normalized, $data['reference']),
                    'message' => $normalized['message'] ?? $response['message'] ?? 'Payment request sent. Please approve on your phone.',
                    'raw_response' => $response['raw'] ?? $normalized,
                ];
            }

            return [
                'success' => false,
                'message' => $response['message'] ?? 'Collection request failed',
            ];
        } catch (Exception $e) {
            Log::error('ZengaPay charge failed', [
                'data' => array_diff_key($data, ['phone' => '']),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Initiate a disbursement (payout/transfer to user)
     *
     * Used by PaymentService::processZengaPayPayout() and processMethodRefund()
     *
     * @param  array  $data  [amount, phone, reference, description]
     * @return array [success, transaction_id, reference, message]
     */
    public function payout(array $data): array
    {
        try {
            $this->validatePayoutData($data);

            $payload = [
                'msisdn' => $this->formatPhoneNumber($data['phone']),
                'amount' => (int) $data['amount'],
                'external_reference' => $data['reference'],
                'narration' => $data['description'] ?? 'TesoTunes Payout',
            ];

            $response = $this->makeRequest('POST', '/transfers', $payload);

            if ($response['success']) {
                $normalized = $response['data'] ?? [];

                return [
                    'success' => true,
                    'transaction_id' => $this->extractTransactionIdentifier($normalized),
                    'reference' => $this->extractExternalReference($normalized, $data['reference']),
                    'message' => $normalized['message'] ?? $response['message'] ?? 'Payout initiated successfully',
                    'raw_response' => $response['raw'] ?? $normalized,
                ];
            }

            return [
                'success' => false,
                'message' => $response['message'] ?? 'Payout request failed',
            ];
        } catch (Exception $e) {
            Log::error('ZengaPay payout failed', [
                'data' => array_diff_key($data, ['phone' => '']),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get transaction status
     *
     * Used by PaymentService::checkZengaPayStatus()
     *
     * @param  string  $transactionId  ZengaPay transaction ID
     * @return array [success, status, data]
     */
    public function getTransactionStatus(string $transactionId): array
    {
        try {
            if (! $this->isProviderTransactionIdentifier($transactionId)) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_PROVIDER_TRANSACTION_ID',
                    'message' => 'Provider transaction identifier must be a UUID.',
                ];
            }

            $response = $this->makeRequest('GET', "/collections/{$transactionId}");

            if ($response['success']) {
                $data = $response['data'] ?? [];
                $status = strtolower($this->extractStatus($data));

                return [
                    'success' => true,
                    'status' => $this->mapTransactionStatus($status),
                    'raw_status' => $status,
                    'data' => $data,
                    'raw_response' => $response['raw'] ?? $data,
                ];
            }

            // Try transfers endpoint if collection not found
            $response = $this->makeRequest('GET', "/transfers/{$transactionId}");

            if ($response['success']) {
                $data = $response['data'] ?? [];
                $status = strtolower($this->extractStatus($data));

                return [
                    'success' => true,
                    'status' => $this->mapTransactionStatus($status),
                    'raw_status' => $status,
                    'data' => $data,
                    'raw_response' => $response['raw'] ?? $data,
                ];
            }

            return [
                'success' => false,
                'message' => 'Transaction not found',
            ];
        } catch (Exception $e) {
            Log::error('ZengaPay status check failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get ZengaPay account balance
     *
     * Used by PaymentService::getZengaPayBalance()
     *
     * @return array [success, balance, currency]
     */
    public function getBalance(): array
    {
        try {
            $response = $this->makeRequest('GET', '/account/balance');

            if ($response['success']) {
                return [
                    'success' => true,
                    'balance' => $response['data']['balance'] ?? 0,
                    'currency' => $response['data']['currency'] ?? 'UGX',
                ];
            }

            return [
                'success' => false,
                'message' => $response['message'] ?? 'Unable to retrieve balance',
            ];
        } catch (Exception $e) {
            Log::error('ZengaPay balance check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an authenticated HTTP request to ZengaPay API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');

        try {
            $http = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $data),
                'POST' => $http->post($url, $data),
                'PUT' => $http->put($url, $data),
                'DELETE' => $http->delete($url, $data),
                default => throw new Exception("Unsupported HTTP method: {$method}"),
            };

            $body = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $this->normalizeResponseData($body),
                    'message' => $body['message'] ?? 'Success',
                    'raw' => $body,
                ];
            }

            Log::warning('ZengaPay API error response', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return [
                'success' => false,
                'message' => $body['message'] ?? $body['error'] ?? 'API request failed',
                'data' => $body,
            ];
        } catch (Exception $e) {
            Log::error('ZengaPay HTTP request failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Format phone number to ZengaPay's expected format (256XXXXXXXXX)
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Handle Ugandan numbers
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '256'.substr($phone, 1);
        }

        // If no country code, assume Uganda
        if (strlen($phone) === 9) {
            $phone = '256'.$phone;
        }

        // Remove leading + if present in the original
        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Map ZengaPay transaction status to internal status
     */
    protected function mapTransactionStatus(string $zengaPayStatus): string
    {
        return match ($zengaPayStatus) {
            'succeeded', 'successful', 'completed', 'success' => 'completed',
            'pending', 'initiated', 'processing', 'in_progress', 'requested', 'queued', 'received', 'indeterminate' => 'processing',
            'failed', 'failure', 'declined', 'rejected' => 'failed',
            'cancelled', 'expired', 'timeout', 'timed_out' => 'cancelled',
            'reversed', 'reversed_successfully', 'refunded' => 'refunded',
            default => 'unknown',
        };
    }

    protected function normalizeResponseData(array $body): array
    {
        $normalized = $body;

        if (isset($body['data']) && is_array($body['data'])) {
            $normalized = array_merge($body['data'], $body);
        }

        unset($normalized['data']);

        return $normalized;
    }

    protected function extractTransactionIdentifier(array $data): ?string
    {
        $candidates = [
            $data['id'] ?? null,
            $data['transactionId'] ?? null,
            $data['transaction_id'] ?? null,
            $data['providerTransactionId'] ?? null,
            $data['provider_transaction_id'] ?? null,
            $data['transactionReference'] ?? null,
            $data['transaction_reference'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($this->isProviderTransactionIdentifier($candidate)) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    protected function extractExternalReference(array $data, ?string $fallback = null): ?string
    {
        return $data['transactionExternalReference']
            ?? $data['transaction_external_reference']
            ?? $data['externalReference']
            ?? $data['external_reference']
            ?? $fallback;
    }

    protected function extractStatus(array $data): string
    {
        return (string) ($data['transactionStatus']
            ?? $data['transaction_status']
            ?? $data['status']
            ?? 'unknown');
    }

    protected function isProviderTransactionIdentifier(mixed $candidate): bool
    {
        if (! is_string($candidate)) {
            return false;
        }

        $value = trim($candidate);

        return $value !== '' && Str::isUuid($value);
    }

    /**
     * Validate charge data
     */
    protected function validateChargeData(array $data): void
    {
        if (empty($data['amount']) || $data['amount'] <= 0) {
            throw new Exception('Invalid charge amount');
        }

        if (empty($data['phone'])) {
            throw new Exception('Phone number is required for collection');
        }

        if (empty($data['reference'])) {
            throw new Exception('Payment reference is required');
        }
    }

    /**
     * Validate payout data
     */
    protected function validatePayoutData(array $data): void
    {
        if (empty($data['amount']) || $data['amount'] <= 0) {
            throw new Exception('Invalid payout amount');
        }

        if (empty($data['phone'])) {
            throw new Exception('Phone number is required for payout');
        }

        if (empty($data['reference'])) {
            throw new Exception('Payout reference is required');
        }
    }
}
