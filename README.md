# Ruben Dance — reservation system

Custom WordPress plugin for [ruben-dance.cz](https://ruben-dance.cz/) (dance school,
Prague): course catalog, public calendar, customer accounts, enrollments and
admin tooling. See [`docs/requirements.md`](docs/requirements.md) for the full spec
and [`docs/implementation/`](docs/implementation/) for the milestone plan.

Plugin code lives in [`plugin/ruben-dance/`](plugin/ruben-dance/); everything else in
this repo (docs, local tooling config) supports that.

## Prerequisites

- **Docker** (Engine + the `compose` CLI plugin) — runs the local WordPress site.
  - This repo was verified against Docker Engine with the `docker compose` plugin
    installed **per-user** at `~/.docker/cli-plugins/docker-compose` (no root
    needed): if `docker compose version` fails with "unknown command", grab the
    matching binary from the
    [docker/compose releases](https://github.com/docker/compose/releases) page for
    your OS/arch and drop it there (`chmod +x`).
- **Node.js** (LTS) — runs `wp-env` (`@wordpress/env`), which manages the Docker
  containers.
- **PHP 8.1+ and Composer** — used for the plugin's own dependencies (PHPCS,
  PHPUnit). If you don't have them installed locally, every `composer …` command
  below can instead be run through Docker:
  ```bash
  docker run --rm -v "$PWD":/app -w /app composer:2 composer <command>
  ```
  (substitute the same way for `composer install`, `composer phpcs`, etc.)

## 1. Start WordPress

```bash
npm install       # installs @wordpress/env locally (first run only)
npx wp-env start
```

This builds/starts the Docker containers and gives you:

- **Site:** http://localhost:8888 — admin at http://localhost:8888/wp-admin
  (user `admin`, password `password` — wp-env defaults)
- **Tests site** (separate DB, used by `wp-env run tests-cli`):
  http://localhost:8889

The Ruben Dance plugin (`plugin/ruben-dance/`) is auto-mounted and activated.

Stop the site with `npx wp-env stop`; wipe it (fresh DB) with `npx wp-env destroy`.

## 2. Plugin dependencies, linting, tests

```bash
cd plugin/ruben-dance
composer install     # pulls in PHPCS + WordPress-Coding-Standards, PHPUnit, wp-phpunit

composer phpcs        # lint against the WordPress coding standard
composer phpcbf        # auto-fix what can be auto-fixed
composer test           # run the PHPUnit suite
```

All three commands are trivially green on a fresh clone (the plugin has no real
functionality yet — see `docs/implementation/01-dev-environment.md`).

## 3. Seed command

Each milestone extends `wp rd seed` with more fixture data (courses, terms with
different discounts/capacities, enrollments in various states). Run it against the
running site with:

```bash
npx wp-env run cli wp rd seed
```

Screens should always be checked against seeded data, never an empty database.

## Troubleshooting

- **Plugin activation notices:** check the WordPress debug log inside the
  container:
  ```bash
  npx wp-env run cli tail -50 wp-content/debug.log
  ```
  (`WP_DEBUG_LOG` is on by default in `.wp-env.json`.)
- **Ports already in use:** `.wp-env.json` pins the site to `8888` and the tests
  instance to `8889`. Change the `port` / `env.tests.port` values there if those
  are taken on your machine.
- **`docker compose` missing:** see the per-user install note under
  Prerequisites above.

## Repo layout

```
plugin/ruben-dance/   # the WordPress plugin (Composer project, PSR-4 autoloading)
docs/                 # requirements & milestone-by-milestone implementation plan
.wp-env.json          # wp-env config: maps plugin/ruben-dance into the site, PHP 8.1
```
