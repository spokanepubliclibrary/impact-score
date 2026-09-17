# Application Modernization Agent Prompt

Paste the contents below as your opening message to a Claude agent (Cowork mode or Claude Code) working on the new repository. The prompt assumes the new application's workspace folder has been mounted/selected.

Adapt the PHP-specific bits if your new application uses a different stack — the methodology generalizes, only the language-specific examples need swapping.

---

## How to use this prompt

1. Make sure `Functional_changes.md` (your IT modernization spec) is at the root of the new repo.
2. Start a fresh Cowork session with the repo folder selected.
3. Paste everything below the `─── PROMPT START ───` line as your first message.
4. Work through each pass with the agent, reviewing and committing between passes.

---

─── PROMPT START ───

# Mission

This repository needs to be modernized for production deployment using the standards in `Functional_changes.md` at the project root. Read that file first — it is the baseline spec from IT. Your job is to bring this repo into compliance with that spec **and** to apply the additional production-hardening improvements described below that go beyond what the spec covers.

Work in discrete passes. Do **not** try to do everything in one shot. After each pass, summarize what changed, list the suggested commit chunks, and wait for me to review before moving on.

# Working principles

Before touching anything in any pass:

1. **Audit first.** Read every file the pass will touch. Grep for every identifier or pattern that the pass affects. Build a complete picture of the current state and the diff you intend to produce.
2. **Flag findings before acting on them.** If during the audit you discover something unexpected — real production data committed to source control, a missing dependency the build doesn't provide, secrets hardcoded somewhere, broken `require`/`import` paths — stop and report it to me before changing anything destructive.
3. **Confirm intentional deviations from spec.** The IT spec is a baseline. Some of the improvements below intentionally deviate from it (e.g., removing the database host port that the spec leaves open). Always describe the deviation, the reason, and ask before applying.
4. **Verify statically after each change.** YAML parses, Makefile dry-runs produce the expected commands, grep finds zero stragglers after a rename. You will not have Docker available — the user runs the dynamic checks (actual builds, container starts, healthchecks) on their machine.

# Pass 1 — File hygiene

Audit the repo for cruft that should never have been committed:

- Duplicate or stale `.env.example` files at nested paths (e.g., inside `app/public_html/`). The canonical template lives at the project root; nested duplicates with diverging content are confusing and ship inside the production image. Delete them with `git rm`.
- Tracked log files (PHP `php-errors.log`, application access logs, anything in a `logs/` directory). These are runtime artifacts that should never be in source control. `git rm` them and update `.gitignore` to keep future ones out, with a `.gitkeep` exception so the directory itself stays tracked.
- Nested `.gitignore` files that contradict the root `.gitignore`. Audit and reconcile.

Output: a clean working tree where the only directory artifacts in `logs/` are `.gitkeep`. Update the root `.gitignore` if needed. Do not touch the application code in this pass.

# Pass 2 — Build system alignment (Makefile)

The IT spec's preferred build pattern uses three separate Makefile targets:

- `build` — web image only, tagged as `IMAGE:latest` and `IMAGE:$(VERSION)`
- `build-db` — db image only, same tagging
- `build-all` — depends on both

If the existing Makefile has a single `build` target that does both, split it. Add the `IMAGE_DB` variable. Update `.PHONY` to include the new targets. Update `setup`, `rebuild`, and `release` to call `$(MAKE) build-all` where appropriate. Verify by running `make help` and `make -n build-all VERSION=1.0.0` — confirm the generated docker commands tag both `:latest` and `:VERSION` for both images.

# Pass 3 — Discover and fix dependency management

This is where most repos hide a serious gap.

Grep every `require_once`, `require`, `include`, `include_once` (or in non-PHP stacks: every `import`, `require()`, `use` statement) in the application source. For each one, resolve the literal path against the container's runtime paths — i.e., walk through what the Dockerfile COPYs and where it puts each directory.

**Find every path that the runtime expects but the image doesn't provide.** The classic instance is `__DIR__ . '/../vendor/autoload.php'` resolving to `/var/www/html/vendor/autoload.php` when no Composer toolchain exists in the build and no `composer.json` is checked in. In Node projects the equivalent is `node_modules/` referenced by `require()` without an `npm install` step in the Dockerfile.

