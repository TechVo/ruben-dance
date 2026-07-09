# M02 — Plugin skeleton, schema, roles

**Depends on:** M01
**Spec:** §3.2 (all tables), §5 (structure), §2 (roles)
**Goal:** Activation creates the full database schema and roles; the plugin has its
final internal structure (bootstrap, repositories, services) with empty-but-real
classes.

## Tasks

- Bootstrap `ruben-dance.php`: constants, autoload, activation/deactivation hooks.
- `Schema` class: all custom tables from §3.2 exactly as specified —
  `rd_location`, `rd_course_term`, `rd_lesson`, `rd_enrollment`, `rd_email_log` —
  via `dbDelta()`; charset `utf8mb4`; unique key
  (`term_id`,`user_id`,`participant_name`) on enrollments; schema version in an
  option + upgrade routine that re-runs `dbDelta` when the version bumps.
- `Roles` class: `rd_manager` role with plugin capabilities (`rd_manage`);
  capability added to `administrator` too. Created on activation, removed on
  uninstall (not deactivation).
- Repository base + one repository class per table (constructor, table name,
  `find($id)`, `insert`, `update` — implemented, minimal).
- Deactivation: nothing destructive. `uninstall.php`: remove roles/options;
  **keep tables** (data loss must be a conscious manual act).
- Top-level admin menu "Ruben Dance" (empty landing page) visible to `rd_manage`.

## Acceptance criteria

- [ ] Activation on a fresh site creates all 5 tables (verify via `wp db query "SHOW TABLES"`)
- [ ] Re-activation / version bump is idempotent (no errors, no duplicate keys)
- [ ] Unique key on enrollments exists exactly as specified
- [ ] `rd_manager` user sees the "Ruben Dance" menu; `subscriber` does not
- [ ] Unit test: schema version upgrade path runs `dbDelta` once

## Verification

`wp-env` fresh start → activate → inspect tables and columns against §3.2
column-by-column (types, NULLability, defaults — especially
`participant_name NOT NULL DEFAULT ''`). Create an `rd_manager` user via
`wp user create ... --role=rd_manager` and log in as them.

## Out of scope

Any UI beyond the empty menu page; seed data beyond what M01 stubbed.
