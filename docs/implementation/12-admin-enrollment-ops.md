# M12 — Admin enrollment ops & customer detail

**Depends on:** M11
**Spec:** F11b, F12
**Goal:** Everything else the owners do with enrollments: find anyone, fix
anything, enroll by phone — plus the "who is this person calling me" screen.

## Tasks

- **Enrollment actions** (on roster rows and detail): cancel; edit role/partner
  note; **edit price** (requires a reason string appended to `admin_note`;
  `discount_note` preserved); **move to another term** (recompute nothing —
  price travels unchanged, admin adjusts manually if needed; over-capacity flag
  re-evaluated on the target term); add admin note; send payment reminder
  (E7 — Mailer stub until M13, logs intent).
- **Cross-term list** (F11b): all enrollments, `WP_List_Table` with filters
  (term, status, paid/unpaid, overdue, over-capacity), search across account
  name/email/participant name; overdue-unpaid rows highlighted.
- **Manual enrollment** (phone orders): admin picks customer (search existing /
  create minimal account without verification email), term, participant, role,
  manual price allowed; goes through EnrollmentService (VS, due date generated).
- **Customer detail (F12)**: searchable customer list; detail — contact data,
  locale, marketing consent, enrollment history with paid status, links to rosters.

## Acceptance criteria

- [ ] Move between terms: enrollment appears in target roster, VS unchanged,
      history traceable via admin note
- [ ] Price edit without a reason is rejected; reason lands in `admin_note`
- [ ] Manual enrollment for a brand-new customer creates account + enrollment;
      duplicate rule still enforced
- [ ] Search finds a child by participant name and leads to the paying parent
- [ ] Filters combine correctly (e.g. unpaid + overdue + specific term)
- [ ] Every action nonce- and capability-checked (spot-check by forged requests)

## Verification

Scenario script against seed data: phone-enroll a new customer → move them once →
edit price with reason → cancel → verify every step in the DB and in the customer
detail history.

## Out of scope

Emails actually sending (M13); reporting/dashboards (v2).
