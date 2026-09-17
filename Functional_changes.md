# Application Modernization Standards
## AI Agent Refactoring Guide

This document captures the full set of changes applied to a PHP + Apache + MySQL Docker
Compose application to make it maintainable, versioned, and deployable. Apply every
section to the target application.

The target application shares this structure:
- PHP 8.x + Apache in a Docker container
- MySQL 8.x database container
- Application code in `app/public_html/` (web root), `app/includes/`, `app/secure/`
- `db/init.sql` for schema initialization
- Environment variables via a `.env` file

---

## 1. Dockerfile (Web Image)

**Goal:** The web image must be self-contained. Application code must be baked in so a
versioned/tagged image can be deployed anywhere without source volume mounts.

**Requirements:**
- `COPY` all application directories into the image after `WORKDIR` is set.
- Map directories to match how docker-compose mounts them (preserving runtime paths).
- Exclude the `logs/` directory from the COPY (via `.dockerignore`) and create it in
  the image with correct ownership.
- After all COPYs, set `www-data` ownership and restrict permissions so no file is
  world-writable.

**Pattern to apply:**
```dockerfile
FROM php:8.2-apache

RUN a2enmod rewrite headers
RUN docker-php-ext-install pdo pdo_mysql

COPY apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY app/public_html .
COPY app/includes    ./includes
COPY app/secure      ./secure

RUN mkdir -p logs \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;
```

Adjust source paths to match the target application's directory layout. The container
paths (`./includes`, `./secure`) must match what PHP `require_once` calls expect at
runtime. Verify by grepping all `require_once` statements and confirming every resolved
path is covered by a COPY instruction.

---

## 2. db/Dockerfile (Database Image)

**Goal:** The db image must be self-contained — `init.sql`, all migration files, and the
migration runner are baked in. A user running the released image needs no local `db/`
directory. Migrations run automatically on every container start.

**Directory structure:**
```
db/
  Dockerfile
  init.sql              <- full schema; run by MySQL initdb.d on first container start
  entrypoint.sh         <- wrapper: starts migration watcher, then hands off to mysqld
  run-migrations.sh     <- waits for MySQL ready, applies pending migrations
  migrations/
    .gitkeep
    001_description.sql <- future migrations placed here
```

**`db/Dockerfile` pattern:**
```dockerfile
FROM mysql:8.0

COPY init.sql /docker-entrypoint-initdb.d/init.sql
COPY migrations/ /migrations/
COPY run-migrations.sh /usr/local/bin/run-migrations.sh
COPY entrypoint.sh /usr/local/bin/<appname>-entrypoint.sh

RUN chmod +x /usr/local/bin/run-migrations.sh \
    && chmod +x /usr/local/bin/<appname>-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/<appname>-entrypoint.sh"]
CMD ["mysqld"]
```

**`db/entrypoint.sh` pattern:**
```bash
#!/bin/bash
set -eo pipefail

# Start migration watcher in background; it waits for MySQL ready, then exits.
/usr/local/bin/run-migrations.sh &

# Hand off to MySQL's own entrypoint as PID 1.
exec docker-entrypoint.sh "$@"
```

**`db/run-migrations.sh` pattern:**
```bash
#!/bin/bash
set -eo pipefail

MIGRATIONS_DIR="/migrations"

mysql_cmd() {
    mysql -h 127.0.0.1 -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" "$@"
}

# Wait for MySQL to accept connections (up to 60 s)
attempts=0
until mysqladmin ping -h 127.0.0.1 -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" --silent 2>/dev/null; do
    attempts=$((attempts + 1))
    [ "$attempts" -ge 60 ] && { echo "[migrate] ERROR: timeout waiting for MySQL" >&2; exit 1; }
    sleep 1
done

mysql_cmd -e "CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;"

shopt -s nullglob
files=("${MIGRATIONS_DIR}"/*.sql)
shopt -u nullglob

[ "${#files[@]}" -eq 0 ] && { echo "[migrate] No migration files — nothing to do."; exit 0; }

applied=0
for file in $(printf '%s\n' "${files[@]}" | sort); do
    version=$(basename "$file")
    count=$(mysql_cmd -se "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}';" 2>/dev/null || echo 0)
    if [ "${count}" -eq 0 ]; then
        echo "[migrate] Applying: ${version}"
        mysql_cmd < "${file}"
        mysql_cmd -e "INSERT INTO schema_migrations (version) VALUES ('${version}');"
        applied=$((applied + 1))
    fi
done

echo "[migrate] Done. Applied: ${applied} migration(s)."
```

