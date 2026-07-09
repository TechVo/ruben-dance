# M13 — Email notifications

**Depends on:** M08 (M11/M12 hooks exist as stubs to replace)
**Spec:** F14, E1–E7, §6.1 (log retention), §5 Multilingual
**Goal:** Every Mailer stub becomes a real, logged, bilingual email. After this
milestone the school communicates automatically.

## Tasks

- **Template system**: per-type, per-language (CS + EN) subject/body stored as
  options with defaults; placeholder rendering (`{first_name}`, `{course}`,
  `{term_schedule}`, `{price}`, `{account_number}`, `{variable_symbol}`,
  `{due_date}`, `{participant}`, …); settings screen to edit them with a
  placeholder legend and a "send test to me" button.
- Language selection per recipient: customer's stored locale; admin notifications
  (E3) always CS.
- Implement all types: E1 verification (replaces M07 plain text), E2 enrollment +
  payment instructions, E3 admin notification, E4 payment confirmation (from the
  M11 toggle prompt), E5 lesson cancelled/moved — admin-confirmed send to a
  term's active enrollees (wire the M05 stub; preview + confirm screen before
  sending), E6 enrollment cancelled, E7 payment reminder (the M12 button).
- **Email log**: every send → `wp_rd_email_log` (type, recipient, subject,
  status, enrollment/user refs — no full bodies, §6.1); visible in the
  enrollment detail (M11) and a simple log screen with search.
- Failures: `wp_mail` failure logged with status `failed`, surfaced as an admin
  notice on the triggering action — never silently lost.
- Deliverability note in README: production requires an SMTP plugin/provider
  (§4.4); wp-env uses the mail catcher.

## Acceptance criteria

- [ ] Each of E1–E7 fires from its real trigger, in the recipient's language,
      placeholders fully resolved (no `{...}` leftovers)
- [ ] E5 flow: cancel a lesson → preview → confirm → all active enrollees of that
      term (only that term) receive it; cancelled enrollments don't
- [ ] Unmark-paid sends nothing (regression check on M11 rule)
- [ ] Every send visible in the log and in the enrollment detail
- [ ] Template edit in settings changes the next send; test-send button works
- [ ] Unit tests: placeholder renderer (missing values, HTML escaping)

## Verification

Trigger every email type manually in wp-env and read all of them in the mail
catcher, both languages. Check the log table matches what was received.

## Out of scope

QR code embedding (M14 — E2/E7 get it there), automated cron reminders (v2).
