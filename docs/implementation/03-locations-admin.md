# M03 — Locations admin

**Depends on:** M02
**Spec:** F13, §3.2 `wp_rd_location`
**Goal:** CRUD for locations — deliberately the simplest entity first, to establish
the admin patterns (list table, form handling, nonces, notices) every later admin
screen copies.

## Tasks

- "Locations" submenu under Ruben Dance: `WP_List_Table` (name, address, active,
  row actions edit/deactivate), add/edit form.
- Nonce + `rd_manage` check on every action; sanitization per field; admin notices
  for success/error.
- Deactivate instead of delete when the location is referenced by any term
  (referential integrity in the service, not the DB).
- Extend `wp rd seed`: 3 locations (use real ones from ruben-dance.cz).

## Acceptance criteria

- [ ] Create, edit, deactivate a location through the UI
- [ ] Direct POST without nonce / as subscriber is rejected
- [ ] Inactive locations flagged in the list, excluded from future term dropdowns
- [ ] `wp rd seed` creates the 3 locations idempotently (re-run ⇒ no duplicates)

## Verification

Manual pass through the UI as an `rd_manager` user (not as administrator — this
catches capability mistakes). Attempt a forged POST via curl → 403/nonce failure.

## Out of scope

Map embeds; anything term-related.
