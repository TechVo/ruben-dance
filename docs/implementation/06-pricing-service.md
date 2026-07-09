# M06 — Pricing & enrollment services (pure logic)

**Depends on:** M02
**Spec:** §3.2 discount model, F3 (computed price), enrollment lifecycle
**Goal:** All money- and state-logic as pure, heavily unit-tested services — before
any UI can bake bugs into it. This is the highest-risk logic in the project;
tests here are not optional.

## Tasks

- **PricingService**: `final = price − discount_early (if enrollment date ≤ early_until)
  − discount_pair (if partner stated)`; never below 0; builds the human-readable
  `discount_note` ("early-bird −300, partner −200"); server-side only — UI price
  displays are advisory.
- **VariableSymbolGenerator**: `{year}{enrollment_id}` zero-padded, ≤ 10 digits,
  uniqueness guaranteed by construction; unit-test the format and year rollover.
- **DueDateCalculator**: `created_at` + N days, N from a plugin setting (default 7).
- **EnrollmentService**: create-enrollment orchestration — validates term is `open`,
  computes price via PricingService, sets `over_capacity` flag when active
  (non-cancelled) enrollments ≥ capacity, enforces the duplicate rule
  ((`term_id`,`user_id`,`participant_name`) — rely on the DB unique key and
  translate the violation into a friendly error), status transitions
  (`confirmed ⇄ paid`, → `cancelled`) with `paid_at`/`paid_marked_by` handling;
  illegal transitions throw.
- Settings page (minimal): due-date days, admin notification email — stored as
  options, used by services.

## Acceptance criteria

- [ ] PricingService test matrix: no discounts / early only / pair only / both /
      boundary date (= `early_until` counts as early) / discounts exceeding price
- [ ] Over-capacity flag set exactly at the boundary (capacity = active enrollments),
      cancelled enrollments don't count
- [ ] Duplicate enrollment (same user, same term, same participant) rejected;
      same user + different participant accepted
- [ ] Status transition table tested incl. illegal moves (cancelled → paid throws)
- [ ] All services usable without WordPress loaded where feasible (plain PHPUnit)

## Verification

`composer test` — this milestone is verified by its test suite; there is no UI yet.
Mutation-style spot check: flip one boundary condition, confirm a test fails.

## Out of scope

Any UI, emails, REST.
