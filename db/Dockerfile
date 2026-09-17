# ─────────────────────────────────────────────────────────────────────────────
# Impact Score Dashboard — Database image
#
# Self-contained: init.sql, all migration files, and the migration runner are
# baked in. A consumer of the released image needs no local db/ directory.
# Migrations run automatically on every container start (see entrypoint.sh).
# ─────────────────────────────────────────────────────────────────────────────

FROM mysql:8.0

COPY init.sql /docker-entrypoint-initdb.d/init.sql
COPY migrations/ /migrations/
COPY run-migrations.sh /usr/local/bin/run-migrations.sh
COPY entrypoint.sh /usr/local/bin/impact-score-entrypoint.sh

RUN chmod +x /usr/local/bin/run-migrations.sh \
    && chmod +x /usr/local/bin/impact-score-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/impact-score-entrypoint.sh"]
CMD ["mysqld"]
