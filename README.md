# Impact Score Dashboard

A PHP/MySQL web application for tracking and reporting community-engagement
impact scores. Staff submit scored evaluations of programs and interactions;
managers view reports, export data, and monitor trends over time.

## Features

- **Impact Score Submission** — Dynamic forms with configurable scoring
  questions, bulk entry support, and real-time score calculation.
- **Score Management** — View, search, edit, duplicate, merge, and delete
  submitted scores.
- **Reports & Analytics** — Programming reports with SPC control charts,
  comparison reports, ad hoc reports, and data exports.
- **Admin Portal** — Manage scoring questions, form profiles, users, teams,
  locations, and column configuration.
- **REST API** — JSON endpoints for scores, programs, and summary data.
- **Session-based auth** — Separate admin login protecting all admin routes.
- **Performer Database** — Full performer management module: directory,
  profiles, programs, bookings, reviews, file uploads, and Impact Score event
  linking.

## Tech Stack

- **Backend:** PHP 8.2 (MySQLi, native sessions), plus PHPMailer for SMTP.
- **Database:** MySQL 8.x.
- **Frontend:** Bootstrap 5, Chart.js, html2canvas, jsPDF, Montserrat font —
  all served from CDN.
- **Build:** Multi-stage Docker (Composer builder + PHP-Apache runtime).
- **Operations:** Single `Makefile` covers every developer and operator
  workflow.

---

## Quick Start

**Requirements:** Docker, Docker Compose, and GNU Make.

```bash
make setup      # first run: copies .env.example → .env, builds images, starts services
make up         # subsequent starts
make help       # list all available commands
```

The app is reachable at `http://localhost:8080`. In development, phpMyAdmin
is reachable at `http://localhost:8081` (dev override only).

`make setup` halts the first time so you can edit `.env`. Re-run it after
filling in real values.

---

## Common commands

| Command | What it does |
|---|---|
| `make setup` | First-time setup: scaffolds `.env`, builds both images, starts services |
| `make up` | Start the stack |
| `make down` | Stop and remove containers (incl. orphans) |
| `make restart` | Restart without rebuild |
| `make rebuild` | Force `--no-cache` rebuild of both images, then start |
| `make logs` | Follow logs (filter: `make logs service=db`) |
| `make ps` | Container status |
| `make shell` | Bash shell inside the web container |
| `make db-shell` | MySQL shell inside the db container |
| `make build` / `make build-db` / `make build-all` | Build images individually or together; tags `:latest` and `:VERSION` |
| `make db-migrate` | Apply pending `db/migrations/*.sql` against the running db (host-side runner) |
| `make db-dump` | Timestamped backup → `db/backups/YYYY-MM-DD_HHMMSS.sql` |
| `make db-restore file=…` | Restore a dump into the running db |
| `make db-reset` | **DANGER** — destroy db volume, recreate from `init.sql` |
| `make seed-dev` | Apply `db/seed_dev.sql` (dev-only admin + test users; refuses if `APP_ENV=production`) |
| `make composer-install` | Run `composer install --no-dev` in a transient container |
| `make version` | Print current `VERSION` (from `git describe`) |
| `make release VERSION=x.y.z` | Tag git, build and tag both images for release |
| `make push REGISTRY=… VERSION=…` | Push both images to a registry |
| `make pull REGISTRY=… VERSION=…` | Pull both images from a registry |
| `make clean` | **DANGER** — wipe containers + named volumes |

---

## Build & Operations

### Dev vs. production compose

Two compose files coexist:

- `docker-compose.yml` (base) — what end users and CI/CD invoke. No source
  bind-mounts, no `build:` directives, no phpMyAdmin, no published DB port.
  Self-contained image behavior.
- `docker-compose.override.yml` (dev only) — Docker Compose auto-merges this
  if it exists. Adds live-edit bind-mounts of the app source, exposes the DB
  port `3307:3306`, and starts phpMyAdmin on `:8081`.

End users pulling a versioned release get only the base file and a clean
production stack.

### Two-image architecture

