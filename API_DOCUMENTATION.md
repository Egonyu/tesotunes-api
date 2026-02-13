# TésoTunes Admin API Documentation

Complete OpenAPI 3.0 specification for the TésoTunes admin panel REST API.

## Viewing the Documentation

### Option 1: Swagger UI (Interactive)

Open the interactive API documentation in your browser:

```
http://beta.test/api-docs.html
```

**Features:**
- Interactive API testing
- Request/response examples
- Schema definitions
- Authorization support

### Option 2: OpenAPI File

The raw OpenAPI specification is available at:

```
http://beta.test/openapi.yaml
```

You can import this into:
- Postman
- Insomnia
- Any OpenAPI-compatible tool

## API Overview

### Base URL
- Production: `https://api.tesotunes.com`
- Development: `http://beta.test`

### Authentication

All admin endpoints require Bearer token authentication via Laravel Sanctum.

**Getting a token:**
```bash
curl -X POST http://beta.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@tesotunes.com","password":"password"}'
```

**Using the token:**
```bash
curl -X GET http://beta.test/api/admin/sacco/dashboard \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## API Modules

### 1. SACCO Management (21 endpoints)

Microfinance system for artist loans and savings.

**Key Features:**
- Member management (approve, suspend)
- Loan processing (approve, reject, write-off)
- Loan product configuration
- Transaction monitoring
- Settings management

**Base Path:** `/api/admin/sacco`

**Example Endpoints:**
- `GET /api/admin/sacco/dashboard` - Dashboard statistics
- `GET /api/admin/sacco/members` - List all members
- `POST /api/admin/sacco/loans/{id}/approve` - Approve loan
- `GET /api/admin/sacco/transactions` - View transactions

### 2. Loyalty Management (15 endpoints)

Fan clubs, rewards, and points system.

**Key Features:**
- Fan club management (approve, suspend)
- Global rewards CRUD
- Points system configuration
- Leaderboards
- Referral tracking

**Base Path:** `/api/admin/loyalty`

**Example Endpoints:**
- `GET /api/admin/loyalty/dashboard` - Dashboard statistics
- `GET /api/admin/loyalty/fan-clubs` - List all fan clubs
- `POST /api/admin/loyalty/rewards` - Create reward
- `GET /api/admin/loyalty/leaderboards` - View leaderboards

### 3. Promotions Management (15 endpoints)

Marketing campaigns and platform management.

**Key Features:**
- Promotion approval workflow
- Platform management
- Analytics and reporting
- Bulk operations
- Order tracking

**Base Path:** `/api/admin/promotions`

**Example Endpoints:**
- `GET /api/admin/promotions` - List all promotions
- `POST /api/admin/promotions/{id}/approve` - Approve promotion
- `GET /api/admin/promotions/analytics` - View analytics
- `POST /api/admin/promotions/bulk-action` - Bulk operations

## Response Format

All endpoints return a standardized JSON response:

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": { ... },
  "meta": {
    "total": 100,
    "per_page": 20,
    "current_page": 1,
    "last_page": 5
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Operation failed",
  "error": "Detailed error message"
}
```

## Testing

### Automated Test Scripts

Three comprehensive test scripts are available:

**1. SACCO Admin Tests**
```bash
./test-sacco-admin.sh
```
Tests all 21 SACCO endpoints (15/16 passing ✓)

**2. Loyalty Admin Tests**
```bash
./test-loyalty-admin.sh
```
Tests all 15 Loyalty endpoints with CRUD operations

**3. Promotions Admin Tests**
```bash
./test-promotions-admin.sh
```
Tests all 15 Promotions endpoints with workflow testing

### Manual Testing with cURL

**List SACCO Members:**
```bash
curl -X GET "http://beta.test/api/admin/sacco/members?status=active&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Create Loyalty Reward:**
```bash
curl -X POST "http://beta.test/api/admin/loyalty/rewards" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Exclusive Track",
    "reward_type": "free_content",
    "points_required": 500,
    "is_active": true
  }'
