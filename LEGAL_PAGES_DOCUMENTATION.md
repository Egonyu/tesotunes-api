# TesoTunes Legal Pages System Documentation

## Overview

TesoTunes now has a complete legal documents management system that allows administrators to create, edit, publish, and manage legal pages such as Terms of Service, Privacy Policy, Artist Agreements, and more.

**System Features:**
- ✅ Drag-and-drop admin interface for managing legal documents
- ✅ Version control for all legal documents
- ✅ User acceptance tracking
- ✅ Per-role legal requirements (all users, artists only, etc.)
- ✅ Scheduled publication (effective dates)
- ✅ Archive and soft-delete capabilities
- ✅ Public viewing pages for users
- ✅ Artist-specific agreements with revenue sharing terms

---

## Database Schema

### `legal_pages` Table
Stores the current version of legal documents.

```sql
CREATE TABLE legal_pages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  uuid CHAR(36) UNIQUE,
  slug VARCHAR(255) UNIQUE INDEX,
  title VARCHAR(255),
  subtitle VARCHAR(255) NULLABLE,
  type VARCHAR(255) INDEX,
  description TEXT NULLABLE,
  content LONGTEXT,
  status VARCHAR(50) DEFAULT 'draft',
  version INT DEFAULT 1,
  applies_to VARCHAR(50) DEFAULT 'all',
  metadata JSON NULLABLE,
  requires_acceptance BOOLEAN DEFAULT FALSE,
  effective_date TIMESTAMP NULLABLE,
  sunset_date TIMESTAMP NULLABLE,
  created_by INT NULLABLE,
  updated_by INT NULLABLE,
  published_by INT NULLABLE,
  published_at TIMESTAMP NULLABLE,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  deleted_at TIMESTAMP NULLABLE
);
```

**Fields:**
- `slug`: URL-friendly identifier (e.g., 'terms-of-service')
- `type`: Document category (terms, privacy, artist_agreement, etc.)
- `status`: 'draft', 'published', or 'archived'
- `version`: Auto-incremented with each change
- `applies_to`: 'all', 'users', 'artists', 'labels', 'event_organizers'
- `requires_acceptance`: Whether users must accept this document
- `effective_date`: When this version becomes active
- `sunset_date`: When this version expires

### `legal_page_acceptances` Table
Tracks user acceptance of legal documents.

```sql
CREATE TABLE legal_page_acceptances (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT FOREIGN KEY,
  legal_page_id INT FOREIGN KEY,
  version INT DEFAULT 1,
  ip_address VARCHAR(45) NULLABLE,
  user_agent TEXT NULLABLE,
  accepted_at TIMESTAMP,
  created_at TIMESTAMP,
  UNIQUE(user_id, legal_page_id, version)
);
```

### `legal_page_versions` Table
Complete audit trail of all document versions.

```sql
CREATE TABLE legal_page_versions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  legal_page_id INT FOREIGN KEY,
  version_number INT,
  title VARCHAR(255),
  content LONGTEXT,
  changes JSON NULLABLE,
  changelog TEXT NULLABLE,
  created_by INT FOREIGN KEY,
  created_at TIMESTAMP,
  UNIQUE(legal_page_id, version_number)
);
```

---

## API Endpoints

### Public Endpoints

#### List All Published Legal Pages
```
GET /api/legal-pages
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Terms of Service",
      "slug": "terms-of-service",
      "type": "terms",
      "applies_to": "all",
      "requires_acceptance": true
    }
  ]
}
```

#### Get Specific Legal Page
```
GET /api/legal-pages/{slug}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Terms of Service",
    "slug": "terms-of-service",
    "type": "terms",
    "content": "<h1>Terms of Service</h1>...",
    "version": 1,
    "effective_date": "2026-04-15T00:00:00Z",
    "requires_acceptance": true,
    "user_accepted": false
  }
}
```

#### Accept Legal Document (Authenticated)
```
POST /api/legal-pages/{id}/accept
```

**Request:**
```json
{
  "id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "You have accepted the Terms of Service",
  "data": {
    "page_id": 1,
    "version": 1,
    "accepted_at": "2026-04-15T12:34:56Z"
  }
}
```

#### Check User Acceptance Status (Authenticated)
```
GET /api/legal-pages/check-acceptance
```

