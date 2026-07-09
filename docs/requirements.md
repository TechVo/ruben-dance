# Ruben Dance — Reservation System: Requirements & Technical Design

**Project:** New website for [ruben-dance.cz](https://ruben-dance.cz/) (dance school, Prague)
**Platform:** WordPress + MySQL, custom plugin
**Status:** Draft v0.1 (2026-07-09)

---

## 1. Overview

The dance school offers multi-week group courses (salsa, bachata, reggaeton, …) at several
Prague locations, plus workshops and individual lessons. Today, sign-up happens by phone
and email only.

The new site adds:

1. **Course catalog + public calendar** — visitors see which courses run when and where.
2. **Customer accounts** — customers register, enroll in courses, and see their
   enrollments and payment status in a "My account" area.
3. **Administration** — owners create courses and their schedules, manage enrollments
   (including manual moves to balance partners), and track payments.
4. **Email notifications** — automatic emails for enrollment, payment confirmation,
   and schedule changes.

### Confirmed decisions

| Decision | Choice |
|---|---|
| What is reserved | A **whole course** (multi-week series, e.g. "Salsa beginners, Mon 18:00, Sep–Dec"). Not single classes. |
| Payments | **Offline only** in v1 — bank transfer / cash. The system tracks payment status; admin marks enrollments as paid. No payment gateway. |
| Implementation | **Custom WordPress plugin** with its own database tables. No third-party booking plugin. |
| Notifications | **Email notifications are in scope** for v1. |
| Partner balancing | No automatic leader/follower logic. Admin must be able to **manually edit enrollments** (change role, move between courses) to balance a course. |
| Capacity | **Soft limit.** A full term still accepts enrollments; those are flagged **over capacity** and the owner decides (keeps room for leader/follower balancing). No automatic waitlist. |
| Self-cancellation | **Not in v1.** Customers contact the school; only admin cancels an enrollment. |
| Confirmation | **Auto-confirm.** Enrollment is confirmed immediately and payment instructions are sent right away — no manual approval step. |
| Payment due date | Payment instructions state a **due date** (default 7 days, configurable). Overdue enrollments are highlighted in admin with a manual "send reminder" button. **Nothing expires automatically.** |
| Mid-season joining | Allowed as long as the term is `open` (owners close it when they want). **Admin can override the price** on an enrollment — pro-rating is a manual decision. |
| Discounts | **Early-bird** (until a date) and **partner** discounts, configured per term as CZK amounts, **auto-applied and combinable** at enrollment. Admin can always override the final price. |
| Workshops / one-off events | **In v1** — modeled as a term with a single lesson (`type = workshop`), same enrollment flow, visible in the calendar. |
| Language | **Fully bilingual Czech + English** — multilingual content (Polylang), translated plugin strings, per-language email templates. |
| Enrolling others (children) | Every enrollment carries a **participant name** (empty = the account holder), so a parent enrolls their children from one account — including several participants in the same course. |
| QR payment | Payment emails and My account include a **Czech QR platba (SPAYD) code** with amount and variable symbol — scan-and-pay, no typing errors. |
| Gift vouchers | **Manual in v1**: info page + simple inquiry; redemption = admin adjusts the enrollment price and notes the voucher. Full voucher module is v2. |
| Make-up lessons (náhrady) | **Deliberately not in the system.** Owners contact customers directly and tell them when to come to an extra class — stays a manual process. |

### Explicitly out of scope for v1

- Online card payments (data model must not prevent adding a gateway later).
- Booking of individual 1-on-1 lessons with time-slot picking.
- Automatic waitlist logic (over-capacity flag covers the need in v1).
- Customer self-cancellation and automatic expiry of unpaid enrollments.
- Make-up lessons for missed classes — handled by the owners contacting customers
  directly, by their own choice.
- Full gift-voucher module (purchase, codes, balance) — manual process in v1.
- Online booking of individual lessons, wedding choreography, corporate events —
  these stay inquiry-based (phone/email).
- Visual design / theme — this document covers functionality only.

---

## 2. Users and roles

| Role | WordPress role | Capabilities |
|---|---|---|
| **Visitor** | none (anonymous) | Browse course catalog and calendar. Start registration/enrollment. |
| **Customer** | `subscriber` (default WP role) | Log in via front-end (never sees `wp-admin`). Enroll in courses, view own enrollments + payment status, edit own profile. |
| **Owner / manager** | custom role `rd_manager` | Full access to the plugin's admin: courses, schedules, enrollments, customers, emails. No access to WP settings/plugins/themes. |
| **Administrator** | `administrator` | Everything (the developer / site admin). |

Customers are ordinary WordPress users. This gives us login, password reset,
"lost password" emails, and session handling for free, and keeps the door open
for WooCommerce or other plugins later.

---

## 3. Domain model

The key insight: a **Course** (e.g. "Salsa for beginners") is marketing content that
rarely changes, while a **Course term** (a concrete run: season, weekday, time, place,
price) is structured data that changes every semester. Customers enroll in a **term**,
not in the abstract course. Individual **Lessons** (dates) are generated from the term
so the calendar can show real dates and the admin can cancel/move a single date
(holidays, illness).

```
Course (CPT)  1 ──── n  CourseTerm  1 ──── n  Lesson        (calendar shows these)
                            │
                            1 ──── n  Enrollment  n ──── 1  WP User (customer)
                            │
Location      1 ──────────n─┘
```

### 3.1 Course — WordPress custom post type `rd_course`

The abstract course as public content: name, description, level, dance style, photos.
Using a CPT (instead of a custom table) gives us the editor, permalinks, SEO,
and media handling for free. Structured data lives in custom tables below.

### 3.2 Custom tables

All plugin tables use the prefix `{$wpdb->prefix}rd_` (i.e. `wp_rd_…` by default),
created via `dbDelta()` on activation with a schema-version option for migrations.

**`wp_rd_location`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `name` | VARCHAR(190) | e.g. "Smíchov Terrace" |
| `address` | VARCHAR(255) | |
| `map_url` | VARCHAR(255) NULL | Google Maps link |
| `is_active` | TINYINT(1) | hide old venues without deleting history |

**`wp_rd_course_term`** — the thing customers enroll in

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `course_id` | BIGINT UNSIGNED | FK → `wp_posts.ID` (the `rd_course` CPT, Czech original — see §5 Multilingual) |
| `location_id` | BIGINT UNSIGNED | FK → `wp_rd_location.id` |
| `type` | ENUM | `course` (multi-week), `workshop` (single lesson, `date_from = date_to`) |
| `season_label_cs` / `season_label_en` | VARCHAR(100) | e.g. "Podzim 2026" / "Autumn 2026" |
| `weekday` | TINYINT | 1=Mon … 7=Sun (ISO) |
| `start_time` / `end_time` | TIME | |
| `date_from` / `date_to` | DATE | first and last lesson date |
| `instructor` | VARCHAR(190) | plain text in v1; normalize to a table if instructors ever log in |
| `capacity` | SMALLINT NULL | NULL = unlimited. **Soft limit:** when reached, the term shows as full but still accepts enrollments, which get the `over_capacity` flag |
| `price` | DECIMAL(10,2) | CZK list price per person; used in payment instructions |
| `discount_early` | DECIMAL(10,2) NULL | CZK off when enrolling on/before `early_until` |
| `early_until` | DATE NULL | last day of the early-bird discount |
| `discount_pair` | DECIMAL(10,2) NULL | CZK off per person when enrolling with a partner (customer states a partner at enrollment) |
| `status` | ENUM | `draft`, `open` (enrollable), `closed` (visible, not enrollable), `cancelled` |
| `note_public_cs` / `note_public_en` | TEXT NULL | shown on the site |
| `created_at` / `updated_at` | DATETIME | |

Discounts are stored as **amounts off**, not alternative prices, so they combine
naturally: `final price = price − discount_early (if before early_until) −
discount_pair (if enrolling with partner)`. The computed price is copied onto the
enrollment; admin can override it afterwards (mid-season pro-rating, special cases).

**`wp_rd_lesson`** — one physical class date, generated from the term

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `term_id` | BIGINT UNSIGNED | FK → `wp_rd_course_term.id` |
| `lesson_date` | DATE | |
| `start_time` / `end_time` | TIME | copied from term, editable per lesson |
| `status` | ENUM | `scheduled`, `cancelled`, `moved` |
| `note` | VARCHAR(255) NULL | e.g. "State holiday — no class" |

Generated automatically when a term is saved (every `weekday` between `date_from`
and `date_to`); admin can then cancel or edit individual rows. **The public calendar
reads exclusively from this table**, so what the calendar shows is always what
actually happens.

**`wp_rd_enrollment`** — the customer's order/reservation

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `term_id` | BIGINT UNSIGNED | FK → `wp_rd_course_term.id` |
| `user_id` | BIGINT UNSIGNED | FK → `wp_users.ID` — the account that enrolled and pays |
| `participant_name` | VARCHAR(190) NOT NULL DEFAULT `''` | who attends; empty = the account holder. Lets a parent enroll their children (several enrollments per account, even in the same term) |
| `role` | ENUM | `solo`, `leader`, `follower` — customer picks at enrollment where relevant; **admin can change it** (partner balancing) |
| `partner_name` | VARCHAR(190) NULL | free text: "coming with a partner" without forcing the partner to register; filled ⇒ pair discount applies |
| `status` | ENUM | see state diagram below |
| `over_capacity` | TINYINT(1) | set automatically when the enrollment was created past the term's capacity; prominent in admin |
| `price` | DECIMAL(10,2) | final price computed at enrollment time (list − discounts); **admin-editable** (pro-rating, special cases). Term price changes must not affect existing orders |
| `discount_note` | VARCHAR(190) NULL | auto-filled breakdown, e.g. "early-bird −300, partner −200" — keeps the price auditable |
| `due_date` | DATE | payment due date, `created_at` + N days (N configurable, default 7); overdue+unpaid is highlighted in admin |
| `variable_symbol` | VARCHAR(10) | unique, generated (e.g. `{year}{enrollment_id}`); customer uses it for the bank transfer |
| `payment_method` | ENUM | `bank_transfer`, `cash` |
| `paid_at` | DATETIME NULL | set when admin marks as paid, cleared on unmark |
| `paid_marked_by` | BIGINT UNSIGNED NULL | WP user ID of the admin who last changed payment status — keeps mark/unmark traceable |
| `customer_note` | TEXT NULL | from the enrollment form |
| `admin_note` | TEXT NULL | internal only |
| `created_at` / `updated_at` | DATETIME | |

Enrollment status lifecycle (auto-confirm — there is no pending/approval state;
the meaningful distinction is paid vs. unpaid):

```
           ──(admin marks paid)──▶
confirmed                            paid
           ◀──(admin unmarks)──────

confirmed | paid ──(admin cancels — customers cannot self-cancel in v1)──▶ cancelled
```

Marking paid stores who did it (`paid_marked_by`) and offers to send the E4
confirmation email; unmarking reverts to `confirmed` and never sends an email.

Nothing expires automatically: an unpaid enrollment past `due_date` stays
`confirmed` and is surfaced to the admin (filter + highlight + "send reminder"
button), who decides whether to remind or cancel.

**`wp_rd_email_log`** (recommended) — `id, enrollment_id NULL, user_id NULL, type,
recipient, subject, sent_at, status`. Owners will ask "did she get the email?";
this answers it.

### 3.3 Why custom tables and not post types for enrollments?

Enrollments are transactional rows that need real columns, indexes, uniqueness
(`term_id`, `user_id`, `participant_name` — which is why `participant_name` is
`NOT NULL DEFAULT ''`, a NULL column would silently break the unique key in MySQL),
aggregation (count per term vs. capacity), and CSV export.
Modeling them as posts+meta makes every one of those painful and slow. Custom
tables are the standard WP approach for order-like data (WooCommerce moved to
custom order tables for the same reason).

---

## 4. Functional requirements

### 4.1 Public site (visitor)

- **F1 Course catalog** — list of courses with open terms, filterable by dance style,
  level, location, weekday. Workshops (single-lesson terms) appear alongside courses,
  visually distinguished. Each course has a detail page (the CPT permalink) listing
  its open terms with day/time/place/price — including early-bird price and deadline
  when configured — and an "Enroll" button. A full term shows an "obsazeno / full"
  badge but remains enrollable (see F3).
- **F2 Calendar** — month and week view of all `scheduled` lessons; filter by style
  and location. Clicking an event opens the course detail. Cancelled lessons shown
  struck-through or hidden (admin choice). Must work on mobile (week view default).
  Rendered in the language of the page the visitor is on (CS/EN).
- **F3 Enrollment flow** —
  1. Visitor clicks "Enroll" on an open term. If the term is at capacity, a notice
     explains they can still sign up and the school will contact them; such
     enrollments get the `over_capacity` flag.
  2. If not logged in: combined register-or-login step (name, surname, email, phone,
     password; email = username). Email verification link before the account is active.
     The site language at registration is stored as the customer's locale and drives
     the language of all future emails.
  3. Enrollment form: **who attends** — "me" or "someone else" with a name field
     (parents enrolling children; repeatable, one enrollment per participant);
     dance role (solo/leader/follower — only if relevant for the course),
     optional partner name, optional note, a required T&C-agreement checkbox with a
     privacy-policy notice, and a separate optional marketing-consent checkbox (see
     §6 Compliance). The form shows the
     computed price live: list price minus early-bird discount (if before the deadline)
     minus partner discount (if a partner is stated).
  4. Confirmation page + **email with payment instructions**: final amount with the
     discount breakdown, account number, variable symbol, due date.
- **F4 Login / lost password** — front-end forms (WP core mechanics, custom templates).
  Customers are never sent to `/wp-admin` or `/wp-login.php` styling.

### 4.2 Customer account ("Moje kurzy")

- **F5 My enrollments** — list of the customer's enrollments (including those for
  other participants, e.g. children — participant name shown) with course name,
  schedule, location, price, payment status (awaiting payment / paid / cancelled),
  and for unpaid ones the payment instructions repeated **including the QR payment
  code** (see F16).
