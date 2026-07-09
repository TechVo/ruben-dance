# M05 — Terms & lessons admin

**Depends on:** M03, M04
**Spec:** §3.2 `wp_rd_course_term` + `wp_rd_lesson`, F9, F10
**Goal:** The owner can enter a whole season: create terms (incl. workshops),
lesson dates generate automatically, individual lessons can be cancelled/edited.

## Tasks

- **LessonGenerator service** (pure, unit-tested first): every `weekday` between
  `date_from`–`date_to` → lesson rows; regeneration preserves manually edited/
  cancelled lessons (match by date); workshop (`type=workshop`,
  `date_from = date_to`) → exactly one lesson.
- Terms admin: list (filter by season/status/location), add/edit form with all
  §3.2 fields — course (CS original), location (active only), type, weekday,
  times, date range, capacity, price, `discount_early` + `early_until`,
  `discount_pair`, status, `season_label_cs/en`, `note_public_cs/en`.
- On save: (re)generate lessons; show the generated dates on the term screen.
- **Duplicate term** action: copies everything, shifts nothing — admin edits dates
  (F9 "copy Autumn → Spring").
- Lessons sub-screen per term (F10): list of dates; cancel / change time / note.
  (The "notify enrollees" email hook is stubbed — implemented in M13.)
- Extend `wp rd seed`: ~6 terms across the 4 courses — mix of statuses, one with
  early-bird, one with pair discount, one workshop, one at low capacity.

## Acceptance criteria

- [ ] Unit tests: generator handles date ranges, holidays-style edits surviving
      regeneration, workshop single-lesson case, DST boundaries (times are wall-clock)
- [ ] Creating a Mon 18:00 term Sep–Dec produces the correct ~15 lesson rows
- [ ] Editing the term's end date regenerates without touching a cancelled lesson
- [ ] Duplicate-term produces an editable copy in `draft`
- [ ] Workshop term creates exactly one lesson

## Verification

As `rd_manager`: enter a real Ruben Dance-like season (use the current site's
schedule as reference), check generated dates against a calendar by hand for one
term, cancel one date, change the range, re-check.

## Out of scope

Public display, enrollments, emails (stub only).
