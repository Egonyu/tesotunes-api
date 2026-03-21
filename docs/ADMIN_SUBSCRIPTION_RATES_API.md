# Admin Subscription Rates API

This API supports the admin panel screen for managing:
- Per-subscription-plan stream rates in UGX
- Per-subscription-plan credit-to-UGX conversion rates
- Platform-wide commission percentages

## Endpoints

### Get rates dashboard data

`GET /api/admin/subscriptions/rates`

Returns all subscription plans with editable rate fields and the current platform commissions.

Example response:

```json
{
  "success": true,
  "data": {
    "plans": [
      {
        "id": 1,
        "name": "Premium Plan",
        "slug": "premium-plan-123",
        "tier": "premium",
        "currency": "UGX",
        "price_monthly": "40000.00",
        "price_yearly": "400000.00",
        "is_active": true,
        "rates": {
          "stream_rate_ugx": "10.00",
          "credit_to_ugx_rate": "1.5000",`r`n          "effective": {`r`n            "configured_stream_rate_ugx": "10.00",`r`n            "effective_stream_rate_ugx": "10.00",`r`n            "streaming_commission_percent": "15.00",`r`n            "estimated_platform_fee_ugx": "1.50",`r`n            "estimated_net_per_stream_ugx": "8.50",`r`n            "rate_source": "plan_metadata"`r`n          }
        }
      }
    ],
    "platform_commissions": {
      "streaming_percent": "15.00",
      "subscription_percent": "5.00",
      "credit_conversion_percent": "1.50",
      "withdrawal_percent": "2.50",
      "distribution_percent": "10.00",
      "store_percent": "4.00"
    }
  }
}
```

### Bulk update rates dashboard data

`PUT /api/admin/subscriptions/rates`

Example request:

```json
{
  "plans": [
    {
      "id": 1,
      "stream_rate_ugx": 4.5,
      "credit_to_ugx_rate": 1.1
    },
    {
      "id": 2,
      "stream_rate_ugx": 12,
      "credit_to_ugx_rate": 1.75
    }
  ],
  "platform_commissions": {
    "streaming_percent": 15,
    "subscription_percent": 5,
    "credit_conversion_percent": 1.5,
    "withdrawal_percent": 2.5,
    "distribution_percent": 10,
    "store_percent": 4
  }
}
```

### Update a single subscription plan

`PUT /api/admin/subscription-plans/{id}`

This endpoint now accepts a nested `rates` object, so the admin panel can save inline edits for one plan without calling the bulk endpoint.

Example request:

```json
{
  "name": "Premium Plus",
  "price_monthly": 55000,
  "rates": {
    "stream_rate_ugx": 11.5,
    "credit_to_ugx_rate": 1.65
  }
}
```

## Notes

- Per-plan rates are stored in `subscription_plans.metadata`.
- Platform commissions are stored in the `settings` table under the `platform_commissions` key.
- Numeric values are normalized before storage.
  - `stream_rate_ugx`: 2 decimal places
  - `credit_to_ugx_rate`: 4 decimal places
  - commission percentages: 2 decimal places

