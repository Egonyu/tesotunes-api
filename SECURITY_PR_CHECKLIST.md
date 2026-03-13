# Security PR Checklist

**Purpose:** keep API security review lightweight, consistent, and hard to skip during normal delivery work.

## When To Use It

Use this checklist for any pull request that changes:

- authentication or session behavior
- API routes or middleware
- role checks, permissions, or admin/artist access
- webhooks, callbacks, or signed endpoints
- rate limiting
- exception handling, error payloads, or logging
- uploads, payments, payouts, or other sensitive mutations

## Author Checklist

- Confirm the route access level is still correct: public, authenticated, artist, admin, webhook, or legacy compatibility.
- Confirm mutating endpoints have the right protection: `auth:sanctum`, role middleware, throttling, or signature validation.
- Confirm sensitive controllers still perform authorization defensively and do not rely only on route grouping.
- Confirm browser clients are not gaining new token exposure or bypassing the server-led auth model.
- Confirm production API responses do not expose stack traces, SQL, secrets, or internal identifiers unnecessarily.
- Confirm failures that matter operationally are logged in a useful way.
- Add or update regression tests for the changed security boundary.
- Update the route/governance docs when the access model changes.

## Reviewer Checklist

- Re-check the changed route definitions, not just controller code.
- Look for privilege escalation paths, especially ID-based mutations and admin/artist surfaces.
- Verify public write endpoints are intentional exceptions and are rate limited.
- Verify webhook endpoints have both validation and replay/idempotency coverage where appropriate.
- Verify the change aligns with [API access matrix](./API_ACCESS_MATRIX.md) and [privileged route review log](./API_PRIVILEGED_ROUTE_REVIEW_LOG.md).

## Related Project Docs

- [API Access Matrix](./API_ACCESS_MATRIX.md)
- [API Privileged Route Review Log](./API_PRIVILEGED_ROUTE_REVIEW_LOG.md)
- [API Deprecation Policy](./API_DEPRECATION_POLICY.md)