Once found:

1. Stop and report the finding. Do not fix it unilaterally — there may be a deliberate reason the dependency isn't managed (vendored copy elsewhere, runtime download, etc.).
2. If the user wants you to fix it, add proper dependency management:
   - Create the manifest file (`composer.json`, `package.json`, `requirements.txt`, etc.) with pinned versions matching the libraries actually used in the code (grep `use` statements / `import` statements to enumerate).
   - Convert the Dockerfile to a multi-stage build: a builder stage runs the dependency installer with `--no-dev` / `--production` / equivalent flags, then the final stage copies the built artifact into the runtime image. Copy the manifest before the source so the build cache survives source changes.
   - Add a Makefile target (`composer-install`, `npm-install`, etc.) that runs the installer in a transient container so contributors don't need the toolchain installed locally. Wire it into `make setup`.
   - Add the manifest's dev-only output directory (`vendor/`, `node_modules/`) to `.dockerignore` so the dev tree doesn't leak into the build context. Add a comment explaining why.
3. Verify Dockerfile path coverage by mapping every COPY destination against every `require_once` path. They must all resolve.

# Pass 4 — Production hardening (goes beyond IT spec)

The IT spec describes the structure but doesn't make several security/operational decisions that production deployment requires. Apply these defaults, flagging each as a deviation from the spec.

## 4a — Database server hardening

- **Drop the database host port.** The spec example publishes `3307:3306`. Remove this from the base compose. Containers still reach the database via the internal Docker network using the hostname `db`. Add the port back in `docker-compose.override.yml` so developers can still connect with a local client.
- **Move phpMyAdmin (or equivalent admin UI) to the dev override only.** Production deployments should not expose a database admin UI by default. Use `make db-shell` (a CLI inside the running container) for prod admin tasks.
- **Drop legacy auth flags.** If the db service passes `--default-authentication-plugin=mysql_native_password` (or similar), remove it so the modern default (`caching_sha2_password` for MySQL 8) is used.

## 4b — Application observability and resilience

- **Add a healthcheck to the web service.** The spec only includes a healthcheck on the db. Add one to the web container using a small HTTP probe (e.g., `curl -fsS http://localhost/ >/dev/null || exit 1` for PHP+Apache). This makes Docker's `restart: unless-stopped` actually responsive to dead processes.
- **Bind-mount the log directory in the base compose** (e.g., `./logs:/var/www/html/logs`) so error logs survive container replacement. Add `logs/.gitkeep`, gitignore the directory contents. Note in the README that production deployments can replace this bind with a Docker log driver if the org has a central log system.

## 4c — Secret-handling preflight

Audit the application's config-loading code (e.g., `app/includes/config.php` for PHP). After it loads `.env`:

- If `APP_ENV=production`, scan secret variables (`DB_PASSWORD`, `BOOTSTRAP_ADMIN_TOKEN`, `MS_CLIENT_SECRET` if SSO is enabled, etc.) for literal placeholder strings like `changeme` or `change-this-secret-token`.
- If any placeholder is detected, refuse to start: log a CRITICAL line and return a 500 from the very first request.

This catches the classic footgun of deploying with `.env.example` defaults.

## 4d — Initial data hygiene

This step is critical and easy to miss.

- **Read `db/init.sql` (or equivalent schema file) in full.** Confirm it is schema-only.
- If you find `INSERT` statements, identify what kind of data they contain. Three possibilities:
  - **Reference data** (default question templates, lookup tables) — fine to keep, but flag for confirmation.
  - **Test/seed data** (an "Improvement" survey with example responses) — should be removed and moved to a separate `db/seed_dev.sql` file.
  - **Real production data** — this is a privacy/security finding. Stop and report immediately. Real email addresses, real password hashes (even bcrypt), real user content should never be in source control. The data must be removed from the current file, and the user should be advised that the git history still contains it (the decision to rewrite history is theirs; bcrypt hashes are slow to crack but should be treated as compromised).
