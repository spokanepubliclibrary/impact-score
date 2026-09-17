# Deployment Decisions — Impact Score Dashboard

Companion document to `Functional_changes.md`. The structural modernization
(image-based deploy, multi-stage Dockerfile, base/override compose split,
secret preflight, db image with auto-migration, Makefile-driven workflows) is
complete. The decisions below are the items that depend on IT's preferences,
network topology, and operational standards — things the application code
should not choose unilaterally.

Each section lists the current state, the realistic options, the recommended
default, and a blank `Decision:` line. Mark up this file before the first
production deploy. Re-revisit any time the relevant decision changes.

---

## 1. TLS / HTTPS termination

**Current state.** The web container speaks plain HTTP on port 80, published
to host port 8080. `.env.example` has `SESSION_SECURE_ONLY=false` for local
dev. Production requires HTTPS — both as a baseline and (later) for any SSO
integration.

**Options.**

- **A. Upstream reverse proxy (recommended).** Terminate TLS at nginx, Caddy,
  HAProxy, Cloudflare, IIS, or the org's load balancer. The Apache container
  stays HTTP-only on an internal port. Cert rotation lives with the proxy.
  Most common pattern.
- **B. Sidecar TLS terminator.** Add a third service (Caddy / Traefik) to
  the compose file. Auto-issues Let's Encrypt certs against a DNS name.
  Lower complexity than container-internal TLS.
- **C. Container-internal TLS.** Mount certs and configure Apache for SSL.
  More moving parts inside the image; cert rotation is harder.

**Knock-on changes once HTTPS lands.**

```env
SESSION_SECURE_ONLY=true
```

Plus any OAuth redirect URIs must use `https://` and match the IdP
registration exactly.

**Recommendation:** A — terminate at whatever proxy IT already runs.

**Decision:** _______________

---

## 2. Image registry

**Current state.** `make build-all` builds local images `impact-score:VERSION`
and `impact-score-db:VERSION`. `make push REGISTRY=… VERSION=…` and `make
pull REGISTRY=… VERSION=…` exist but no registry is configured.

**Options.**

- **A. Internal registry the organization runs** (Harbor, GitLab CR, Nexus).
  Best fit if IT already operates one.
- **B. GitHub Container Registry (ghcr.io).** Free for private repos under
  reasonable team plans; integrates with GitHub auth.
- **C. Cloud-native (ECR / GCR / ACR).** Appropriate if the production
  environment already lives in that cloud.
- **D. Docker Hub.** Workable; least preferred (rate limits, occasional
  outages affect deploys).

**Recommendation:** A if IT has one, else B.

```bash
make release REGISTRY=ghcr.io/your-org VERSION=1.0.0
make push    REGISTRY=ghcr.io/your-org VERSION=1.0.0
# On the prod host:
make pull    REGISTRY=ghcr.io/your-org VERSION=1.0.0 && make up
```

**Decision:** _______________

---

## 3. Production `.env` ownership

**Current state.** `.env` is gitignored. There is no committed mechanism for
provisioning it on a production host. The image expects `.env` to be present
next to `docker-compose.yml`; it is bind-mounted into the container read-only.

**Things IT should decide.**

- **Owner / permissions.** Suggested: `root:root`, mode `0600`. Only the
  service account running `docker compose` reads it.
- **Storage location.** Suggested: outside the cloned repo at e.g.
  `/etc/impact-score/.env`. `docker-compose.yml` then references it by an
  absolute path or via a symlink.
- **Generation / rotation.** Baseline:
  `openssl rand -base64 32` for `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD`,
  `openssl rand -hex 32` for `BOOTSTRAP_ADMIN_TOKEN` (rotate after first use).
- **Secrets manager integration (optional).** If the org uses Vault / Azure
  Key Vault / AWS Secrets Manager, a wrapper script can render `.env` at
  boot. Higher operational lift, much safer.

**Decision (storage):** _______________
**Decision (rotation cadence):** _______________
**Decision (secrets manager):** _______________

---

## 4. Database backup strategy

**Current state.** `scripts/backup.sh` performs a `mysqldump
--single-transaction --quick --routines --triggers` of the running container,
pipes through gzip, and writes to `db/backups/YYYY-MM-DD_HHMMSS.sql.gz`. It
supports `BACKUP_REMOTE=s3://…` or rsync targets and a `BACKUP_RETENTION_DAYS`
window (default 30). It is not yet scheduled — nothing runs it.

**Things IT should decide.**

- **Schedule.** Nightly is typical. A `cron` line on the host:
  ```
  30 2 * * * www-data /opt/impact-score/scripts/backup.sh >> /var/log/impact-backup.log 2>&1
  ```
- **Off-host destination.** Backups on the same host they protect are not
  backups. Pick one:
  - S3 (`BACKUP_REMOTE=s3://bucket/impact-score/`)
  - rsync target (`BACKUP_REMOTE=user@host:/backups/impact-score/`)
  - Azure Blob, organization NAS, etc.
- **Retention.** Default 30 days local. Consider also: weekly snapshots for a
  year on the remote.
- **Encryption at rest** on the remote target. Backups contain submitter
  identities and program data — encrypt.
- **Restore drill cadence.** A never-restored backup is hope, not a backup.
  Suggested: quarterly tabletop restore into a staging container.

**Decision (schedule):** _______________
**Decision (destination):** _______________
**Decision (retention):** _______________
**Decision (encryption):** _______________
**Decision (restore drill cadence):** _______________

---

## 5. Log persistence

