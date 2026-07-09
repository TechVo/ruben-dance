# M15 — GDPR & compliance features

**Depends on:** M09, M13
**Spec:** §6 (all), §6.1 implementation requirements
**Goal:** The technical side of compliance is implemented and demonstrable —
export, erasure, retention, and the consumer-law details on the flows.

## Tasks

- **WP core privacy integration**: register exporters + erasers
  (`wp_privacy_personal_data_exporters` / `_erasers`) covering user meta (phone,
  locale, consents) and all `rd_` tables; erasure **anonymizes** enrollments
  (participant/partner names → "anonymizováno", user link severed, price/dates
  kept) and purges the email log for that user.
- **Retention cron** (monthly, WP-Cron): per §6.1 — anonymize customers inactive
  N years (setting, default 3, "inactive" = no non-cancelled enrollment since),
  purge email log > 1 year, purge cancelled-unpaid enrollments > 1 year; every
  run logged (what, how many); dry-run WP-CLI command `wp rd retention --dry-run`.
- Consent audit: registration/enrollment store T&C-version + timestamp alongside
  the marketing consent captured in M07/M08 — verify and backfill gaps.
- §6.3 sweep of the front end: enroll button wording with payment obligation
  (both languages), T&C + privacy links present at registration, enrollment,
  footer; confirmation email links T&C (M13 template check).
- Cookie audit: crawl the front end (anonymous + logged-in) and enumerate cookies
  set; must be only WP session/auth + language — document the result (the "no
  banner needed" claim in §6.2 must be *demonstrated*, not assumed).
- Placeholder pages for privacy policy + T&C (CS/EN, lorem structure) — real
  texts are the lawyer's (pre-launch checklist).

## Acceptance criteria

- [ ] WP admin → Export Personal Data for a seeded customer yields their profile,
      consents and enrollments
- [ ] Erasure request → user anonymized, enrollments retain price/dates without
      any name, email log purged; roster shows "anonymizováno"
- [ ] Retention dry-run reports correct candidates on seed data aged artificially;
      real run performs and logs them
- [ ] Cookie audit documented in the repo (`docs/cookie-audit.md`) and clean
- [ ] Enroll button wording correct in CS + EN

## Verification

Run export + erasure end-to-end for one seeded customer and read the results.
Cookie audit via browser devtools on every public page type.

## Out of scope

Legal text content (lawyer, pre-launch checklist); accessibility polish (M16).