---

## 3. .dockerignore

**Goal:** Keep secrets out of the web image and reduce build context size.

**Create `.dockerignore` at the project root:**
```
.env
db/
scripts/
Makefile
README.md
.git/
.gitignore
app/public_html/logs/
```

The `db/` directory is excluded from the web image build context — it has its own
`db/Dockerfile` built separately. Adjust the log path to match wherever the application
writes PHP error logs.

---

## 4. .env.example

**Goal:** Every environment variable the application requires must be documented in a
committed template file. Developers copy this to `.env` and fill in real values.
`.env` itself must never be committed.

**Requirements:**
- One variable per line, `KEY=safe_default_or_blank`
- Group variables by concern with `# ── Section ──` header comments
- Provide safe defaults for non-secret variables; use `changeme` placeholders for secrets
- Include ALL variables consumed by PHP, docker-compose, and any bootstrap process

**Minimum sections for a PHP + MySQL app:**
```env
# ── Application ───────────────────────────────────────────────────────────────
APP_ENV=production
APP_DEBUG=false

# ── Database ──────────────────────────────────────────────────────────────────
DB_HOST=db
DB_NAME=
DB_USER=
DB_PASSWORD=changeme
MYSQL_ROOT_PASSWORD=changeme_root

# ── Admin bootstrap ───────────────────────────────────────────────────────────
BOOTSTRAP_ADMIN_TOKEN=change-this-secret-token

# ── Registration ──────────────────────────────────────────────────────────────
# Set to false to disable public self-registration (admin-invite only)
ENABLE_REGISTRATION=true

# ── Session security ──────────────────────────────────────────────────────────
# Set to true when running behind HTTPS
SESSION_SECURE_ONLY=false

# ── Microsoft Entra / O365 SSO (optional) ────────────────────────────────────
MS_ENABLED=false
MS_TENANT_ID=
MS_CLIENT_ID=
MS_CLIENT_SECRET=
MS_REDIRECT_URI=http://localhost:8080/auth/microsoft/callback.php
```

Add application-specific variables below. Match variable names exactly as they appear
in PHP config files and docker-compose environment blocks.

---

## 5. docker-compose.yml (Base — Production / Versioned Release)

**Goal:** The base compose file is what end users and CI/CD use when running a released
image. It contains NO application source volume mounts — the image provides those files.
Only `.env` is mounted at runtime so secrets are never baked in.

**Requirements:**
- No `build:` directive on any service. Image builds are handled by the Makefile.
- `image: <appname>:latest` on the web service (or a pinned version for a release).
- `image: <appname>-db:latest` on the db service (custom image with init + migrations baked in).
- Mount ONLY `.env` read-only into the web root — no source directories.
- Remove `./db:/docker-entrypoint-initdb.d:ro` from the db service — init.sql is baked in.
- db and phpmyadmin services are otherwise identical to the dev setup.

**Web service pattern:**

Replace `env_file: - .env` with an explicit `environment:` block. Docker reads `.env` automatically for variable substitution (`${VAR}` syntax), so the values come from `.env` without passing the file itself into the container as an env dump. The `.env` volume mount is kept separately so the PHP application can read it directly via dotenv.

```yaml
web:
  image: <appname>:latest
  container_name: <appname>_web
  ports:
    - "8080:80"
  environment:
    APP_ENV: ${APP_ENV:-production}
    APP_DEBUG: ${APP_DEBUG:-false}
    DB_HOST: ${DB_HOST:-db}
    DB_NAME: ${DB_NAME:-<dbname>}
    DB_USER: ${DB_USER:-<dbuser>}
    DB_PASSWORD: ${DB_PASSWORD:-changeme}
    BOOTSTRAP_ADMIN_TOKEN: ${BOOTSTRAP_ADMIN_TOKEN:-change-this-secret-token}
    ENABLE_REGISTRATION: ${ENABLE_REGISTRATION:-true}
    SESSION_SECURE_ONLY: ${SESSION_SECURE_ONLY:-false}
    MS_ENABLED: ${MS_ENABLED:-false}
    MS_TENANT_ID: ${MS_TENANT_ID:-}
    MS_CLIENT_ID: ${MS_CLIENT_ID:-}
    MS_CLIENT_SECRET: ${MS_CLIENT_SECRET:-}
    MS_REDIRECT_URI: ${MS_REDIRECT_URI:-http://localhost:8080/auth/microsoft/callback.php}
  volumes:
    - ./.env:/var/www/html/.env:ro
  depends_on:
    db:
      condition: service_healthy
  restart: unless-stopped
```