| Image | Source | Contents |
|---|---|---|
| `impact-score:VERSION` | `./Dockerfile` | PHP 8.2 + Apache + app code + composer-built vendor/ |
| `impact-score-db:VERSION` | `./db/Dockerfile` | MySQL 8 + baked-in `init.sql` + migrations + auto-runner |

A versioned release produces both images. The db image is **self-contained** —
a consumer needs no local `db/` directory; migrations apply automatically on
every container start.

### Database migration — two-track workflow

Two migration mechanisms coexist. Both use the same `schema_migrations`
tracking table and the same `NNN_description.sql` filename convention.

**Track 1 — auto-migration on container start (production).**
`db/run-migrations.sh` is baked into the db image and launched by
`db/entrypoint.sh` on every container start. It waits for MySQL to be ready,
then applies any `db/migrations/*.sql` files not already in
`schema_migrations`. Fresh installs see `[migrate] Done. Applied: 0 migration(s).`
because `db/init.sql` pre-populates `schema_migrations` with everything
already folded into the schema.

**Track 2 — host-side runner (developer workflow).**
`scripts/migrate.sh`, invoked via `make db-migrate`, applies pending
migrations against a running db container via `docker exec`. Safe to re-run.

Upgrade workflow for image-only deployments:

```bash
make db-dump                       # always back up first
make down
# point docker-compose.yml at the new image tag (or `make pull VERSION=…`)
make up                            # migrations apply automatically
make logs service=db               # confirm `[migrate] Done. Applied: N migration(s).`
```

### Version tagging

`VERSION` defaults to `git describe --tags --always --dirty`. Override it on
the command line:

```bash
make build-all VERSION=1.0.0
make release   VERSION=1.0.0   # tags git, builds + tags both images
```

`release` refuses to run on a dirty working tree.

### Backups

`scripts/backup.sh` is a cron-friendly wrapper around `mysqldump
--single-transaction --quick --routines --triggers`, gzipped. It applies
`BACKUP_RETENTION_DAYS` (default 30) and optionally ships off-host:

- `BACKUP_REMOTE=s3://bucket/path/`   → uses `aws s3 cp`
- `BACKUP_REMOTE=user@host:/backups/` → uses `rsync`

Schedule via cron on the host:

```cron
30 2 * * * www-data /opt/impact-score/scripts/backup.sh >> /var/log/impact-backup.log 2>&1
```

`make db-dump` is the manual equivalent; `scripts/backup.sh` adds retention
and remote-ship logic.

---

## Production Deployment

`deployment_decisions.md` lists the IT decisions that need answers before the
first production deploy (TLS termination, registry choice, `.env` ownership,
backup destination, log persistence, etc.). Fill in the blanks there first.

### Registry-based release workflow

```bash
# Tag and build:
make release VERSION=1.0.0
make push    REGISTRY=ghcr.io/your-org VERSION=1.0.0

# On the production host:
make pull    REGISTRY=ghcr.io/your-org VERSION=1.0.0
make up      # auto-migration runs on db container start
```

### `.env` ownership

`.env` must never live in source control (enforced by `.gitignore`). On the
production host:

- Recommended owner / mode: `root:root`, `0600`.
- Recommended location: outside the cloned repo, e.g. `/etc/impact-score/.env`;
  symlinked or referenced explicitly from the docker-compose path.
- Generate strong values with `openssl rand -base64 32` (passwords) and
  `openssl rand -hex 32` (tokens).

### TLS

Production must run behind HTTPS. The image ships plain HTTP on port 80;
terminate TLS at an upstream reverse proxy (nginx / Caddy / org load balancer
/ Cloudflare). After TLS is in place, set `SESSION_SECURE_ONLY=true` in `.env`
so PHP marks session cookies as Secure. Any OAuth redirect URIs must use
`https://` and match the IdP registration exactly.

### Log persistence

The base compose bind-mounts `./logs:/var/www/html/logs`, so PHP error logs
survive container replacement. For central log aggregation, swap this bind
for a Docker `logging:` driver routed to your central system.

