# Launch checklist — remaining human actions (M16)

Everything a developer can do locally for M16 is **done** (voucher page +
inquiry form, complete `cs_CZ` translation, accessibility pass, this
checklist). Every box below needs a **person** — the site owners, a
wordpress.com account holder, a lawyer, or someone with a phone and a real
banking app. Launch = DNS/site switch **only after every box is checked**.

References: [`requirements.md`](requirements.md) §7 (remaining open items),
§5 Security layers 3–4, §4.4 (email deliverability), F16 (QR), F17 (vouchers),
[`implementation/16-launch.md`](implementation/16-launch.md).

Evidence convention: when a box is checked, attach the evidence (screenshot,
log line, backup-restore timestamp) right under the item — the milestone's
acceptance criteria require the hardening items to be *verifiably* done, not
just ticked.

---

## 1. Hosting: wordpress.com Business (spec §7 item 1)

- [ ] **Owners approve the cost**: custom plugins require the **Business plan
      or higher** (~$25/month billed yearly). Lower tiers cannot run this
      project at all. Fallback if declined: Czech managed WordPress hosting
      (~⅕ the price, but security/updates become self-managed — the §5
      hardening items below then all fall on us).
- [ ] **Purchase the plan** under an owner-controlled wordpress.com account
      (not a developer's personal account); add the developer as a user with
      admin rights instead.
- [ ] **Verify before committing the DNS switch** (all three were flagged in
      §7 as "verify before committing"):
  - [ ] **Polylang installs and activates** on the plan (free tier of the
        plugin suffices; wordpress.com Business allows plugin uploads —
        confirm no marketplace restriction bites).
  - [ ] **SMTP story**: confirm which transactional email route the plan
        supports — an SMTP plugin (e.g. WP Mail SMTP) pointed at a provider
        (Mailgun/Postmark/SES), or the provider's own plugin. Bare PHP
        `mail()` is not acceptable (§4.4, README "Deliverability in
        production").
  - [ ] **Secrets handling**: direct `wp-config.php` editing is restricted on
        wordpress.com. Confirm where the SMTP API key will live (most SMTP
        plugins store it in `wp_options`; if a constant is required, check
        wordpress.com's supported mechanism). The plugin itself needs **no**
        `wp-config.php` secrets — IBAN/account settings live in options
        (Ruben Dance → Settings).

## 2. Staging site + GitHub deployment

- [ ] **Create the staging site** (included in Business) before anything
      touches production.
- [ ] **Set up GitHub deployment** of the plugin directory: connect the
      repository in wordpress.com's Deployments UI, deploy **only**
      `plugin/ruben-dance/` (the repo root is a dev harness — docs, wp-env
      config, mail catcher — and must never ship). Note: the deployed
      directory must include a production `vendor/` autoloader
      (`composer install --no-dev` as a build step; the runtime needs only
      the PSR-4 classmap + `chillerlan/php-qrcode`).
- [ ] **Run the full M08→M11 loop on staging without code changes**
      (acceptance criterion): seed or hand-create a course/term, register a
      test customer (email verification round-trip via the real SMTP), enroll,
      check E2/E3 arrive, mark paid from the roster, check E4 arrives.
- [ ] **Install Polylang on staging** and confirm the CS/EN language setup
      matches local dev (CS default, EN second — the plugin auto-configures
      on first load if no language exists).

## 3. Production configuration (owners + developer together)

All of these live in **Ruben Dance → Settings** unless noted:

- [ ] **SMTP provider connected** and a test email delivered to an external
      mailbox (Gmail + one Czech provider, e.g. Seznam) — check spam folders.
      SPF/DKIM/DMARC records set for the sending domain.
- [ ] **Real bank account number** entered (the human-readable form customers
      see in payment instructions).
- [ ] **Real IBAN** entered (drives the QR code; leaving it blank disables QR
      everywhere without errors).
- [ ] **Due-date days** confirmed by owners (default 7).
- [ ] **Admin notification email** set to the owners' real address (receives
      E3 enrollment notifications and E8 voucher inquiries).
- [ ] **Retention window** (years) confirmed by owners (default 3, spec §6.1).
- [ ] **Email templates reviewed by the owners**: for each type E1–E8 and both
      languages, send a test to the owners (Ruben Dance → Email Templates →
      "Save & send test to me") and get an explicit OK on the wording.

## 4. Security hardening (spec §5 layers 3–4, §7 item 6)

Collect evidence (screenshot or log line) under each item when done:

- [ ] **2FA enabled for every owner/admin account** — wordpress.com account
      2FA for the platform login, plus the "Two-Factor" plugin (WP core team)
      if wp-admin passwords are also in play. No admin account named `admin`.
- [ ] **`DISALLOW_FILE_EDIT` or platform equivalent** — wordpress.com
      restricts the theme/plugin editor on managed plans by default; verify
      the editor is actually absent from wp-admin and note how it's enforced.
- [ ] **XML-RPC disabled** — via the hosting toggle or a hardening plugin;
      verify `POST /xmlrpc.php` no longer answers `200`.
- [ ] **Auto-updates on** for WP core (minor) and all plugins; plugin count
      kept minimal; a calendar reminder exists for the monthly update-channel
      review.
- [ ] **Backups verified** — daily automatic backups exist off the web server
      (wordpress.com/Jetpack includes this), **and one restore actually
      performed on staging** (acceptance criterion — record the date and what
      was restored).
- [ ] **HTTPS only + HSTS** — site addresses `https://`, HSTS header present
      (check `curl -sI https://<domain> | grep -i strict-transport`),
      cookies `Secure`.
- [ ] **Login attempt limiting** — hosting-level protection confirmed, or
      Limit Login Attempts Reloaded installed (the plugin's own per-IP rate
      limits cover its custom forms only, not `wp-login.php`).
- [ ] Optional: **Cloudflare free tier** in front (WAF, bot filtering, hides
      origin IP) — decide, don't drift into it later.

## 5. QR payment code against the real IBAN (M14 flag, F16)

M14's acceptance criterion "QR scan works in a real banking app" could not be
fully closed locally (no real IBAN, no phone). Repeat the test on staging with
production settings:

- [ ] Enter the **real production IBAN** in Settings.
- [ ] Create a test enrollment; open **My account → My enrollments**; scan the
      QR code with at least one real Czech banking app (ideally two — e.g.
      ČSOB and Air Bank differ in SPAYD strictness).
- [ ] Confirm the app pre-fills: correct account, correct amount, correct
      variable symbol, message "Ruben Dance".
- [ ] Repeat with the QR image in the E2 email (email clients sometimes
      re-compress images — the scan must still work).
- [ ] **Do not pay** — cancel in the app after verifying the pre-filled data.

## 6. Owner acceptance run (the milestone's real verification)

An owner performs the full loop **on staging, without developer help** — the
developer may watch, not touch. Script:

1. Owner logs into wp-admin with their own (2FA-protected) account.
2. Owner creates a real course (CS + EN content) or picks an existing one,
   then creates a **real term** under Ruben Dance → Terms (dates, price,
   capacity, discounts as they actually intend).
3. A test customer (someone from the school, on their own device) registers
   through the public site, clicks the verification email, logs in.
4. The test customer enrolls in the term from the catalog — notes the
   §6.3 button wording and the payment instructions page.
5. Both check their inboxes: customer got E2 (with QR), owners' notification
   address got E3.
6. Owner opens Ruben Dance → Roster for the term, finds the enrollment,
   **marks it paid**; customer receives E4.
7. Owner cancels the enrollment from the detail screen; customer receives E6.
8. Owner checks Ruben Dance → Email Log shows every send with status `sent`.
9. Owner submits the voucher inquiry form on the voucher page; the inquiry
   (E8) arrives at the notification address.

- [ ] Acceptance run completed by an owner without developer intervention.

## 7. Legal texts (spec §7 item 5 — lawyer)

The plugin seeded **placeholder** pages with the legally required structure;
the text is not real:

- [ ] **Privacy policy** (CS + EN) drafted by the lawyer and pasted over the
      placeholder pages (`/zasady-ochrany-osobnich-udaju/`,
      `/privacy-policy…/`). Must cover: controller identity, purposes/legal
      bases (§6.1 table), retention periods (match the Settings value!),
      processors (hosting, SMTP provider, Cloudflare if used — each with a
      DPA), data-subject rights, no non-EU transfers beyond listed
      processors, cookies (see [`cookie-audit.md`](cookie-audit.md)).
- [ ] **Terms & conditions** (CS + EN) drafted by the lawyer and pasted over
      the placeholders (`/obchodni-podminky/`, `/terms-and-conditions/`).
      Must include: school identification (IČO, address), pricing/payment/
      cancellation terms, complaint handling, and the **§ 1837(j)
      no-withdrawal clause** for fixed-date leisure services — explicitly
      confirmed by the lawyer (§6.3).
- [ ] **Refund policy wording** decided by owners (feeds the T&C, §7 item 4).
- [ ] **Marketing email approach** decided with the lawyer: opt-in consent
      checkbox (already implemented) vs. the existing-customer exception of
      Act 480/2004 Sb. (§6.1).
- [ ] **Voucher page text** reviewed by owners (seeded copy at
      `/darkove-poukazy/` + `/gift-vouchers/` is a reasonable draft, but the
      offer terms — amounts, validity — are the owners' call).

## 8. Content (spec §7 items 2–3 — owners/agency)

- [ ] **English course descriptions** translated (system supports EN from day
      one; can launch with partial EN content — decide what "partial" means).
- [ ] **Content migration decision**: move pages/media from the current
      ruben-dance.cz, or start fresh? (Doesn't affect the plugin; blocks the
      content phase.)
- [ ] Real course photos/media uploaded; **decorative images get empty `alt`**
      (accessibility §6.4) — content editors need to know this rule.
- [ ] Site pages (about, contact, pricing) written in CS + EN.

## 9. Launch day

- [ ] Every box above checked, with evidence collected in this file.
- [ ] Fresh backup taken immediately before the switch.
- [ ] DNS switched / site made public.
- [ ] Post-launch smoke test: register → verify → enroll → E2 arrives, QR
      scans, calendar loads, both languages render.
- [ ] `wp rd retention --dry-run` scheduled check one month out (retention
      cron runs monthly; verify its first real run in the email/admin logs).
