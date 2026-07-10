# Implementation plan — overview

Source of truth: [`docs/requirements.md`](../requirements.md). Milestones reference its
sections (§) and functional requirements (F-numbers). If a milestone contradicts the
spec, the spec wins — fix the milestone file.

## How to work through this plan

- **One milestone per session/PR.** Each ends in a working, verifiable state.
- **Verify before moving on** — every milestone has a Verification section; run it.
  Never start milestone N+1 on top of an unverified N.
- Definition of done, for every milestone:
  - [ ] Acceptance criteria all pass
  - [ ] `composer phpcs` clean (WordPress coding standard)
  - [ ] `composer test` green (where the milestone adds testable logic)
  - [ ] Manually exercised in the local site (wp-env), not just "code looks right"
- **Conventions:** text domain `ruben-dance`; every user-facing string through
  `__()`/`esc_html__()` from day one (English source strings per WP convention,
  **CS via `cs_CZ` `.po`** — completed in M16; legally mandated CS wording like
  the §6.3 button may branch by language explicitly);
  all SQL via `$wpdb->prepare()`; every admin action nonce + `rd_manage`
  capability checked; every front-end write nonce + ownership checked.

## Milestone order & dependencies

| # | Milestone | Depends on | Spec refs |
|---|---|---|---|
| 01 | [Dev environment & tooling](01-dev-environment.md) | — | §5 |
| 02 | [Plugin skeleton, schema, roles](02-plugin-skeleton-schema.md) | 01 | §3.2, §5 |
| 03 | [Locations admin](03-locations-admin.md) | 02 | F13 |
| 04 | [Course CPT + multilingual base](04-course-cpt-multilingual.md) | 02 | §3.1, §5 Multilingual |
| 05 | [Terms & lessons admin](05-terms-lessons-admin.md) | 03, 04 | §3.2, F9, F10 |
| 06 | [Pricing & enrollment services](06-pricing-service.md) | 02 | §3.2, F3 |
| 07 | [Customer registration & login](07-auth-registration.md) | 02 | F4, §6.1 |
| 08 | [Enrollment flow (public)](08-enrollment-flow.md) | 05, 06, 07 | F3 |
| 09 | [My account](09-my-account.md) | 08 | F5, F6, F7 |
| 10 | [Public calendar](10-calendar.md) | 05 | F2 |
| 11 | [Admin term roster](11-admin-roster.md) | 08 | F11a |
| 12 | [Admin enrollment ops & customer detail](12-admin-enrollment-ops.md) | 11 | F11b, F12 |
| 13 | [Email notifications](13-emails.md) | 08 | F14, E1–E7 |
| 14 | [QR payment code](14-qr-payment.md) | 13 | F16 |
| 15 | [GDPR & compliance](15-compliance-gdpr.md) | 09, 13 | §6 |
| 16 | [Launch prep](16-launch.md) | all | §7, F17 |

10 (calendar) is independent of 08–09 and can be done any time after 05.

## Test data

Milestone 01 creates a seed WP-CLI command (`wp rd seed`) that later milestones
extend. Screens must always be verified against seeded data (several courses, terms
with different discounts/capacities, enrollments in various states), never against
an empty database.