**Response:**
```json
{
  "success": true,
  "data": {
    "all_accepted": false,
    "pages": {
      "terms-of-service": {
        "accepted": true,
        "version": 1,
        "title": "Terms of Service"
      },
      "privacy-policy": {
        "accepted": false,
        "version": 1,
        "title": "Privacy Policy"
      }
    }
  }
}
```

### Admin Endpoints (Requires admin/super_admin role)

#### List Legal Pages with Filters
```
GET /api/admin/legal-pages
Query Parameters:
  - type: Filter by document type
  - status: Filter by status (draft, published, archived)
  - applies_to: Filter by audience
  - search: Search by title or slug
  - per_page: Pagination (default: 15)
```

#### Create New Legal Page
```
POST /api/admin/legal-pages
Content-Type: application/json

{
  "title": "Terms of Service",
  "subtitle": "User Agreement",
  "type": "terms",
  "description": "Platform usage terms",
  "content": "<h1>Terms</h1>...",
  "applies_to": "all",
  "requires_acceptance": true,
  "effective_date": "2026-04-15T00:00:00Z",
  "metadata": {}
}
```

#### Update Legal Page
```
PUT /api/admin/legal-pages/{id}
Content-Type: application/json

{
  "title": "Updated Title",
  "content": "<h1>Updated Content</h1>...",
  "create_version": true,
  "changelog": "Updated section 3 for clarity"
}
```

#### Publish Draft Document
```
POST /api/admin/legal-pages/{id}/publish
Content-Type: application/json

{
  "effective_date": "2026-04-20T00:00:00Z"
}
```

#### Archive Published Document
```
POST /api/admin/legal-pages/{id}/archive
```

#### Delete Document
```
DELETE /api/admin/legal-pages/{id}
```

#### Get Version History
```
GET /api/admin/legal-pages/{id}/versions
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "version_number": 2,
      "title": "Terms of Service",
      "changelog": "Updated section 3",
      "created_at": "2026-04-15T10:30:00Z",
      "creator": "Admin User"
    }
  ]
}
```

#### Get User Acceptances
```
GET /api/admin/legal-pages/{id}/acceptances
Query Parameters:
  - per_page: Pagination (default: 20)
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "user_id": 123,
      "email": "user@example.com",
      "version": 1,
      "accepted_at": "2026-04-15T12:34:56Z",
      "ip_address": "192.168.1.1"
    }
  ],
  "meta": {
    "total": 5000,
    "acceptance_rate": 95.32
  }
}
```

---

## Setup Instructions

### 1. Run Database Migration

```bash
php artisan migrate
```

This creates three tables:
- `legal_pages`
- `legal_page_acceptances`
- `legal_page_versions`

### 2. Seed Initial Legal Documents

```bash
php artisan db:seed --class=LegalPagesSeeder
```

This creates these default documents:
1. **Terms of Service** - Applies to all users, requires acceptance
2. **Artist Agreement** - Artist-specific terms with revenue sharing
3. **Privacy Policy** - Data collection and usage
4. **Acceptable Use Policy** - Prohibited activities
5. **Payment Terms** - Payment, billing, and payouts
6. **Copyright & DMCA Policy** - IP and takedown procedures
7. **Cookie Policy** - Cookie usage and tracking
8. **Accessibility Statement** - Accessibility compliance

### 3. Access Admin Panel

Navigate to `/admin/legal-pages` in your Next.js application to manage documents.

### 4. Publish Documents

In the admin panel:
1. Click "Create/Edit" tab
2. Review and customize each document
3. Set "Requires User Acceptance" as needed
4. Click "Publish Document"
5. Set effective date (optional)

---

## Frontend Integration

### Display Legal Pages Publicly

Users can view all published legal documents at `/legal`

```
/legal                              # Main legal page viewer
```

### Check User Acceptance

Use the hook to check if a user has accepted required policies:

```typescript
import { useLegalAcceptance } from '@/hooks/useLegal';

export function MyComponent() {
  const { allAccepted, pages, isChecking } = useLegalAcceptance();

  if (isChecking) return <div>Loading...</div>;

  if (!allAccepted) {
    return <div>Please accept our updated policies</div>;
  }

  return <div>Welcome!</div>;
}
```

### Accept Policy