- After removing INSERTs, also remove `AUTO_INCREMENT=N` overrides so fresh installs start IDs at 1.
- If init.sql came from `mysqldump`, prefer to rewrite it as a clean schema file with `CREATE TABLE` statements that include their indexes and foreign keys inline. Easier for humans to read than separate `ALTER TABLE` statements at the bottom.

## 4e — Registry-based deploy workflow

Add `push` and `pull` Makefile targets parameterized by a `REGISTRY` variable. If `REGISTRY` is empty, the target must error with a helpful usage message. When set, tag the local images with the registry prefix and push/pull both web and db.

## 4f — Backup automation

Add `scripts/backup.sh` — a cron-friendly wrapper around `mysqldump` (or equivalent for the stack). Requirements:

- Load `.env` from project root.
- Dump with `--single-transaction --quick --routines --triggers` so the running database isn't locked.
- Pipe through `gzip`.
- Apply a configurable retention policy (e.g., 30 days).
- Support an optional off-host destination (`BACKUP_REMOTE`) — recognize `s3://...` and route to `aws s3 cp`, otherwise fall back to `rsync`.
- Exit non-zero on any failure so cron sends an alert.

## 4g — Production-aware .env.example

Update `.env.example` with inline comments that document:

- How to generate strong values (e.g., `openssl rand -base64 32` for passwords, `openssl rand -hex 32` for tokens).
- When `SESSION_SECURE_ONLY` must be `true` (behind HTTPS).
- Production requirements for OAuth redirect URIs (must be `https://`, must match the IdP registration exactly).
- The lifecycle of single-use tokens (e.g., bootstrap admin token should be rotated after first use).

## 4h — Deployment decisions document

Some Pass 4 items are genuinely user/IT decisions, not unilateral defaults. Produce a separate `deployment_decisions.md` covering at minimum:

- TLS termination strategy (upstream proxy vs. container TLS vs. sidecar)
- Image registry choice (internal vs. ghcr vs. dockerhub)
- Production `.env` ownership and storage location
- Backup destination, schedule, retention
- Log persistence strategy (bind-mount vs. log driver)

Each item: current state, options with tradeoffs, recommended default, blank `Decision:` line for IT to mark up.

# Pass 5 — README spec compliance

After the structural work, sweep the README for the spec's explicit requirements:

- Quick Start must use `make`, not raw `docker compose`.
- The Build & Operations section must cover both migration tracks (auto-migration on container start and host-side `make db-migrate`).
- The Contributing section must explicitly document the two-file rule for schema changes: any change touches both `db/migrations/NNN_description.sql` (for upgrades) and `db/init.sql` (for fresh installs).
- **Replace every raw `docker compose` / `docker-compose` command with its `make` equivalent.** Grep `^\s*docker(-|\s)compose ` against the README — there should be zero matches after this pass. File-name references in prose are fine; only inline runnable commands need replacing.
- Add a Production Deployment section documenting the registry-based release workflow, `.env` ownership recommendations, TLS expectations, log persistence behavior, and healthcheck observability.

# Pass 6 — Schema renames (only if user requests)

If the user asks to rename schema identifiers (tables, columns, indexes, FKs), follow this methodology:

1. **Grep first, never blind sed.** Enumerate every reference across SQL, application code, comments, error messages, and HTML/template form input names.
2. **Replace longest strings first** to avoid substring conflicts. For example: if renaming `demo_question_surveys` and `demo_questions`, replace `demo_question_surveys` first, then `demo_question_id`, then `demo_questions`, then `demo_answers`.
3. **Update `init.sql` to the new names** (the spec's two-file rule says `init.sql` is the schema at HEAD).
4. **Write an idempotent migration script** for existing deployments. The pattern: use a stored procedure that checks `information_schema.tables` for the old name before running any RENAME. Wrap the whole thing in `DELIMITER //` so MySQL parses it correctly.
5. **Pre-populate `schema_migrations` from init.sql** with the migration filename. This makes fresh installs skip the migration cleanly (because init.sql already has the new names) while existing deployments still run it via the runner.
6. Verify by grepping the entire repo for the old identifiers — the only legitimate remaining references should be inside the migration file itself (which mentions the old names because that's literally what it's renaming).

# Pass 7 — Dev seed convenience

Once the production-grade architecture is solid, add a dev convenience layer so contributors don't have to manually create admin accounts every time:

- `db/seed_dev.sql` — `INSERT IGNORE` statements creating one admin and a few test users with weak, easy-to-type passwords. Pre-compute bcrypt hashes using a Python or PHP one-liner. Document the cleartext credentials in a comment at the top of the file so future-you can recover them.
- `make seed-dev` Makefile target — refuses to run when `APP_ENV=production`, otherwise pipes the seed file into the running db container.
- Document `make seed-dev` in the README's Common commands table.

Never bake the seed file into the production image. It lives in the repo for dev convenience; the production image build excludes `db/` via `.dockerignore` already, so the file naturally stays out.

# Pass 8 — macOS bind-mount gotcha (if applicable)

If the user is on macOS and their workspace lives in CloudStorage/Dropbox/iCloud, the `./env:/var/www/html/.env` bind mount will fail at container start with "mountpoint is outside rootfs" because virtiofs-backed bind mounts can't nest cleanly when the parent directory is also bind-mounted.

Fix: create an empty placeholder file at `app/public_html/.env` (or wherever the dev override bind exposes the web root). The real `.env` overlay then mounts onto this existing inode. Add the placeholder path to `.dockerignore` so it never ships in the production image.

This isn't in the IT spec — it's a Docker-on-macOS interaction discovered during testing.

# Verification gates (apply to every pass)

Before declaring a pass complete:

- `make help` lists every new target with its docstring.
- `make -n <target>` dry-runs produce the expected commands.
- YAML compose files parse cleanly (`python3 -c "import yaml; yaml.safe_load(open('docker-compose.yml'))"`).
- For rename passes: a final grep across the entire repo for the old identifiers returns zero matches outside legitimate contexts (migration files, comments explaining the history).
- The user runs the dynamic checks (`make rebuild`, `make setup`, `docker run --rm <image>:latest ls /var/www/html`, watching `make logs service=db` for `[migrate] Done. Applied: N migration(s).`) on their own machine.

# What to flag for user confirmation, never decide unilaterally

- Whether to rewrite git history to remove discovered production data.
- The choice of image registry (cannot guess the org's infrastructure).
- TLS termination strategy.
- Backup destination and schedule.
- Whether to rename schema identifiers (always confirm scope before sed).
- Any deviation from the IT spec — describe the reason and ask before applying.

# Output format for each pass

End each pass with:

1. A concise summary of what changed (file names + one-line description each).
2. Any findings that need user attention.
3. Suggested commit chunking — small, focused commits grouped by intent.
4. Specific commands the user should run on their machine to validate.
5. The next pass to consider, with a one-line preview of its scope.

─── PROMPT END ───

---

## Notes for adapting this prompt

**If the new application uses a different stack:**

- Replace PHP-specific references (`require_once`, `composer.json`, `vendor/`, `php-errors.log`) with the equivalents for your stack:
  - Node: `require()` / `import`, `package.json`, `node_modules/`, application logs
  - Python: `import`, `requirements.txt` or `pyproject.toml`, `.venv/` or site-packages
  - Ruby: `require`, `Gemfile`, `vendor/bundle`
- Replace MySQL-specific references (`mysqldump`, `caching_sha2_password`) with the database the new application uses (Postgres, SQLite, etc.).
- Replace web-server specifics (Apache, `apache.conf`) with the actual server (nginx, Caddy, application-embedded, etc.).
- The methodology (audit, hardening, deploy workflow) stays the same regardless of stack.

**If the IT spec is different from `Functional_changes.md`:**

- The audit phase (Pass 1's framing) still applies: read the spec first, then audit against it.
- The Pass 4 hardening goes beyond any reasonable spec — keep that section even if your spec doesn't mention healthchecks, port hardening, etc.
- If your spec is more permissive (e.g., allows db host port in prod), trust the spec but mention the option in `deployment_decisions.md`.

**If you want to extend this prompt:**

- Add a Pass 9 / Pass 10 covering anything specific to the new application's domain.
- The prompt is designed to be extended at the bottom without disturbing the earlier passes.