### Healthcheck observability

Both `web` and `db` services declare healthchecks; `restart: unless-stopped`
plus the healthchecks means Docker automatically replaces a stuck container.
`make ps` shows current health state.

### Production secret preflight

`secure/db_connection.php` (the env-driven shim baked into the image at
`docker/db_connection.php`) refuses to connect when `APP_ENV=production` and
any of `DB_PASSWORD`, `BOOTSTRAP_ADMIN_TOKEN`, or `MYSQL_ROOT_PASSWORD` still
hold placeholder values from `.env.example`. The container returns 500 on the
first request and logs a CRITICAL line — failing fast is the intended
behavior.

A second, separate preflight covers the bootstrap admin login.
`db/migrations/000_ensure_admin_user.sql` seeds an `admin` account with a
documented default password (`changeme`) so a fresh install has *something*
to log in with. That password lives in the `admins` table, not an env var, so
the env-var checks above can't see it — the connection shim runs its own
check after connecting: if `APP_ENV=production` and any row in `admins`
still carries that default password hash, the app refuses to serve requests
(500 + CRITICAL log) until it's rotated.

**Setting the admin password at initial deployment:**

1. Deploy normally — `000_ensure_admin_user.sql` applies automatically and
   the app will refuse to serve (500) because the seeded password is still
   the default.
2. Generate a real hash:
   ```bash
   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), \"\n\";"
   ```
3. Set it directly in the database (`make db-shell`, or `docker compose exec
   db mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"`):
   ```sql
   UPDATE admins SET password = '<hash from step 2>' WHERE username = 'admin';
   ```
4. Reload the app — the preflight now passes and the container starts
   serving normally.

**Rotating it again later / after subsequent migrations:** the preflight
check runs on every request while `APP_ENV=production`, so any time an
`admins` row is reset back to the known default hash (e.g. a fresh
`db-reset`, or a new environment stood up from these same migration files),
the app will refuse to serve until you repeat steps 2–4 above. Because
`000_ensure_admin_user.sql` is tracked in `schema_migrations`, it only
re-applies on a brand-new database (or after `make db-reset`) — routine
`make up` / `make db-migrate` runs will not overwrite a password you've
already rotated.

---

## Contributing

### Schema changes — the two-file rule

Any pull request that modifies the database schema **must** update two
things:

1. **`db/migrations/NNN_description.sql`** — incremental migration for
   existing deployments. Applied by both the container-side runner and
   `make db-migrate`.
2. **`db/init.sql`** — complete current schema for fresh installs. Baked
   into the `impact-score-db` image. **Also** add the new migration's
   filename to the `INSERT IGNORE INTO schema_migrations` block at the end of
   `db/init.sql` — that's what makes fresh installs skip the (already-folded-in)
   migration cleanly.

Updating only one breaks either fresh installs (missing schema) or existing
deployments (no upgrade path). Both must stay in sync at every commit that
changes the schema.

After any schema change, rebuild both images before testing:

```bash
make build-all
make db-reset       # development only — destroys data
```

### Local development

```bash
make setup          # first time
make up             # subsequent runs
make seed-dev       # populate dev admin + test users
```

`docker-compose.override.yml` bind-mounts the local source over the image,
so PHP changes appear on browser reload without rebuild.

### Coding conventions

- Procedural PHP — no framework, no ORM. The `mysqli` object is the only
  OOP touchpoint.
- Prepared statements everywhere — never interpolate user input into SQL.
- Admin routes start with `$_SESSION['admin_logged_in'] === true` check.
- Bootstrap 5.3, Chart.js, Montserrat — CDN-loaded. Add to a strict CSP if
  one is enabled.
- Color palette: `#480d3c` (Deep Plum), `#bb1b51` (Fuchsia), `#f5f2ec`
  (Parchment).

---

## Project Structure

