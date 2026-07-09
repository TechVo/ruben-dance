# M16 — Launch prep

**Depends on:** all previous
**Spec:** §7 (remaining open items), §5 Security layers 3–4, F17, §6.4
**Goal:** The plugin runs on the production host (wordpress.com Business, pending
owner approval) and the pre-launch checklist is executed, not just read.

## Tasks

- **Voucher info page + inquiry form (F17)**: WP page CS/EN + small form
  (name, email, message → owners' email, honeypot-protected). Last feature bit.
- **i18n completeness**: generate `.pot`, complete the EN `.po/.mo`; `wp rd`
  CLI or script check for untranslated strings; verify no hardcoded Czech in
  templates.
- **Accessibility pass (§6.4)** on the enrollment path: labels, error messages,
  keyboard-only walkthrough catalog → enroll → account; calendar list-view
  fallback link.
- **wordpress.com deployment**: verify plan constraints from §7 item 1 (Polylang
  installable, SMTP story, secrets — IBAN/account settings live in options, so
  no `wp-config.php` need); set up GitHub deployment of the plugin directory;
  staging site first.
- **Security hardening (§5 layers 3–4)** on production: 2FA for all owner/admin
  accounts, `DISALLOW_FILE_EDIT` (or platform equivalent), XML-RPC off,
  auto-updates, backups verified **including one tested restore on staging**,
  HTTPS/HSTS confirmed.
- **Production configuration**: SMTP provider connected, real IBAN + account
  number, due-date days, admin notification address, email templates reviewed by
  the owners (send each to them).
- **Owner acceptance run** on staging: an owner enters a real term, a test
  customer enrolls, owner marks paid from the roster, all emails arrive — the
  full loop from §1 performed by the actual users.
- Content items handed off (not ours to write): legal texts from the lawyer,
  EN course descriptions, migrated/new pages (§7 items 2–5).

## Acceptance criteria

- [ ] Staging on wordpress.com runs the full M08→M11 loop without code changes
- [ ] Owner completes the acceptance run without developer help
- [ ] Hardening checklist items each verifiably done (screenshot/log evidence
      collected in `docs/launch-checklist.md`)
- [ ] Backup restore actually performed once on staging
- [ ] EN locale shows no Czech leakage on the enrollment path
- [ ] QR scan works against the real production IBAN (repeat M14's test)

## Verification

The owner acceptance run *is* the verification. Launch = DNS/site switch only
after every box above is checked in `docs/launch-checklist.md`.

## Out of scope

v2 backlog (waitlist, self-cancellation, online payments, voucher module,
attendance, reporting, cron reminders) — collect real-usage feedback first.
