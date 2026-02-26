# Webhook System Documentation

## Overview

TesoTunes receives webhooks from external payment providers (ZengaPay, Airtel Money)
and distribution platforms to process asynchronous events like payment confirmations,
refunds, and content status updates.

## Webhook Endpoints

| Endpoint | Handler | Verification | Rate Limited | Status |
|----------|---------|-------------|-------------|--------|
| `POST /api/webhooks/zengapay` | `ZengaPayService::handleWebhook()` | HMAC signature | ✅ Yes | ✅ Active |
| `POST /api/webhooks/payment/{provider}` | `PaymentController::webhook()` | HMAC signature | ✅ Yes | ✅ Active |
| `POST /api/payments/webhook` | `PaymentController::webhook()` | HMAC signature | ✅ Yes | ✅ Active |
| `POST /api/webhooks/payment` (Store) | `Store\PaymentController::webhook()` | HMAC signature | ✅ Yes | ✅ Active |
| `POST /api/webhooks/mobile-money` | `MobileMoneyWebhookController::handle()` | HMAC signature | ✅ Yes | ✅ Active |
| `POST /api/webhooks/distribution/{platform}` | `DistributionWebhookController` | - | ✅ Yes | ⬜ Stub |

## Security

### Signature Verification

All webhook endpoints verify HMAC-SHA256 signatures:

```php
// All handlers compute: hash_hmac('sha256', $rawPayload, $secret)
// And compare with timing-safe hash_equals()
```

**Provider-specific secrets:**
- ZengaPay: `ZENGAPAY_WEBHOOK_SECRET` (config/services.php)
- Airtel Money: `AIRTEL_WEBHOOK_SECRET`
- MTN MoMo: `MTN_WEBHOOK_SECRET`
- Generic: `PAYMENT_WEBHOOK_SECRET`

**Behavior when secret not configured:**
- **Production:** Webhook is **rejected** (returns 403)
- **Local/Testing:** Signature verification is **skipped** with a log warning

### Rate Limiting

`WebhookRateLimiter` middleware (`webhook.rate_limit`) is applied to **all webhook routes**:
- **Limit:** 60 requests/minute per IP
- **Response:** `429 Too Many Requests`

## Retry Mechanism

### Incoming Webhooks (from providers to TesoTunes)

TesoTunes relies on each provider's retry policy:

| Provider | Retry Policy | Timeout |
|----------|-------------|---------|
| ZengaPay | Up to 3 retries with exponential backoff | 30 seconds |
| Airtel Money | Up to 5 retries over 24 hours | 15 seconds |

**Requirements for reliable webhook handling:**
1. Return `200 OK` immediately upon receipt
2. Process heavy operations asynchronously via queued jobs
3. Implement idempotency (check if event was already processed)

### Outgoing Webhooks (from TesoTunes to partners)

Not currently implemented. Future consideration for:
- Distribution status updates to labels
- Payout completion notifications

## Failed Job Handling

### Queue Configuration

```php
// config/queue.php
'retry_after' => 90,  // seconds before a job is retried
'failed' => [
    'driver' => 'database-uuids',
    'database' => 'mysql',
    'table' => 'failed_jobs',
],
```

### Monitoring

- `HealthMonitorCommand` alerts when > 10 failed jobs/hour
- `SystemMonitoringService` tracks `failed_jobs` count as health metric
- Failed jobs are stored in `failed_jobs` table with full exception trace

### Retrying Failed Jobs

```bash
# List failed jobs
php artisan queue:failed

# Retry a specific job
php artisan queue:retry <uuid>

# Retry all failed jobs
php artisan queue:retry all

# Flush all failed jobs
php artisan queue:flush
```

## Idempotency

All webhook handlers check payment/order status before processing:

| Handler | Idempotency | Finalized Statuses |
|---------|-------------|-------------------|
| `ZengaPayService` | ✅ Yes | completed, refunded, failed |
| `PaymentController` | ✅ Yes | completed, refunded, cancelled |
| `MobileMoneyWebhookController` | ✅ Yes | completed, refunded, cancelled |
| `Store\PaymentController` | ✅ Yes | paid, refunded |

Already-finalized webhooks return `200 OK` with `"Payment already processed"`
to prevent providers from retrying.

## Dead-Letter Queue (Planned)

A dead-letter queue for permanently failed webhook processing is planned:

### Design

1. After 3 failed processing attempts, move to `webhook_dead_letters` table
2. Store: original payload, headers, failure reason, timestamps
3. Admin dashboard for manual review and replay

### Future Migration

```sql
CREATE TABLE webhook_dead_letters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    headers JSON,
    failure_reason TEXT,
    attempts INT DEFAULT 0,
    last_attempted_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_provider (provider),
    INDEX idx_resolved (resolved_at)
);
```

## Troubleshooting

### Webhook not being received

1. Check webhook URL is accessible from the internet
2. Verify SSL certificate is valid (providers reject self-signed)
3. Check `WebhookRateLimiter` isn't blocking (check IP whitelist)
4. Review `storage/logs/laravel.log` for incoming request logs

### Webhook received but not processed

1. Check `failed_jobs` table for exceptions
2. Verify signature secret matches provider configuration
3. Check queue worker is running: `php artisan queue:work`
4. Review `ApiLoggingMiddleware` JSON logs for request/response details

### Duplicate webhook processing

1. Implement idempotency check (see above)
2. Use database transactions for atomic status updates
3. Log webhook event IDs to detect duplicates