```
impact-score/
├── Dockerfile                      # Multi-stage: composer-builder + PHP-Apache runtime
├── Makefile                        # Every developer + operator workflow
├── composer.json                   # PHP deps (PHPMailer, etc.)
├── docker-compose.yml              # Base (production / versioned release)
├── docker-compose.override.yml     # Dev-only: bind-mounts, DB port, phpMyAdmin
├── .env.example                    # Template; copy to .env and fill in
├── .dockerignore                   # Excludes secrets, db/, logs/, build cruft
├── .gitignore                      # Excludes .env, vendor/, logs/, uploads/, *.sql.gz, stale copies
│
├── index.php                       # Dashboard landing page
├── value_score_form.php            # Impact score submission form
├── view_scores.php                 # Score list with search/export
├── report.php / report2.php        # Programming reports w/ control charts
├── compare_report.php              # Side-by-side comparison report
├── control_chart_report.php        # SPC control chart report
├── ad_hoc_report.php               # Ad hoc filtered report
├── one_on_one_specialty.php        # One-on-one specialty scoring form
├── recurring.php                   # Recurring entry support
├── program.php                     # Program management
├── export.php / import.php         # Data export / import
├── admin_*.php                     # Admin portal pages
├── api/                            # JSON REST API endpoints
├── api-docs/                       # API documentation
├── performers/                     # Performer Database module
│   ├── index.php                   # Directory (search/filter/browse)
│   ├── view.php                    # Detail page (tabbed)
│   ├── edit.php                    # Add/edit performer (admin)
│   ├── booking_list.php            # Booking list w/ filters
│   ├── booking_edit.php / booking_view.php
│   ├── review_edit.php             # Add/edit review (admin)
│   ├── program_catalog.php         # Global program catalog
│   └── ajax_*.php                  # AJAX endpoints
│
├── secure/                         # DB credentials placeholder (env-driven)
├── docker/db_connection.php        # Production env-driven DB shim (baked in via Dockerfile)
├── db/
│   ├── Dockerfile                  # MySQL 8 + baked-in init.sql + migrations + runner
│   ├── entrypoint.sh               # Spawns migration runner, hands off to docker-entrypoint.sh
│   ├── run-migrations.sh           # Container-side migration loop
│   ├── init.sql                    # Complete schema at HEAD
│   ├── seed_dev.sql                # Dev-only admin + test users
│   ├── migrations/                 # NNN_description.sql files
│   └── backups/                    # `make db-dump` output (gitignored)
├── scripts/
│   ├── migrate.sh                  # Host-side migration runner (used by `make db-migrate`)
│   └── backup.sh                   # Cron-friendly mysqldump + optional off-host ship
│
├── css/                            # Custom stylesheets
├── logs/                           # Bind-mounted; gitignored except .gitkeep
└── uploads/                        # User-uploaded photos; named volume in compose
```

## Database tables (overview)

| Table | Purpose |
|---|---|
| `scores` | Core score records (user, team, program, date, totals) |
| `score_responses` | Individual question responses per score |
| `scoring_questions` / `scoring_options` | Configurable questions + options |
| `form_profiles` / `form_questions` / `form_prefill_values` | Form configuration |
| `users` / `teams` / `locations` | Reference data |
| `admins` | Admin login accounts |
| `programming_reports` | Saved report layouts |
| `programs` | Aggregated/denormalized program records |
| `calendar_upload` | Imported calendar events |
| `columns` | Dynamic score column metadata |
| `custom_fields` / `custom_field_options` / `custom_field_team_filter` / `custom_field_form_filter` / `score_custom_field_values` | Custom dropdown fields system |
| `performers` + 13 supporting tables | Performer Database module |
| `impact_event_performers` | Links Impact Score submissions to performers |
| `schema_migrations` | Migration tracking |

## API

All endpoints live under `/api/` and return JSON.

```
GET /api/scores?user_id=1&start_date=2025-01-01&end_date=2025-12-31&limit=50
GET /api/programs
GET /api/summary
```

See `api-docs/` for full documentation.

## Color palette

| Name | Hex |
|---|---|
| Deep Plum | `#480d3c` |
| Fuchsia | `#bb1b51` |
| Parchment | `#f5f2ec` |