```typescript
import { useLegalAcceptance } from '@/hooks/useLegal';

export function AcceptPoliciesModal() {
  const { acceptPolicy, isAccepting } = useLegalAcceptance();

  return (
    <button
      onClick={() => acceptPolicy(1)}
      disabled={isAccepting}
    >
      I Accept
    </button>
  );
}
```

---

## Document Types

| Type | Slug | Applies To | Default Required? |
|------|------|-----------|------------------|
| Terms of Service | `terms` | All | Yes |
| Privacy Policy | `privacy` | All | No |
| Acceptable Use | `acceptable_use` | All | Yes |
| Artist Agreement | `artist_agreement` | Artists | Yes |
| Payment Terms | `payment_terms` | All | Yes |
| Copyright Policy | `copyright` | All | No |
| Cookie Policy | `cookies` | All | No |
| DMCA Policy | `dmca` | All | No |
| Disclaimer | `disclaimer` | All | No |
| Accessibility | `accessibility` | All | No |

---

## Security Considerations

### Admin Access
- All endpoints require `auth:sanctum` middleware
- Admin endpoints require `role:admin,super_admin` middleware
- Uses `admin.exceptions` middleware for additional security

### User Data Protection
- IP addresses and user agents logged for acceptance tracking
- Signed URLs can be used for sensitive document links
- Rate limiting applied to prevent abuse

### Audit Trail
- All changes tracked with `created_by`, `updated_by`, `published_by` user IDs
- Version history preserved indefinitely
- Soft deletes maintain data integrity

---

## Best Practices

### Document Naming
- Use clear, descriptive slugs
- Keep titles concise but comprehensive
- Add subtitles for complex documents

### Version Management
- Always create new versions for substantial changes
- Use meaningful changelog messages
- Set effective dates for important updates
- Archive old versions after sunset period

### User Communication
- Only require acceptance for essential documents
- Consider user role when setting `applies_to`
- Provide clear acceptance language
- Track acceptance rates in admin dashboard

### Content Guidelines
- Use HTML for rich formatting
- Include table of contents for long documents
- Add help links when explaining technical terms
- Ensure mobile-friendly layout

---

## Common Tasks

### Update Terms of Service

1. Go to `/admin/legal-pages`
2. Find "Terms of Service" document
3. Click Edit
4. Modify content in the editor
5. Check "Create Version" to track changes
6. Add changelog message
7. Click "Update Document"
8. Click "Publish" to activate
9. Set "Effective Date" for when change takes effect

### Track Policy Acceptance

1. Go to `/admin/legal-pages`
2. Click on a published document
3. View acceptance statistics
4. See individual user acceptance records
5. Export data if needed

### Archive Old Policies

1. Go to `/admin/legal-pages`
2. Click "Archive" button on published document
3. Document remains in system but marked as archived
4. Users no longer required to accept archived version

---

## Troubleshooting

### Legal pages not appearing

**Problem:** Published documents don't show up publicly

**Solution:**
1. Verify `status` is 'published'
2. Check `effective_date` is in the past (or NULL)
3. Check `sunset_date` is in the future (or NULL)
4. Verify `deleted_at` is NULL
5. Ensure user role matches `applies_to`

### Users can't accept policies

**Problem:** Accept button not working

**Solution:**
1. Verify user is authenticated
2. Check API endpoint is accessible
3. Verify `requires_acceptance` is true
4. Check for CORS issues
5. Review browser console for errors

### Acceptance not being tracked

**Problem:** Accept button works but acceptance not recorded

**Solution:**
1. Verify `legal_page_acceptances` table has entries
2. Check user ID is correct
3. Verify version number matches
4. Ensure IP address is captured (may be proxied)

---

## API Rate Limits

- Public GET endpoints: 100 requests/minute per IP
- Admin endpoints: 30 requests/minute per user
- Accept endpoint: 10 requests/minute per user

---

## Future Enhancements

- [ ] Multi-language support for legal documents
- [ ] A/B testing for different legal text
- [ ] Email notifications for policy updates
- [ ] Custom acceptance forms per document type
- [ ] Document comparison tool (show what changed)
- [ ] E-signature support for formal agreements
- [ ] Scheduled auto-approval after X days
- [ ] Integration with third-party legal document services

---

## Support

For issues or questions:
- Email: legal@tesotunes.com
- Admin Panel: `/admin/legal-pages`
- API Docs: See `/api/admin/legal-pages` OpenAPI spec