```

**Approve Promotion:**
```bash
curl -X POST "http://beta.test/api/admin/promotions/1/approve" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

## Rate Limiting

- API calls are rate-limited per user
- Limits are configured in Laravel middleware
- 429 status code returned when limit exceeded

## Pagination

All list endpoints support pagination:

**Query Parameters:**
- `per_page` - Items per page (default: 20, max: 100)
- `page` - Page number (default: 1)

**Example:**
```
GET /api/admin/sacco/members?per_page=50&page=2
```

## Filtering & Sorting

Most list endpoints support filtering and sorting:

**Common Filters:**
- `status` - Filter by status
- `search` - Search by name, email, etc.
- `date_from` / `date_to` - Date range
- `type` - Filter by type

**Sorting:**
- `sort_by` - Column to sort by (e.g., 'created_at')
- `sort_order` - Sort direction ('asc' or 'desc')

**Example:**
```
GET /api/admin/sacco/loans?status=active&sort_by=created_at&sort_order=desc
```

## Integration Examples

### JavaScript/TypeScript

```typescript
const BASE_URL = 'http://beta.test/api';
const token = localStorage.getItem('api_token');

async function getSaccoDashboard() {
  const response = await fetch(`${BASE_URL}/admin/sacco/dashboard`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });

  const data = await response.json();
  return data.data;
}
```

### React Query

```typescript
import { useQuery } from '@tanstack/react-query';

export function useSaccoMembers(filters = {}) {
  return useQuery({
    queryKey: ['sacco-members', filters],
    queryFn: async () => {
      const params = new URLSearchParams(filters);
      const response = await fetch(
        `${BASE_URL}/admin/sacco/members?${params}`,
        {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
          }
        }
      );
      return response.json();
    }
  });
}
```

## Database Schema

### SACCO Tables (8)
- `sacco_members` - Member records
- `sacco_loan_products` - Loan product definitions
- `sacco_loans` - Loan applications and tracking
- `sacco_accounts` - Savings accounts
- `sacco_transactions` - All financial transactions
- `sacco_loan_repayments` - Repayment history
- `sacco_audit_logs` - Audit trail
- `sacco_settings` - System configuration

### Loyalty Tables (9)
- `fan_clubs` - Fan club records
- `fan_club_tiers` - Membership tiers
- `fan_club_memberships` - User memberships
- `loyalty_rewards` - Available rewards
- `loyalty_points` - Points transaction log
- `user_loyalty_points` - User point balances
- `reward_redemptions` - Redemption history
- `user_referrals` - Referral tracking
- `loyalty_points_config` - Points system settings

### Promotions Tables (4)
- `promotion_platforms` - Available platforms (TikTok, Instagram, etc.)
- `promotions` - Promotion campaigns
- `promotion_orders` - Customer orders
- `promotion_reviews` - Customer feedback

## Roadmap

### Completed ✓
- [x] 107 admin API endpoints across 10 modules
- [x] 21 database tables for SACCO, Loyalty, Promotions
- [x] Automated test scripts
- [x] OpenAPI 3.0 specification
- [x] Interactive Swagger UI documentation

### In Progress 🔄
- [ ] Next.js admin dashboard components
- [ ] Real-time notifications (WebSockets)
- [ ] Advanced analytics dashboards

### Planned 📋
- [ ] Webhook system for integrations
- [ ] GraphQL API layer
- [ ] Mobile SDK (React Native)
- [ ] Advanced reporting (PDF/Excel export)

## Support

For API support or bug reports:
- Email: api@tesotunes.com
- GitHub Issues: https://github.com/tesotunes/admin-api/issues
- Documentation: http://beta.test/api-docs.html

## License

Proprietary - TésoTunes Platform
Copyright © 2026 TésoTunes. All rights reserved.
