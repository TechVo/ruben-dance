# M01 — Dev environment & tooling

**Depends on:** —
**Spec:** §5 (architecture, PHP ≥ 8.1 baseline)
**Goal:** A reproducible local WordPress running the (empty) plugin, with linting and
tests wired up, so every later milestone can be executed and verified.

## Tasks

- Repo layout: plugin code in `plugin/ruben-dance/`, docs stay in `docs/`.
- `@wordpress/env` (`wp-env`): `.wp-env.json` mapping `plugin/ruben-dance` into the
  site; PHP 8.1+; ports documented in the repo README.
- Composer in the plugin: autoloader (PSR-4, namespace `RubenDance\`), dev deps:
  `phpcs` + `WordPress-Coding-Standards` ruleset (`phpcs.xml` — WordPress standard,
  text-domain check for `ruben-dance`), PHPUnit + `wp-phpunit` for integration-style
  tests where needed; plain PHPUnit suffices for pure services.
- Composer scripts: `composer phpcs`, `composer phpcbf`, `composer test`.
- Skeleton WP-CLI command `wp rd seed` (registers, does nothing yet) — later
  milestones add their fixtures here.
- Repo README: how to start (`npx wp-env start`), log in, run checks.
- `git init` + sensible `.gitignore` (node_modules, vendor, wp-env artifacts).

## Acceptance criteria

- [ ] `npx wp-env start` yields a running WP at a documented URL; admin login works
- [ ] The empty plugin activates without notices (check `debug.log`)
- [ ] `composer phpcs` and `composer test` both run (trivially green)
- [ ] `wp rd seed` runs via `npx wp-env run cli wp rd seed`

## Verification

Fresh clone → follow README from scratch → all four criteria pass. If the README
isn't sufficient to get there, the README is the bug.

## Out of scope

Any real plugin functionality; CI pipeline (optional later).