**Why explicit over `env_file`:** The compose file becomes self-documenting — every variable the container receives is visible at a glance, defaults are explicit, and there is no silent "all vars from file" behaviour that can leak unexpected variables into the container.

**db service pattern:**
```yaml
db:
  image: <appname>-db:latest
  container_name: <appname>_db
  command: --default-authentication-plugin=mysql_native_password
  environment:
    MYSQL_DATABASE: ${DB_NAME:-<dbname>}
    MYSQL_USER: ${DB_USER:-<dbuser>}
    MYSQL_PASSWORD: ${DB_PASSWORD:-changeme}
    MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-changeme_root}
  volumes:
    - <appname>_db_data:/var/lib/mysql
  ports:
    - "3307:3306"
  healthcheck:
    test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -p\"${MYSQL_ROOT_PASSWORD:-changeme_root}\" --silent"]
    interval: 5s
    timeout: 5s
    retries: 30
  restart: unless-stopped
```

**phpmyadmin service pattern:**
```yaml
phpmyadmin:
  image: phpmyadmin:latest
  container_name: <appname>_pma
  environment:
    PMA_HOST: db
    PMA_PORT: 3306
    PMA_USER: ${DB_USER:-<dbuser>}
    PMA_PASSWORD: ${DB_PASSWORD:-changeme}
  ports:
    - "8081:80"
  depends_on:
    db:
      condition: service_healthy
  restart: unless-stopped
```

Note: no `./db` bind mount on the db service and no `env_file` on any service. The custom image handles init and migrations; explicit `environment:` blocks handle all configuration.

---

## 6. docker-compose.override.yml (Dev Overrides)

**Goal:** Docker Compose automatically merges `docker-compose.override.yml` when present.
Place the development-only source volume mounts here so they apply when a developer runs
`docker compose up` from a clone, but do NOT apply when an end user runs only the base
`docker-compose.yml`.

**This file is committed to the repo.** End users pulling only `docker-compose.yml`
never have this file and get the clean image behavior.

**Pattern:**
```yaml
# Development overrides — auto-merged by `docker compose up`.
# Bind-mounts local source directories over the baked image layers for live editing.
# End users running a versioned image do NOT need this file.
services:
  web:
    volumes:
      - ./app/public_html:/var/www/html
      - ./app/includes:/var/www/html/includes:ro
      - ./app/secure:/var/www/html/secure:ro
```

**Why this pattern over a separate dev compose file:**
- `make setup` / `docker compose up` in a clone automatically gets live-edit mounts.
- No `-f` flag required; developers never need to remember a second filename.
- End users pulling a versioned release run `docker compose up` against the base file and
  get self-contained image behavior — no empty mount overlay.

---

## 7. Makefile

**Goal:** All developer and operator workflows are accessible via `make <target>`.
No one needs to remember raw docker commands for routine operations.

**Requirements:**
- `.DEFAULT_GOAL := help`
- `help` target auto-generates from `## comment` annotations via grep/awk
- Load `.env` with `-include .env` + `export` so DB vars are available to `db-*` targets
- Define top-level variables: `IMAGE`, `VERSION`, `WEB`, `DB`, `PMA`, `CONTAINERS`
- `VERSION` defaults to `git describe --tags --always --dirty`, overridable on command line
- Build targets use `docker build` directly — NOT `$(COMPOSE) build`
- Two build targets: `build` (web image) and `build-db` (db image); `build-all` builds both
- `down` must force-remove named containers by name to handle orphans from prior sessions
- Destructive targets (`db-reset`, `clean`) must call `$(MAKE) down` before proceeding

**Variable block:**
```makefile
-include .env
export

COMPOSE    := docker compose
WEB        := <appname>_web
DB         := <appname>_db
PMA        := <appname>_pma
IMAGE      := <appname>
IMAGE_DB   := <appname>-db
CONTAINERS := $(WEB) $(DB) $(PMA)

VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
```

**Full target list — implement all:**

