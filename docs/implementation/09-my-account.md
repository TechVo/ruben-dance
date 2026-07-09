# M09 — My account

**Depends on:** M08
**Spec:** F5, F6, F7
**Goal:** Logged-in customers see their enrollments, payment status and upcoming
lessons, and can edit their profile.

## Tasks

- `[rd_account]` page (seeded, CS + EN), tabs/sections:
  - **My enrollments (F5)**: per enrollment — course, term schedule, location,
    participant name, price, status badge (awaiting payment / paid / cancelled);
    unpaid: payment instructions block (amount, account, VS, due date; QR slot
    added in M14) + "cancellation: contact us" note (no self-cancel, per spec).
  - **My schedule (F6)**: upcoming lessons of active enrollments, date-sorted,
    cancelled/moved lessons visibly marked.
  - **Profile (F7)**: name, phone, email (re-verify on change), password,
    preferred language, marketing consent toggle.
- Ownership enforced in the repository layer (`user_id = get_current_user_id()`),
  per §5 — not just in the controller.
- Anonymous access to the page → login form.

## Acceptance criteria

- [ ] Customer sees exactly their own enrollments (incl. children participants),
      never anyone else's — verify by URL/id tampering
- [ ] Unpaid enrollment shows correct payment instructions; paid shows paid badge
- [ ] Schedule reflects a lesson the admin cancelled in M05's screen
- [ ] Email change requires re-verification before taking effect
- [ ] Marketing consent toggle updates the stored consent + timestamp
- [ ] Works in CS and EN

## Verification

Manual pass as two different seeded customers; tamper with enrollment ids in any
form/URL. Integration test for the ownership check.

## Out of scope

QR rendering (M14), emails (M13).
