# M08 — Enrollment flow (public)

**Depends on:** M05, M06, M07
**Spec:** F1, F3
**Goal:** The end-to-end money path: a visitor finds a course and enrolls; the
enrollment lands correctly priced in the database. After this milestone the
product minimally *works*.

## Tasks

- **Catalog** `[rd_catalog]`: open terms grouped by course, filters (style, level,
  location, weekday), localized; term row shows day/time/place/price, early-bird
  price + deadline when configured, "full" badge when at capacity (still
  enrollable, F3.1). Course detail (CPT template/filter) lists its terms with
  Enroll buttons.
- **Enrollment form** `[rd_enroll]` (term id in URL): participant ("me" /
  "someone else" + name), role (solo/leader/follower — shown per course
  configuration; add a per-course "roles relevant" flag on the CPT), partner
  name, note, required T&C checkbox, optional marketing checkbox (skip if already
  consented). Live computed price display (advisory; server recomputes).
- Submit → EnrollmentService (M06): logged-in users only — the form embeds the
  register/login step (M07) for anonymous visitors and returns to the filled form.
- Button label per §6.3: "Závazně přihlásit s povinností platby" / EN equivalent.
- Confirmation page: summary + payment instructions (amount, account from
  settings, variable symbol, due date). Email E2 fires through the Mailer stub
  (full template M13).
- Over-capacity notice per F3.1 before the form when the term is full.
- Bot protection as in M07; nonce + server-side revalidation of everything
  (term still `open`, price recomputed — never trust the form).
- Extend `wp rd seed`: ~15 enrollments across terms (paid/unpaid/cancelled/
  over-capacity/child-participant mix) — this becomes the admin milestones' fixture.

## Acceptance criteria

- [ ] Anonymous visitor completes: catalog → course → enroll → register → confirm;
      enrollment row correct (price, discount_note, VS, due_date, status `confirmed`)
- [ ] Early-bird + partner discount combine per M06 rules; tampering with the
      submitted price has no effect
- [ ] Parent enrolls two children into the same term (two rows); enrolling the same
      child twice is rejected with a friendly message
- [ ] Full term: badge shown, enrollment created with `over_capacity = 1`
- [ ] Whole flow passes in CS and EN
- [ ] Closed/draft/cancelled term: no enroll button, direct URL access refused

## Verification

Manual end-to-end in both languages, plus DB row inspection after each scenario
above. Integration test for the submit handler covering the acceptance scenarios
(wp-phpunit).

## Out of scope

My account (M09), calendar (M10), real email content (M13), QR (M14).