| Target | Description |
|---|---|
| `help` | Auto-generated command list (default target) |
| `setup` | First-time: copy `.env.example`, build both images, start services |
| `up` | Start all services |
| `down` | Stop + force-remove containers including orphans |
| `build` | Build web image; tag as `IMAGE:latest` and `IMAGE:VERSION` |
| `build-db` | Build db image; tag as `IMAGE_DB:latest` and `IMAGE_DB:VERSION` |
| `build-all` | Build both images |
| `rebuild` | `down` + `docker build --no-cache` (both images) + `up` |
| `restart` | Restart without rebuild |
| `logs` | Follow logs; `make logs service=web` to filter by service |
| `ps` | Show container status |
| `shell` | bash shell in web container |
| `db-shell` | MySQL shell in db container |
| `db-migrate` | Apply pending migrations from `db/migrations/` via host-side runner |
| `db-dump` | Dump database to `db/backups/YYYY-MM-DD_HHMMSS.sql` |
| `db-restore` | Restore: `make db-restore file=path/to/dump.sql` |
| `db-reset` | DANGER: destroy db volume, recreate from init.sql |
| `version` | Print current VERSION string |
| `release` | git tag + build both images: `make release VERSION=1.0.0` |
| `clean` | DANGER: remove all containers and volumes |

**Note on build target granularity:** The preferred pattern separates web and db builds into
`build`, `build-db`, and `build-all` as shown below. An earlier implementation merged both
into a single `build` target — this works but prevents rebuilding only one image. Prefer the
separated pattern for new applications.

**Critical implementation patterns:**
```makefile
setup:
	@test -f .env || { cp .env.example .env && echo "Created .env — edit it, then re-run."; exit 1; }
	docker build -t $(IMAGE):latest .
	docker build -t $(IMAGE_DB):latest ./db
	$(COMPOSE) up -d

build:
	docker build -t $(IMAGE):latest .
	docker tag $(IMAGE):latest $(IMAGE):$(VERSION)
	@echo "Tagged: $(IMAGE):$(VERSION)"

build-db:
	docker build -t $(IMAGE_DB):latest ./db
	docker tag $(IMAGE_DB):latest $(IMAGE_DB):$(VERSION)
	@echo "Tagged: $(IMAGE_DB):$(VERSION)"

build-all: build build-db

down:
	$(COMPOSE) down --remove-orphans
	-docker rm -f $(CONTAINERS) 2>/dev/null

release:
	@if echo "$(VERSION)" | grep -q "dirty"; then \
	  echo "Error: uncommitted changes. Commit everything first."; exit 1; fi
	git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	$(MAKE) build-all VERSION=$(VERSION)
	@echo "v$(VERSION) tagged. Push with: git push origin v$(VERSION)"
```

---

## 8. Database Migrations — Two-Track Approach

Two migration mechanisms exist in parallel. Both use the same `schema_migrations` tracking
table and the same `NNN_description.sql` naming convention.

### Track 1: Auto-migration inside the db image (container-side)

`db/run-migrations.sh` is baked into the db image and launched by `db/entrypoint.sh`
on every container start. It waits for MySQL to be ready, then applies any `.sql` files
in `/migrations/` that are not yet in `schema_migrations`.

**Upgrade workflow for image-only deployments:**
```bash
make db-dump                     # back up data first
docker compose down
# update docker-compose.yml to new image tag
docker compose up -d             # migrations run automatically in the background
# check: docker compose logs db
```

**The two-file rule — enforced for every schema change:**

Every pull request that modifies the database schema MUST update two files:

1. **`db/migrations/NNN_description.sql`** — the incremental change that upgrades an existing live database. This is what `make db-migrate` and the container-side runner apply to running deployments.
2. **`db/init.sql`** — the complete current schema for fresh installs. This is baked into the `<appname>-db` image. It must always reflect the full schema at the current HEAD.

Updating only one breaks either fresh installs (missing schema) or existing deployments (no upgrade path). The two files must stay in sync at every commit that changes the schema.

**Adding a new migration:**
1. Add the incremental change to `db/migrations/NNN_description.sql`
2. Apply the same change to `db/init.sql` so the full schema stays current
3. Run `make build-db VERSION=x.y.z` — both files are baked into the image
4. On next `docker compose up`, the runner applies the migration automatically

### Track 2: Host-side runner via Makefile (developer workflow)

`scripts/migrate.sh` runs from the host, connects to the running db container via
`docker exec`, and applies pending `db/migrations/*.sql` files. Used during development
when the container is already running and you want to apply a new migration without
rebuilding the image.

**Usage:** `make db-migrate` — safe to run repeatedly; already-applied files are skipped.

