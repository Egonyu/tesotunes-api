# Subscription entitlements

TesoTunes subscriptions are data-driven. Plan names, prices, marketing points,
limits, commissions, and feature access are stored on `subscription_plans` and
can be changed from **Admin → Subscriptions → Plans** without a deployment.

## Initial packages

| Plan | Monthly | Yearly | Position |
|---|---:|---:|---|
| Emong | UGX 3,000 | UGX 30,000 | Personal |
| Eris | UGX 5,000 | UGX 50,000 | Professional |
| Engatuny | UGX 15,000 | UGX 150,000 | Organization |

Free remains the database-backed default access level.

## Editing rules

The admin editor accepts one entitlement per line:

```text
streaming.ad_free = true
creator.uploads_per_month = 10
finance.withdrawal_minimum_ugx = 25000
support.level = community
```

Values may be booleans, numbers, text, `null`, or `unlimited`. Internally,
unlimited numeric quotas use `-1`; `null` is reserved for rules where absence of
a finite cap is meaningful.

Audio quality, downloads, uploads, ads, offline access, and event fees have
dedicated controls in the same form. The editor automatically keeps their
legacy columns and entitlement keys synchronized, so those keys should not be
duplicated in the rule list.

Marketing copy belongs in **Visible Value Points**. Enforcement belongs in
**Entitlement Rules**. Keeping them separate prevents a benefit from appearing
on the pricing page without a matching system rule.

## Enforcement

- Backend code reads values with `getSubscriptionEntitlement()` and boolean
  access with `hasSubscriptionEntitlement()`.
- Routes can use `subscription.entitlement:<key>` after authentication.
- Frontend code reads values with `useEntitlement()` or
  `useEntitlementLimit()` and boolean access with `useCanAccess()`.
- Legacy plan columns remain populated while modules migrate to entitlement
  keys, avoiding a flag-day release.

The first fully connected rules cover ads, audio quality, offline access,
downloads, creator uploads, withdrawal minimums, and withdrawal fee rates.
Other rules are already present for events, stores, promotions, fan benefits,
reports, organizations, support, rewards, and distribution, ready for each
module to enforce as it evolves.