- **F6 My schedule** — upcoming lesson dates of the customer's active courses, including
  any cancelled/moved dates.
- **F7 Profile** — edit name, phone, email, password.

### 4.3 Administration (owner)

All admin screens live in `wp-admin` under one top-level menu **"Ruben Dance"**,
built with standard `WP_List_Table` UX so it feels native.

- **F8 Course management** — CRUD for the `rd_course` CPT (standard WP editor).
- **F9 Term management** — CRUD for course terms, including type (course/workshop),
  capacity, and the discount fields (early-bird amount + deadline, partner amount).
  On save, lesson dates are (re)generated; already-edited lessons are preserved.
  Duplicate-term action ("copy Autumn 2026 → Spring 2027") to make each season's
  setup fast. Creating a workshop = term with `date_from = date_to`, one lesson.
- **F10 Lesson management** — per-term list of dates; cancel a date, change its
  time, add a note. Cancelling a lesson can trigger an email to enrolled customers
  (admin confirms before sending).
- **F11 Term roster + enrollment management** — the main daily-use screen, in two
  views:

  **a) Term roster** (the screen the owner opens before/after every class):
  - pick a term → one row per enrollment: participant name (account holder if not
    filled), contact (email, phone), dance role, price, over-capacity and overdue
    badges, note indicator, and a **paid toggle**;
  - **mark/unmark paid inline** (single click, AJAX, no page reload):
    - *mark* sets `paid_at` + `paid_marked_by` and asks whether to send the E4
      payment-confirmation email (default yes);
    - *unmark* (wrong row clicked, payment bounced/refunded) clears both — never
      sends an email; both directions are recorded so it's always traceable who
      changed payment status and when;
  - header: paid/total count vs. capacity, leader/follower/solo breakdown
    (imbalance at a glance), sum collected vs. expected;
  - row click → enrollment detail (all fields incl. price/discount breakdown,
    notes, email history from `wp_rd_email_log`) with a link to the customer
    detail (F12);
  - roster actions: cancel, edit role/partner note, **edit price**
    (with `discount_note`/admin note kept honest), **move enrollment to a
    different term** (partner balancing), **send payment reminder** (E7),
    add admin note; CSV export of the roster (attendance sheet).

  **b) Cross-term list**: all enrollments with filters (term, status, paid/unpaid,
  **overdue**, **over capacity**) and search by name/email/**participant name**;
  overdue-unpaid rows highlighted. Manual enrollment creation on behalf of a
  customer (phone orders), including a manual price.
- **F12 Customer detail** — searchable customer list; detail page per customer:
  contact data, preferred language, marketing-consent status, and full enrollment
  history (course, term, participant, price, paid status) with links back to the
  rosters. This is the "who is this person calling me" screen.
- **F13 Locations** — simple CRUD.

### 4.4 Email notifications (F14)

| # | Trigger | Recipient |
|---|---|---|
| E1 | Account registration | customer (verification link) |
| E2 | Enrollment created | customer — summary (incl. participant) + payment instructions (amount, account, variable symbol, **QR payment code**) |
| E3 | Enrollment created | admin notification address |
| E4 | Marked as paid | customer — payment confirmation |
| E5 | Lesson cancelled/moved | all active enrollees of the term (admin-confirmed send) |
| E6 | Enrollment cancelled | customer |
| E7 | Payment reminder — unpaid after N days | customer (manual "send reminder" button in v1; cron later) |

Implementation: `wp_mail()` through an SMTP plugin (e.g. WP Mail SMTP) or a
transactional provider — PHP `mail()` from shared hosting lands in spam.
Editable subject/body with placeholders (`{course}`, `{price}`,
`{variable_symbol}`, …) stored as plugin settings, **one template set per
language (CS + EN)**. Each customer email is sent in the customer's stored
locale (captured from the site language at registration, editable in the
profile). Every send is written to `wp_rd_email_log`.

### 4.5 QR payment code (F16)

Wherever payment instructions appear (E2/E7 emails, My account), include the
standard Czech **QR platba**: a QR code encoding the SPAYD string
(`SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*X-VS:<variable symbol>*MSG:<course>`).
Customers scan it in their banking app — correct amount and variable symbol
guaranteed, which directly reduces the owners' payment-matching work.
Generated locally with a small PHP QR library (no external service — nothing
personal leaves the server); embedded as an attached/inline image in emails,
rendered on the fly in My account. Requires the school's **IBAN** (pre-launch
checklist).

### 4.6 Gift vouchers — manual in v1 (F17)

The school sells gift vouchers today, so the site must cover them, but a full
voucher module (purchase, codes, balances) is not v1. Instead:

- A **voucher info page** (normal WP content, CS + EN) describing the offer, with
  a short inquiry form (name, email, message) mailed to the owners.
- **Redemption**: the customer enrolls normally and mentions the voucher in the
  note; admin verifies it offline, edits the enrollment price (typically to 0 or
  the difference), and records the voucher in `admin_note`.
- The `discount_note` / `admin_note` trail keeps this auditable until a real
  module exists (v2).

---

## 5. WordPress architecture

```
wp-content/plugins/ruben-dance/
├── ruben-dance.php            # bootstrap, activation hook (dbDelta, roles)
├── includes/
│   ├── class-schema.php       # table definitions + versioned migrations
│   ├── class-roles.php        # rd_manager role + capabilities
│   ├── repositories/          # one class per table, all SQL via $wpdb->prepare()
│   ├── services/              # enrollment service, lesson generator, mailer
│   └── emails/                # templates + placeholder rendering
├── admin/                     # menu pages, WP_List_Tables, term/enrollment screens
├── public/                    # shortcodes/blocks, front-end forms, calendar
│   └── rest/                  # REST controllers (namespace rd/v1)
└── assets/                    # calendar JS/CSS
```

Key choices:

- **Front-end pages via shortcodes** (`[rd_catalog]`, `[rd_calendar]`, `[rd_account]`,
  `[rd_enroll]`) placed on normal WP pages — theme-independent, works with any
  builder the final design uses. Gutenberg block wrappers can come later.
- **Calendar:** [FullCalendar](https://fullcalendar.io/) (MIT license) on the front
  end, fed by a REST endpoint `GET /wp-json/rd/v1/lessons?from=…&to=…&style=…`
  reading `wp_rd_lesson`. Czech locale built in.
- **REST API** (`rd/v1`) for the calendar feed and enrollment submission; standard
  WP nonces + `permission_callback` on every route. Admin screens can be classic
  form posts — no need for a JS app there.
- **Security:** covered in its own section below — the plugin-code rules plus
  WordPress and hosting hardening.
- **Payments later:** because `variable_symbol`, `price`, and `status` live on the
  enrollment, adding a gateway (Comgate/GoPay/Stripe) later means adding one more
  path to reach `paid` — no schema change.
- **PHP ≥ 8.1, MySQL ≥ 8.0** as the development baseline (hosting not chosen yet —
  see pre-launch checklist).

### Multilingual (CS + EN)

The site is fully bilingual. Strategy per data type:

| Data | Translation mechanism |
|---|---|
| Pages, course CPT content | **Polylang** (free tier suffices) — each course post has a linked CS/EN translation; permalinks per language |
| Plugin UI strings (buttons, labels, statuses) | WordPress i18n (`__()` with text domain `ruben-dance`), CS + EN `.po` files |
| Custom-table text (`season_label`, `note_public`) | Paired `_cs` / `_en` columns — only a handful of short fields, a translations table would be overkill |
| Email templates | Per-language template sets in plugin settings (see F14) |
| Customer-facing dates/prices | Localized by WP locale (`wp_date`, `number_format_i18n`) |

Rules that keep this sane:

- **Structured data is language-neutral.** A term, lesson, or enrollment exists
  once — never per language. Only its display strings vary. The term's
  `course_id` always points to the **Czech** course post (the canonical one);
  the front end resolves the translation for the current language via Polylang
  when rendering.
- The REST calendar feed takes a `lang` parameter and returns localized titles.
- Each WP user has a locale (CS/EN) that drives their account pages and all
  emails to them.
- Admin screens are Czech-first (owners are Czech); admin strings still go
  through i18n like everything else.
- English course descriptions are content work for the owners — flagged in the
  pre-launch checklist.

### Security

The site is public, stores personal data, and WordPress is the most attacked CMS
on the internet — the vast majority of it automated bots probing known plugin
vulnerabilities and brute-forcing logins. Defense in four layers:

**1. Plugin code (our responsibility — the rules, not optional)**

- Every DB query through `$wpdb->prepare()` — no string-concatenated SQL, ever.
- Every form and REST route: nonce verification (`check_ajax_referer` /
  `wp_verify_nonce`) **and** a `permission_callback` / capability check
  (`rd_manage` for admin actions, `is_user_logged_in()` + ownership check for
  customer actions). Never trust a user ID from the request — always
  `get_current_user_id()`.
- Ownership enforcement: a customer can only ever read/act on enrollments where
  `user_id = get_current_user_id()`; checked in the repository layer, not just
  the controller.
- All output escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`);
  all input validated/sanitized at the point of entry (whitelist enums, `absint`
  IDs, `sanitize_text_field` strings).
- Integrity in the schema, not just code: unique key
  (`term_id`,`user_id`,`participant_name`) against duplicate enrollments,
  foreign-key-style checks in services, prices recomputed server-side (never
  trust the price shown in the form).
- Public write endpoints (registration, enrollment, contact) protected against
  bots: honeypot field + time-trap as baseline, **Cloudflare Turnstile** (free,
  no cookie banner implications, unlike reCAPTCHA) if spam appears; simple
  per-IP rate limiting via transients.
- No secrets in the repo; API keys (SMTP etc.) in `wp-config.php` constants.

**2. Authentication & accounts**

- Customers: WP core password hashing and sessions; email verification before the
  account is active; WP's password-strength meter on registration; generic error
  messages on login/reset (don't reveal whether an email exists).
- Owners/admins: **strong unique passwords + two-factor auth mandatory**
  (e.g. the "Two-Factor" plugin from the WP core team); admin accounts never
  named `admin`; login attempt limiting (e.g. Limit Login Attempts Reloaded or
  the hosting's protection).
- Customers get the `subscriber` role and are blocked from `wp-admin` entirely;
  `rd_manager` gets only the plugin's capabilities — a compromised owner account
  can't install plugins or edit code.

**3. WordPress hardening (one-time setup)**

- `DISALLOW_FILE_EDIT` — no theme/plugin editor in admin (a stolen admin session
  otherwise means instant remote code execution).
- Disable XML-RPC (classic brute-force amplification vector) and user enumeration
  (`?author=1` redirects, REST users endpoint restricted).
- **Auto-updates on** for WP core (minor) and all plugins; keep the plugin count
  minimal — every plugin is attack surface, and abandoned plugins are the #1 WP
  breach vector. Review the update channel monthly.
- Correct file permissions; no `wp-config.php` backups lying in webroot.
- Optional but cheap: put the site behind **Cloudflare** (free tier) — WAF rules,
  bot filtering, and it hides the origin IP.

**4. Hosting & operations**

- **HTTPS only** — HSTS header, cookies `Secure` + `HttpOnly`; WP addresses set
  to `https://`.
- **Backups: automatic, daily, stored off the web server**, and restore actually
  tested once. This is the single highest-value security measure — every other
  layer can fail and a backup still saves the business.
- DB user with least privilege (no `DROP`/`GRANT` needed at runtime beyond
  migrations); SFTP/SSH only, no plain FTP.
- PHP kept on a supported version (≥ 8.1); `display_errors` off in production.
- Email log and admin notes must not accumulate more personal data than needed
  (ties into GDPR retention below).

What we deliberately do **not** rely on: "security through obscurity" tricks
(renaming `wp-login.php`, hiding the WP version) may reduce log noise but stop
no targeted attack — fine as extras, never as the plan.

---

## 6. Compliance

> Not legal advice — the technical measures below implement the obligations, but the
> actual texts (privacy policy, T&C) and edge-case interpretations should get a
> lawyer's review before launch.

### 6.1 GDPR (personal data)

**What we store and why** — the legal basis matters because it determines whether we
need consent (mostly we don't):

| Data | Purpose | Legal basis |
|---|---|---|
| Name, email, phone, password hash | Account + enrollment handling | Contract performance (Art. 6(1)(b)) — **no consent checkbox needed** |
| Enrollments, payments, variable symbols | Delivering the course, accounting | Contract + legal obligation (accounting records) |
| Partner name (free text) | Course organization | Legitimate interest; keep it optional and minimal |
| Email log | Proving what was sent | Legitimate interest; store metadata + subject, **not full bodies** |
| Marketing emails (newsletter) | Promotion | **Opt-in consent** (unchecked checkbox), or the existing-customer exception of Act 480/2004 Sb. with opt-out in every mail — decide with the owners |

**Implementation requirements:**

- **Privacy policy page** (CS + EN) linked from registration, enrollment, and footer.
  Registration has a checkbox for **agreeing to T&C** and a *notice* (not consent)
  that data is processed per the privacy policy. A separate, unchecked, optional
  checkbox for marketing consent — never bundled.
- **Data subject rights** — hook the custom tables into WP core's personal data
  **export** and **erasure** tools (`wp_privacy_personal_data_exporters` /
  `_erasers`). Erasure **anonymizes** enrollments (name → "anonymized", keep
  price/dates) instead of deleting, because accounting records must survive.
- **Retention** — define and enforce: e.g. inactive customer accounts deleted/
  anonymized after N years (suggest 3), email log purged after 1 year, unpaid
  cancelled enrollments after 1 year. Implement as a monthly WP-Cron job, log runs.
- **Processors** — hosting provider, SMTP/transactional email service, Cloudflare
  if used: each needs a DPA (all standard ones have it), and the privacy policy
  lists them. Prefer EU data centers where offered.
- **Breach readiness** — 72-hour notification duty to ÚOOÚ; realistic measure for
  a project this size: the security layer above, plus knowing who to call
  (developer + hosting) and having the email log/backups to assess scope.
- No data transfers outside the EU beyond what the listed processors do; no
  profiling, no automated decision-making — state so in the policy.

### 6.2 Cookies (ePrivacy / Act 127/2005 Sb.)

Czech law requires **opt-in** consent for non-essential cookies (since 2022). The
cheapest compliant strategy is to **not need a banner at all**:

- v1 uses only **strictly necessary** cookies: WP login session, the language
  choice — these are exempt, no banner required.
- Therefore: **no Google Analytics / Meta pixel in v1.** If the owners want
  analytics, prefer a cookieless/self-hosted option (e.g. self-hosted Matomo
  configured cookieless, or server-side stats) before reaching for GA + a consent
  management banner.
- Cloudflare Turnstile (if enabled) is designed to work without consent-requiring
  cookies — one more reason to prefer it over reCAPTCHA.
- If marketing tags ever arrive, add a proper consent-mode banner then — the site
  must not set those cookies before consent.

### 6.3 Consumer & e-commerce law (Civil Code, distance contracts)

Enrolling and paying for a course through the site is a **distance contract with a
consumer**, which triggers information duties (§ 1811, § 1820+ Civil Code):

- **Terms & conditions page** (obchodní podmínky, CS + EN): identification of the
  school (IČO, address), price incl. everything, payment and cancellation terms,
  complaint handling. The enrollment confirmation email must contain (or durably
  link) this information — our E2 email covers it with a T&C link + full order
  summary.
- **The enrollment button must clearly signal a payment obligation** (§ 1826a):
  label it e.g. "Závazně přihlásit s povinností platby" / "Enroll with obligation
  to pay" — not just "Submit". Cheap to do, legally required, often forgotten.
- **14-day withdrawal right**: consumers can normally withdraw from a distance
  contract within 14 days. **Exemption § 1837(j)** — leisure-time services
  provided on a specific date — very likely covers dance courses with fixed
  schedules, meaning no withdrawal right, **but this must be stated explicitly in
  the T&C** and confirmed by the lawyer. The refund policy the owners choose
  (open item 4) plugs into this.
- **Complaints (reklamace)**: T&C must describe the process; no system support
  needed in v1 beyond the contact channel.

### 6.4 Accessibility (EAA / Act 424/2023 Sb.)

The European Accessibility Act applies to e-commerce services since June 2025, but
**microenterprises (< 10 employees, ≤ €2M turnover) providing services are exempt**
— the school almost certainly qualifies for the exemption. Still, basic accessibility
is cheap when built in from the start and widens the customer base: semantic HTML in
all plugin output, labeled form fields with visible error messages, keyboard-navigable
calendar with a list-view fallback, sufficient color contrast. Target: roughly
WCAG 2.1 AA for the enrollment flow (the path that matters).

---

## 7. Remaining open items

All original design questions were resolved on 2026-07-09 (answers folded into the
"Confirmed decisions" table and the sections above). What's left doesn't block plugin
development — it's a **pre-launch checklist**:

1. **Hosting** — leaning towards **wordpress.com**. Critical constraint: custom
   plugins require the **Business plan or higher** (~$25/month billed yearly) —
   lower tiers cannot run this project at all; owners must approve the cost.
   Business includes managed MariaDB, backups, CDN/WAF, staging, SSH/WP-CLI,
   phpMyAdmin, and GitHub deployments (covers most of the §5 hardening checklist).
   Verify before committing: Polylang installable, SMTP/transactional email
   options, secrets handling (direct `wp-config.php` editing is restricted on
   their platform). Fallback: Czech managed hosting (~⅕ the price, self-managed
   security/updates). Database is MySQL/MariaDB either way — the only engine
   WordPress supports, and what the §3 schema is designed for.
2. **Content migration** — undecided whether pages/media from the current
   ruben-dance.cz move over or the site starts fresh. Doesn't affect the plugin;
   decide before the content phase.
3. **English content** — someone (owners? agency?) must translate course
   descriptions and site pages; the system supports EN from day one but can launch
   with partial EN content.
4. **Bank account details (incl. IBAN for the QR payment code) + refund policy
   wording** — needed for the payment instruction emails and terms & conditions
   page.
5. **Legal texts + lawyer review** — privacy policy and T&C in CS + EN, including
   the § 1837(j) no-withdrawal clause and the retention periods from §6.1; decide
   the marketing-email approach (opt-in vs. customer exception).
6. **Security hardening pass** — the one-time items from §5 Security: HTTPS/HSTS,
   `DISALLOW_FILE_EDIT`, XML-RPC off, auto-updates on, 2FA for all owner/admin
   accounts, login attempt limiting, daily off-server backups with a tested
   restore, optionally Cloudflare in front.

---

## 8. Suggested build phases

> Superseded by the detailed, AI-implementable milestone plan in
> [`docs/implementation/00-overview.md`](implementation/00-overview.md) —
> 16 thin vertical slices with acceptance criteria and verification steps.
> The table below remains as the coarse roadmap view.

| Phase | Content | Result |
|---|---|---|
| **1 — Foundation** | Plugin skeleton, schema + migrations, roles, locations, course CPT, term + lesson admin incl. lesson generation and workshop type | Owner can enter the whole season's schedule |
| **2 — Public** | Polylang setup, catalog, calendar (REST + FullCalendar), registration/login, enrollment flow incl. participants, discount computation and over-capacity flag, My account | Customers can enroll end-to-end, in CS or EN |
| **3 — Operations** | Enrollment admin (mark paid, edit price, move, overdue view, reminder button, export), email notifications (CS+EN templates) + QR payment codes + log, GDPR hooks, voucher info page | Owners run daily operations in the system |
| **4 — Polish / v2** | Automatic payment reminders via cron, waitlist, self-cancellation, online payments, gift-voucher module, attendance/reporting | Based on real usage |
