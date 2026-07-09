# M04 — Course CPT + multilingual base

**Depends on:** M02
**Spec:** §3.1, §5 Multilingual, F1 (filters need taxonomies)
**Goal:** Courses exist as translatable public content; the CS/EN foundation
(Polylang) is in place before anything user-facing is built on it.

## Tasks

- Register CPT `rd_course` (public, has_archive, editor + thumbnail support) and
  taxonomies `rd_dance_style` and `rd_level` (used by F1 catalog filters).
- Install + configure Polylang (free) in wp-env: CS default, EN second; CPT and
  both taxonomies marked translatable; language switcher available.
- Helper `Lang` class: current language, resolve a course post to the right
  translation (`pll_get_post`), pick `_cs`/`_en` column variants — the single
  place the rest of the plugin asks language questions.
- Extend `wp rd seed`: 4 courses (e.g. Salsa beginners, Bachata intermediate,
  Kids dance, Ladies styling) with CS + EN versions, styles/levels assigned.

## Acceptance criteria

- [ ] Course created in CS, translated to EN, both permalinks render on the front end
- [ ] Language switcher flips between the two versions
- [ ] `Lang` helper unit-tested for the `_cs`/`_en` column pick (with Polylang absent
      it must fall back to CS, not fatal — the plugin may outlive the multilingual choice)
- [ ] Seed is idempotent and creates linked translations

## Verification

Front end in both languages shows the right course content. Deactivate Polylang
temporarily → site still works in CS (graceful degradation).

## Out of scope

Catalog listing/filters (M08 territory via shortcodes), terms.
