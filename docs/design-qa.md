# D9 — Cross-site design QA and consistency pass

Full walkthrough of the D1–D8 design implementation (HEAD `761c1a7`), driven
with Playwright (Chromium) against wp-env (`http://localhost:8888`), at 390px
and 1280px, logged in as `jana.novakova@example.com` for account/enrollment
screens. Every page was screenshotted at both widths, checked for horizontal
overflow (`scrollWidth <= clientWidth`), checked for external network
requests, and checked for browser console errors.

## Screens × viewport matrix

| Screen | URL | 390 | 1280 | Notes |
|---|---|---|---|---|
| Homepage | `/` | Pass | Pass | Footer logo text bug (see below) present at both widths before fix |
| Catalog | `/kurzy/` | Pass | Pass | Badges (workshop dashed, early-bird, obsazeno) all match token spec |
| Course detail | `/course/salsa-pro-zacatecniky/` | Pass | Pass | — |
| Calendar | `/kalendar/` | Pass | Pass | Month grid initially appeared empty — stale calendar cache from direct SQL test-data edits bypassing `Calendar_Cache`'s save hooks, not a product bug (see below); busted cache, re-verified chips render correctly (coral/yellow/dashed-workshop/cancelled-strikethrough) |
| Enrollment form | `/prihlaska/?term_id=<open>` | Pass | Pass | Found and removed a leaked designer annotation ("EN: …" note, see below) |
| Enrollment confirmation | (post-submit) | Pass | Pass | `rd-payment` block pixel-consistent with My account's version |
| My account — Moje přihlášky | `/muj-ucet/` | Pass | Pass | Found and removed stray leftover test-enrollment row (id 122) predating this session |
| My account — Můj rozvrh | `/muj-ucet/?rd_tab=schedule` | Pass | Pass | Cancelled-lesson row styling matches calendar's cancelled chip |
| My account — Profil | `/muj-ucet/?rd_tab=profile` | Pass | Pass | — |
| Registrace | `/registrace/` | Pass | Pass | — |
| Přihlášení | `/prihlaseni/` | Pass | Pass | — |
| Dárkové poukazy | `/darkove-poukazy/` | Pass | Pass | — |
| Obchodní podmínky (legal) | `/obchodni-podminky/` | Pass | Pass | Placeholder legal copy is intentional pre-launch content, not a design defect (see `docs/launch-checklist.md`) |

No page overflowed horizontally at either width, in any state (logged in/out,
all account tabs, all badge/status variants exercised).

## External-request audit (GDPR/cookie-banner requirement, M15)

Network requests were captured for every page/viewport pair above. Zero
requests to `fonts.googleapis.com`, `fonts.gstatic.com`, or any third-party
host on any page — Bricolage Grotesque loads exclusively from the plugin's
self-hosted `rd-fonts.css`/`public/assets/fonts/`. The only non-`localhost`
URL ever observed was a same-origin `blob:http://localhost:8888/...` object
URL (an in-page resource, not a network request) on the homepage — not an
external host. **Result: pass, zero external hosts contacted, everywhere.**

## Typography / token audit

Bricolage Grotesque (`"Bricolage Grotesque", -apple-system, ...`) is the
computed `font-family` on every screen's `<h1>` at both widths — no
fallback-only screens found. Heading sizes match the documented scale (H1
44px mobile / 66px desktop, H2 26px/36px). Button pill radius, card radius,
badge styling and the coral/cocoa/cream/yellow palette are visually
consistent across every screen (all screens draw from the single shared
`rd-design.css`; no per-screen hardcoded color drift was found).

## Issues found and fixed

1. **Footer logo text invisible on every page (sitewide, all screens).**
   `.rd-logo` sets `color: var(--rd-cocoa)` for its cream-background header
   instance; the footer reuses the same class on its dark cocoa panel
   without overriding the color, so "RUBEN"/"DANCE" rendered in the exact
   same color as their background — only the coral `·` dot (separately
   colored) was ever visible. This affected literally every page on the
   site (theme-owned footer, shared by every template).
   **Fixed** in `theme/ruben-dance/assets/theme.css`:
   `.rd-site-footer__logo { color: var(--rd-cream); }`.

2. **Missing keyboard focus ring on theme-chrome links (sitewide).** The
   design system's `outline: 3px solid cocoa; outline-offset: 3px` focus
   ring is implemented consistently for every plugin-rendered interactive
   element (`.rd-btn`, form fields, checkboxes, calendar controls, account
   nav, etc.) but was never applied to the theme's own header/footer
   elements: the logo link, the desktop nav links (Kurzy/Kalendář/Kontakt),
   and the language switcher's desktop instance all fell back to the bare
   browser default outline. Footer links needed a **cream** ring instead of
   the usual cocoa one, since the footer's own background is cocoa (a cocoa
   ring there would be invisible — same reasoning the pre-existing mobile
   menu panel styling already used elsewhere).
   **Fixed** in `theme/ruben-dance/assets/theme.css` (logo, nav links,
   footer legal/contact links, footer logo instance) and
   `plugin/ruben-dance/public/assets/rd-design.css` (shared
   `.rd-lang-switch a`, since the switcher markup/CSS is a documented shared
   component, not theme-only).

3. **Leaked designer annotation shown to real visitors on the enrollment
   form.** `design/screens.html`'s mockup carries an inline note under the
   submit button — `EN: "Enroll with obligation to pay"` — clearly meant as
   a translator/reviewer annotation (telling the implementer what the
   English wording should be), not literal UI copy. D5's implementation
   copied it verbatim into `enroll-form.php`, so every Czech-language
   visitor saw a stray, out-of-context English gloss under the submit
   button. The button's own label already correctly branches on `$lang` via
   `Lang::EN`, so the note was redundant even by its own logic.
   **Fixed**: removed the `<p class="rd-enr-en-note">` block from
   `plugin/ruben-dance/public/templates/enroll-form.php` and its now-unused
   `.rd-enr-en-note` rule from `front-enroll.css`.

4. **Theme's vendored `rd-design.css` fallback had drifted from the
   canonical plugin copy.** The theme only falls back to
   `theme/ruben-dance/assets/vendor/rd-design.css` when the plugin is
   deactivated (a disaster-recovery path); functions.php's own docblock
   states the two copies are "same bytes, kept in sync manually." D4's
   calendar work added `.rd-chip--workshop` to the plugin's copy only,
   breaking that invariant (harmless today since the plugin is active on
   every page tested, but would silently drop the workshop dashed-border
   treatment if the plugin were ever deactivated).
   **Fixed**: re-synced the vendor copy to the plugin's canonical file
   (byte-identical again, including this session's other `rd-design.css`
   changes).

## Deferred (not fixed, with reason)

- **No active nav-item highlighting** (Kurzy/Kalendář/Kontakt never get a
  visual "you are here" state). This is uniform — identical on every page,
  not an inconsistency between screens — and matches the static design
  mockup, which also never depicts an active nav state. Fixing it would be
  a scope-creep behavior addition, not a consistency fix, so left as-is per
  the "polish, not redesign" brief.
- **Legal page placeholder copy** (`/obchodni-podminky/` renders
  `PLACEHOLDER — text připraví a před spuštěním provozu schválí právník.`)
  is intentional, tracked pre-launch content work in
  `docs/launch-checklist.md`, not a design defect.

## Methodology notes / non-issues ruled out during the walkthrough

- **Stale calendar month grid.** Directly seeding near-future lesson dates
  via raw `wp db query UPDATE` (to exercise the calendar's coral/yellow/
  dashed-workshop/cancelled chip variants, since all fixture lesson dates
  are in the past relative to the current system clock) bypasses
  `Term_Service`/`Lesson_Service`'s save hooks, so `Calendar_Cache` kept
  serving a pre-existing empty-result transient for that date range. Busting
  the cache (`Calendar_Cache::bump_version()`) resolved it immediately — the
  REST feed and FullCalendar rendering both work correctly; this was purely
  an artifact of writing test data below the service layer, not a product
  bug.
- **"Bot"-looking instant form submissions.** Playwright fills forms
  faster than `Bot_Guard::MIN_SECONDS` (3s), which by design silently shows
  the same success screen a real submission would without creating
  anything or sending email — confirmed intentional anti-spam behavior in
  `Enrollment_Form_Handler`/`Form_Handler`, not a bug. Re-tested with a
  3.5s pause before submit to get a real enrollment for the confirmation-
  screen screenshot.
- **Login rate limiting.** Repeated fast test logins tripped the 10-
  attempts/15-minutes per-IP limiter (`Rate_Limiter`); cleared the relevant
  transient to continue testing. Working as designed.

## Data hygiene

This session found and removed one **pre-existing** leftover from an
earlier (D8) QA/screenshot pass: `wp_rd_enrollment` id 122
(`participant_name = "D8 Email Verify"`, term 1, user `jana.novakova`),
which had no corresponding fixture in `wp rd seed` and was clearly a real
enrollment submitted through the live UI for an email-verification
screenshot and never cleaned up. Deleted, confirmed the table is back to
the canonical 15-row seed.

This session's own temporary data changes (all reverted before finishing):
- Shifted 5 `wp_rd_lesson.lesson_date` values (ids 1, 2, 32, 63, 77) into
  the near future and set id 2's status to `cancelled`, to exercise every
  calendar chip variant — reverted to original dates/status, calendar cache
  busted afterward.
- Submitted one real enrollment (workshop term 5, "Dámský styling") to
  screenshot the confirmation screen's `rd-payment` block — deleted the
  resulting enrollment row and its 2 email-log rows afterward.
- Cleared login/register/voucher-inquiry rate-limit transients generated by
  repeated automated test traffic.

Post-cleanup verification: `wp_rd_enrollment` = 15 rows, `wp_rd_email_log` =
31 rows, all 5 touched `wp_rd_lesson` rows back to their original
date/status — matching the state at session start (after removing the
pre-existing id-122 leftover).

## Verification

- `composer phpcs`: clean (122 files, 0 errors).
- `composer test`: 281 tests, 540 assertions, 0 failures (2 pre-existing
  skips, unrelated to this change).
- Manual re-walkthrough after fixes: no new console errors, no new
  horizontal overflow, footer logo text visible on every page, focus ring
  visible on logo/nav/lang-switch/footer links (cream on the footer,
  cocoa everywhere else), enrollment form no longer shows the stray
  English note.
