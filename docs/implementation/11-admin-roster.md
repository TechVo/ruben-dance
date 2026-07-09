# M11 — Admin term roster

**Depends on:** M08 (needs seeded enrollments)
**Spec:** F11a — the owners' main daily screen
**Goal:** Owner opens a term and manages who's in it and who has paid, with
one-click mark/unmark paid.

## Tasks

- Roster screen (term picker → roster): one row per enrollment — participant
  (fallback account holder), email, phone, role, price, badges (over-capacity,
  overdue = unpaid past `due_date`, cancelled), note indicator, **paid toggle**.
- Paid toggle (AJAX, nonce + `rd_manage`):
  - mark → EnrollmentService transition to `paid` (`paid_at`, `paid_marked_by`),
    then a small prompt: send E4 confirmation email? (default yes; Mailer stub
    until M13);
  - unmark → back to `confirmed`, both fields cleared, **no email ever**.
- Header stats: paid/total vs. capacity, leader/follower/solo counts, sum
  collected vs. expected.
- Row click → enrollment detail: all fields, price/discount breakdown, notes,
  email history for this enrollment from `wp_rd_email_log`; link to customer
  detail (built in M12 — plain link now).
- CSV export of the roster (attendance sheet: participant, contact, role, paid).

## Acceptance criteria

- [ ] Toggle updates the row + header stats without page reload; DB state correct
      (`paid_at`, `paid_marked_by` set/cleared)
- [ ] Unmark never triggers an email path
- [ ] Overdue badge appears exactly when unpaid and `due_date` past
- [ ] Roles breakdown matches seeded data; cancelled enrollments excluded from sums
- [ ] CSV opens in Excel/LibreOffice with correct diacritics (UTF-8 BOM)
- [ ] All actions rejected for non-`rd_manage` users (test with subscriber session)

## Verification

As `rd_manager` against seeded data: mark/unmark several rows, cross-check DB;
export CSV and open it. Forge the AJAX call without nonce → rejected.

## Out of scope

Cross-term list, move/price-edit/cancel actions, manual enrollment (all M12);
real E4 email (M13).