**`scripts/migrate.sh` requirements:**
1. Load `.env` from project root (`set -a; source .env; set +a`)
2. Connect to running db container by its container name
3. Create `schema_migrations` table if not exists
4. Iterate `db/migrations/*.sql` in filename sort order
5. Skip files already recorded in `schema_migrations`
6. Apply unapplied files via `docker exec -i <db_container> mysql ...`
7. Record each applied file; print `Applied: N  |  Already current: N`

**Migration file naming convention:** `NNN_description.sql`
(e.g. `001_add_oauth_fields.sql`, `002_survey_archive_flag.sql`)

Never modify an already-applied migration file — add a new one instead.

---

## 9. README.md

**Goal:** The README accurately documents how to build, run, and operate the application
using the Makefile. Raw `docker-compose` commands must not appear in the Quick Start.

**Quick Start section — replace existing content with:**
```markdown
**Requirements:** Docker, Docker Compose, and GNU Make.

    make setup      # first run: copies .env.example, builds images, starts services
    make up         # subsequent starts
    make help       # list all available commands

The bootstrap admin URL is printed by `make setup`.
```

**Build & Operations section — must cover:**
- Table of all Makefile targets and descriptions
- Dev vs. production compose: explain base file + override pattern and two-image architecture
- Database backup and restore command examples
- Migration workflow — both tracks: auto-migration on container start, and `make db-migrate`
- Version tagging: `make version`, `make build VERSION=x.y.z`, `make build-all`, `make release VERSION=x.y.z`
- Warning that `db-reset` and `clean` are destructive/dev-only

**Add a Contributing section** that explicitly documents the two-file rule for schema changes:
```markdown
## Contributing

### Schema changes — the two-file rule

Any pull request that modifies the database schema **must** update two things:

1. **`db/migrations/NNN_description.sql`** — incremental migration for existing deployments
2. **`db/init.sql`** — complete current schema for fresh installs (baked into the db image)

Updating only one breaks either fresh installs or existing deployments. Both must stay in sync.

After any schema change, rebuild both images before testing:

    make build-all
    make db-reset   # development only
```

**Replace all inline docker commands elsewhere in the README:**
- `docker-compose down && docker-compose up -d` → `make restart`
- `docker-compose up -d` → `make up`
- `docker-compose build && docker-compose up -d` → `make build-all && make up`

---

## 10. Verification Checklist

After applying all changes, verify the following before closing out the refactor:

- [ ] `Dockerfile` — COPY covers all directories referenced by PHP `require_once`
- [ ] `Dockerfile` — permissions RUN step sets `www-data` ownership
- [ ] `db/Dockerfile` — `init.sql`, `migrations/`, `run-migrations.sh`, `entrypoint.sh` all COPY'd
- [ ] `db/entrypoint.sh` — starts migration watcher in background, hands off to `docker-entrypoint.sh`
- [ ] `db/run-migrations.sh` — waits for MySQL, creates tracking table, applies pending files
- [ ] `.dockerignore` — `.env` excluded; `db/` excluded from web image; log directories excluded
- [ ] `.env.example` — all variables from PHP config and docker-compose documented
- [ ] `docker-compose.yml` — base file; no `build:`, no `env_file:`, no app source mounts; all services use explicit `environment:` with `${VAR:-default}`; db uses custom image
- [ ] `docker-compose.override.yml` — dev override; adds `./app/public_html`, `./app/includes`, `./app/secure` mounts
- [ ] `Makefile` — `build` and `build-db` targets both present; `setup` builds both images
- [ ] `scripts/migrate.sh` — created and executable; host-side runner for dev workflow
- [ ] `db/migrations/.gitkeep` — folder exists and is tracked by git
- [ ] `README.md` — Quick Start uses `make`; Build & Operations section covers both migration tracks
- [ ] `README.md` — Contributing section present with two-file rule and `make build-all` + `make db-reset` instructions
- [ ] Web image self-contained: `docker run --rm <image>:latest ls /var/www/html` shows app files
- [ ] DB image self-contained: `docker run --rm <image>-db:latest ls /migrations` shows `.gitkeep`
- [ ] Dev volumes work: `make up` with override, edit a PHP file, reload browser — change visible
- [ ] Auto-migration: fresh `docker compose up` logs show `[migrate] Done. Applied: 0 migration(s).`
- [ ] Host migration runner: `make db-migrate` on a running stack completes with `Already current: 0`
- [ ] Version tagging: `make build-all VERSION=1.0.0` produces both `<image>:1.0.0` and `<image>-db:1.0.0`
- [ ] Two-file rule: `db/init.sql` and `db/migrations/` are in sync — `init.sql` reflects full schema at HEAD