**Current state.** Base compose bind-mounts `./logs:/var/www/html/logs` so
PHP error logs survive container replacement. Apache access/error logs go to
container stdout/stderr (visible via `make logs service=web`) but vanish on
container removal.

**Options.**

- **A. Keep bind mount + logrotate.** Install host-side `logrotate` to rotate
  and compress the bind-mount target. Simplest.
- **B. Docker logging driver.** Replace the bind with `logging.driver:
  fluentd` / `syslog` / `gelf` / `json-file` with rotation. Centralizes logs
  off-host. Often what the org already runs.
- **C. Sidecar log shipper.** Run Vector / Fluent Bit / Promtail to tail the
  directory and ship to Loki / Splunk / ELK. Heaviest; rarely needed for an
  app this size.

**Recommendation:** A initially; promote to B once IT decides which central
log system applies.

**Decision:** _______________

---

## 6. Credential rotation follow-up (Pass 1 audit)

**Finding.** `secure/db_connection.php` previously contained live production
credentials for `splsqldb1.spokanelibrary.org` (user `impact_sqladmin`,
plaintext password). The file has been replaced with a placeholder, but
**the credentials remain in git history**.

**Required follow-up.**

1. **Rotate the password** for `impact_sqladmin` on the database server.
   Treat the previous value as compromised regardless of any other decision.
2. **Decide whether to rewrite git history** to scrub the secret. Tools:
   `git filter-repo`, BFG Repo-Cleaner. Coordinate with anyone who has
   cloned the repo — they'll need fresh clones.

**Decision (rotate password):** done / scheduled for: ____________________
**Decision (rewrite history):** yes / no / decide-later: _________________

---

## 7. Bundled phpMyAdmin removal (Pass 1 audit)

**Finding.** The `my/` directory under the repo root contained a complete
phpMyAdmin source tree (~60 vendor packages). Apache was serving it on port
8080 alongside the application — any phpMyAdmin CVE would have been an
application-level RCE. The separate `phpmyadmin` container on port 8081
(now dev-override-only per §8 below) provides the same admin UI cleanly.

**Action.** `my/` is queued for deletion via `cleanup_pass1.sh`.

**Optional hardening.** Add an Apache `<Location /my>` deny block so a future
accidental reintroduction cannot be browsed:

```apache
<Location /my>
    Require all denied
</Location>
```

**Decision:** add the deny block? yes / no: _______________

---

## 8. Spec deviations applied as Pass 4 defaults

These were applied as defaults but explicitly flagged as deviations from the
spec example.

| Item | Spec example | Applied | Reason |
|---|---|---|---|
| DB host port `3307:3306` | Published in base | Moved to dev override | No MySQL exposure in prod; CLI access via `make db-shell`. |
| `--default-authentication-plugin=mysql_native_password` | Used on db service | Removed | MySQL 8 `caching_sha2_password` default is strictly stronger. |
| phpMyAdmin in base compose | Included | Moved to dev override | No DB admin UI exposed in prod. `make db-shell` covers admin needs. |
| `pdo` + `pdo_mysql` extensions | Installed | Removed | App uses MySQLi only (zero `new PDO(` in app code). |

**Decision (restore any of these?):** if any environment needs one of these
restored (e.g., legacy reporting tool that requires the DB port), say so:

**Decision:** _______________

---

## 9. Admin bootstrap on fresh installs (carry-forward)

**Finding.** Previous `init.sql` shipped a default admin (`admin` / `changeme`)
hashed with bcrypt. The new `db/init.sql` does not — fresh installs have no
admin account. Production must bootstrap an admin one of two ways:

- **Dev workflow.** `make seed-dev` applies `db/seed_dev.sql` (admin + a few
  test users with documented weak passwords). Refuses to run when
  `APP_ENV=production`.
- **Production workflow.** The application code does **not yet** have a
  `BOOTSTRAP_ADMIN_TOKEN`-redeeming endpoint; the variable exists in
  `.env.example` and the secret preflight gates it, but no PHP route consumes
  it. This is a TODO that production deployment needs.

**Two paths to closing the gap.**

- **A. Build the bootstrap endpoint.** A single-use page (`admin_bootstrap.php`)
  that accepts the token, creates the first admin from posted credentials,
  then disables itself. ~50 lines of PHP; testable.
- **B. Bootstrap via SQL on the production host.** Run a one-shot
  `INSERT INTO admins (username, password) VALUES ('admin', '<bcrypt-hash>')`
  via `make db-shell`, then immediately rotate the password through the
  application UI.

**Recommendation:** A. The token preflight already exists; finishing the
endpoint is the smallest possible follow-up commit.

**Decision (which path):** A / B: _______________

---

## 10. Ports summary, post-modernization

If all recommendations are accepted, the production stack exposes one port:

| Service | Host port | Internal port | Reachable in production? |
|---|---|---|---|
| `web` (Apache) | 8080 | 80 | Yes — behind HTTPS proxy |
| `db` (MySQL) | — | 3306 | Internal only |
| `phpmyadmin` | — | 80 | Dev override only |

---

## After IT answers

Return this document with decisions filled in. Each decision maps to a small,
focused commit:

- TLS choice → adjust reverse-proxy config / sidecar; flip `SESSION_SECURE_ONLY`
- Registry choice → README's Production Deployment section + CI hooks
- `.env` location → systemd unit / docker-compose path tweak
- Backup destination + schedule → cron line + populated `BACKUP_REMOTE`
- Log strategy → either logrotate config or compose `logging:` block
- Admin bootstrap path → either build `admin_bootstrap.php` (A) or document
  the SQL bootstrap procedure (B)
