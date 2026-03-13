## Summary

- What changed:
- Why it changed:

## Validation

- [ ] Relevant automated tests were run locally
- [ ] Any skipped tests or known gaps are called out below

## Security Review

- [ ] I reviewed auth changes for guard, middleware, and token/session impact
- [ ] I reviewed any new or changed mutating routes for required auth, role checks, throttling, or signed/webhook validation
- [ ] I reviewed controller/service changes for privilege escalation or direct object access risks
- [ ] I confirmed API errors do not leak secrets, stack traces, raw SQL, or internal-only details in production responses
- [ ] I confirmed logs capture meaningful security or auth failures when behavior changed
- [ ] I updated or added regression tests for auth, permissions, rate limiting, webhooks, or error handling where applicable
- [ ] I checked whether this change affects any documented route inventory or review log and updated docs if needed

## Reviewer Focus

- Areas that need extra scrutiny:
- Risk of regression:

## Notes

- Deployment notes:
- Follow-up work:
