# M10 — Public calendar

**Depends on:** M05 (independent of 07–09 — can be built any time after terms exist)
**Spec:** F2, §5 (REST, FullCalendar)
**Goal:** Visitors see when and where classes happen, in a month/week calendar that
always matches reality (it reads generated lessons, not term definitions).

## Tasks

- REST route `GET /rd/v1/lessons?from&to&style&location&lang`
  (`permission_callback: __return_true` — public read-only; strict param
  validation; no personal data in the response, ever).
- Response: lesson date/times, localized course title (via `Lang`/Polylang),
  location name, style slug, status, course permalink.
- `[rd_calendar]` shortcode: FullCalendar bundled locally (no CDN), CS/EN locale
  by page language, month + week views, **week view default on mobile**, style +
  location filter controls, event click → course detail page.
- Cancelled lessons per F2: struck-through or hidden — plugin setting, default
  struck-through.
- Basic caching: REST response cached per query in a transient, invalidated on
  lesson/term save.

## Acceptance criteria

- [ ] Calendar shows seeded lessons in correct slots; cancelled date rendered per setting
- [ ] Filters narrow correctly and combine
- [ ] CS page shows Czech titles + locale; EN page English
- [ ] REST endpoint rejects malformed dates; response contains no user/enrollment data
- [ ] Admin cancelling a lesson is reflected after cache invalidation
- [ ] Usable on a phone-sized viewport (week view, no horizontal page scroll)

## Verification

Manual on desktop + mobile viewport, both languages. Cross-check one week of
calendar output against the terms entered in M05. `curl` the endpoint with hostile
params (huge ranges, garbage dates) — clean 400s, no notices in `debug.log`.

## Out of scope

Per-customer calendars (that's F6 in M09), iCal export (v2 candidate).
