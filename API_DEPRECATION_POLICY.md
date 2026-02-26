# API Deprecation Policy

## Overview

TesoTunes follows a structured deprecation process to ensure API consumers have
adequate time to migrate to new endpoints.

## Deprecation Timeline

| Phase | Duration | Action |
|-------|----------|--------|
| **Announcement** | Day 0 | `Deprecation: true` header added to endpoint |
| **Migration Period** | 90 days | Both old and new endpoints operate simultaneously |
| **Sunset** | Day 90 | Old endpoint returns `410 Gone` |
| **Removal** | Day 120 | Old endpoint removed from codebase |

## HTTP Headers

Deprecated endpoints include these standard headers:

```
Deprecation: true
Sunset: Sat, 01 Jun 2026 00:00:00 GMT
Link: </api/v2/new-endpoint>; rel="successor-version"
```

## How to Deprecate an Endpoint

### 1. Add the `deprecated` middleware to the route:

```php
Route::get('/v1/songs', [SongController::class, 'index'])
    ->middleware('deprecated:2026-06-01,/api/v2/songs');
```

Parameters:
- First: Sunset date (YYYY-MM-DD)
- Second: Successor URL (optional)

### 2. Document in CHANGELOG.md

Add an entry under `### Deprecated` in the current unreleased section.

### 3. Notify consumers

- Update API documentation with deprecation notice
- Send notification to registered API consumers (if applicable)

## Versioning Strategy

- Current API version: `v1` (returned via `X-API-Version` header)
- New major versions use URL prefix: `/api/v2/...`
- Minor/patch changes are backwards-compatible within the same version

## Currently Deprecated Endpoints

_None at this time._
