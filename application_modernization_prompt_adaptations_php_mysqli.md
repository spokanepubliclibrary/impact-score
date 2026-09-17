# Stack Adaptations — PHP (MySQLi, sessions) + MySQL + Bootstrap 5 CDN frontend

Paste this **after** the main `application_modernization_prompt.md` (or include it inside the same prompt before the agent starts work). It overrides and refines a few specific passes for this stack.

## Tech stack summary the agent should assume

- **Backend:** PHP 8.x using **MySQLi** (procedural or object-oriented) and native PHP sessions. Likely *no* Composer dependencies — confirm during audit.
- **Database:** MySQL 8.x.
- **Frontend:** Bootstrap 5, Chart.js, html2canvas, jsPDF, and the Montserrat font — almost certainly loaded from CDN URLs in `<link>` and `<script>` tags rather than vendored locally. Confirm during audit.

## Pass 3 adaptation — Dependency management (conditional)

The original Pass 3 assumed a Composer-using PHP app. For this stack, **first determine whether Composer is needed at all**:

1. Search the codebase for namespaced classes used by the application:
   ```
   grep -rEn '^use [A-Z][a-zA-Z_\\]+' app/ src/ public_html/ 2>/dev/null
   ```
2. Search for `composer.json` and `composer.lock` anywhere in the repo.
3. Search PHP files for `vendor/autoload.php` or `composer/autoload`.

**If none of those return any results:** the application is pure MySQLi + sessions + procedural PHP. **Skip Composer entirely.** Do not invent a Composer manifest, do not convert the Dockerfile to multi-stage. Pass 3 becomes "verify the Dockerfile COPYs cover every `require_once` path" only — same as before, just without the vendor work.

**If any of them return results:** apply the original Pass 3 in full (composer.json, multi-stage Dockerfile, `make composer-install` target, `.dockerignore` entries for `vendor/`).

## Pass 3 adaptation — Frontend asset audit (new step)

Bootstrap 5, Chart.js, html2canvas, jsPDF, and Montserrat are typically CDN-loaded. Verify this is the case for the target application:

```
grep -rEn '<(script|link).*(bootstrap|chart\.js|html2canvas|jspdf|montserrat)' \
  app/ public_html/ 2>/dev/null
```

For each match, classify:

- **CDN URL** (e.g., `https://cdn.jsdelivr.net/...`, `https://cdnjs.cloudflare.com/...`, `https://fonts.googleapis.com/...`): no Dockerfile change needed. Note for the user: production deployments behind a strict CSP may need to allowlist these origins.
- **Local file** (e.g., `/css/bootstrap.min.css`, `/js/chart.umd.js`): confirm the file lives somewhere the Dockerfile's `COPY app/public_html .` (or equivalent) will pick up. If the asset is in a path the Dockerfile doesn't cover, add a COPY for it.
- **Built artifact** (e.g., references to `dist/`, `build/`, or imports requiring `node_modules`): unusual for this stack but possible. If found, flag for user discussion — the agent should not silently add a Node toolchain to the Dockerfile without confirmation.

Output the classification table to the user before changing anything.

## Pass 4c adaptation — Config preflight for MySQLi

The original Pass 4c describes a `.env` preflight check. Apply it identically — the language doesn't matter. But when reading the application's existing config-loading code, expect MySQLi connection patterns rather than PDO:

```php
// Typical MySQLi pattern the audit will encounter
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);
if ($mysqli->connect_error) {
    error_log("DB connection failed: " . $mysqli->connect_error);
    // ...
}
```

The preflight logic (refuse to start if `APP_ENV=production` and any secret contains `changeme`) is identical regardless of MySQLi vs PDO. Place it after `.env` is loaded but before the database connection is opened.

## Dockerfile adaptation — PHP extensions

The IT spec's web Dockerfile example installs `pdo` and `pdo_mysql`:

```dockerfile
RUN docker-php-ext-install pdo pdo_mysql
```

For this stack, the application uses **MySQLi**, which is a different extension. Install it instead (or alongside, if the app uses both):

```dockerfile
RUN docker-php-ext-install mysqli
```

If the application uses MySQLi only, omit `pdo` and `pdo_mysql` entirely — they add image size and attack surface for no benefit. Confirm by grepping the codebase for `new PDO(` and `PDO::`. Zero matches = drop the PDO extensions from the Dockerfile.

## Pass 6 adaptation — Schema rename methodology

The methodology in the original prompt is database-agnostic and applies cleanly to MySQL. No changes needed.

One note specific to MySQLi: when verifying renames, also grep for any places the application reads column data via `$row['old_column_name']` array indexing — these need updating in addition to SQL strings. PDO's named binding makes this slightly more discoverable; MySQLi's `fetch_assoc()` returns the same plain array structure and is just as easy to grep for, but it's worth being thorough:

```
grep -rEn '\["old_column_name"\]|\['"'"'old_column_name'"'"'\]' app/
```

## Pass 7 adaptation — Seed data and bcrypt

PHP's `password_hash()` works identically regardless of MySQLi vs PDO. The seed file pattern from the original prompt applies as-is. The agent can generate bcrypt hashes from a transient container:

```
docker run --rm php:8.2-cli php -r 'echo password_hash("admin", PASSWORD_BCRYPT) . "\n";'
```

PHP's `password_verify()` accepts both `$2y$` and `$2b$` bcrypt variants, so the agent can also use Python's `bcrypt` library in the sandbox if PHP isn't available — the hashes will be interoperable.

## Things that are unchanged

The following passes apply identically and need no adaptation:

- Pass 1 — File hygiene
- Pass 2 — Makefile build target split
- Pass 4a — Database server hardening (drop host port, move admin UI to dev override, drop legacy auth flags)
- Pass 4b — Web service healthcheck and log bind-mount
- Pass 4d — `db/init.sql` audit for committed user data
- Pass 4e — Registry-based deploy workflow
- Pass 4f — `scripts/backup.sh`
- Pass 4g — `.env.example` production guidance
- Pass 4h — `deployment_decisions.md`
- Pass 5 — README spec compliance
- Pass 8 — macOS bind-mount placeholder (still relevant if the user is on macOS with the repo in CloudStorage/Dropbox)

## What to ask the user up front

Before starting Pass 1, ask the user:

1. Is the workspace on macOS and inside CloudStorage/Dropbox/iCloud? If yes, Pass 8 is mandatory; if no, Pass 8 is skippable.
2. Does the application have a `Functional_changes.md` (or equivalent IT spec) at the repo root? If yes, read it first; if no, work from the methodology in the main prompt and flag anything that requires an explicit IT decision.
3. Are there any in-flight migrations or local changes that should be committed before modernization work begins?
