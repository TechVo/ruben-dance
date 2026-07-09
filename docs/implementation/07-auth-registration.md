# M07 — Customer registration & login

**Depends on:** M02
**Spec:** F4, F3 step 2, §5 Security layer 2, §6.1
**Goal:** Customers can create an account and log in entirely on the front end,
with email verification and locale capture — never seeing `wp-admin`.

## Tasks

- Shortcodes `[rd_login]`, `[rd_register]`, `[rd_lost_password]` on WP pages;
  seed creates those pages (CS + EN via Polylang).
- Registration: name, surname, email (= username), phone, password (WP strength
  meter); stores phone + locale (current site language) as user meta; T&C
  checkbox (required) + privacy notice text + separate optional marketing-consent
  checkbox (stored with timestamp, §6.1).
- **Email verification**: account inactive until the tokenized link is clicked
  (single-use, expiring token in user meta); the verification email is the first
  real use of the Mailer stub (interface now, full templates in M13).
- Login/lost-password: WP core mechanics behind custom front-end forms; generic
  error messages (no user enumeration); redirect subscribers away from `wp-admin`
  and hide the admin bar for them.
- Bot baseline per §5: honeypot + time-trap on the registration form; per-IP
  transient rate limit.
- Extend `wp rd seed`: 5 verified customers with locales/consents varied.

## Acceptance criteria

- [ ] Register → cannot log in before verifying → verify → can log in
- [ ] Verification token is single-use and expires
- [ ] Login error identical for wrong-password vs. nonexistent email
- [ ] Subscriber hitting `/wp-admin` is redirected to My account page location
- [ ] Locale + marketing consent (with timestamp) stored correctly per user
- [ ] Honeypot-filled or instant submission is silently dropped

## Verification

Full manual pass in a private window, in both languages (labels translate, locale
is captured per language). Check the verification email lands in wp-env's mail
catcher (e.g. MailHog/Mailpit container) — set that up here if M01 didn't.

## Out of scope

Enrollment itself; real email templates (Mailer is an interface + plain-text
verification mail); profile editing (M09).
