# Moving PostgreSQL to Its Own Server

The default deployment runs PostgreSQL as a local container (`chat-postgres`) on the same
host as everything else. That is the right starting point: zero network latency, one
`docker compose up`, nothing else to manage.

At some point you may want the database on its own server — for managed backups, HA,
more headroom, or because you're preparing a Kubernetes migration and want state out of
the compose host. We (Utah Valley University) have done this move twice in production —
once badly, once well. This guide is the distilled version of what we learned, including
the mistakes, so you can skip them.

## Lesson 0: Latency is the whole ballgame

**Our failed migration:** we moved to a cloud-managed PostgreSQL (Azure Database for
PostgreSQL) and rolled back within 24 hours. The database itself was fine — the problem
was the ~25 ms network round trip per query, versus ~0.2 ms to a local container.
Both OpenWebUI and LiteLLM issue *many sequential queries per request* (auth checks,
config reads, chat history, spend writes), so per-query RTT multiplies: our DB-bound
endpoints got **10–25× slower** and users noticed the same day.

Connection pooling does not help — pooling saves connection *setup*, not per-query
round trips. The only fixes are: co-locate compute with the database, or don't move.

**Our successful migration:** a PostgreSQL server in the same datacenter/campus network
(sub-millisecond RTT). No user-visible slowdown.

**Rule of thumb:** before migrating, measure RTT from the compose host to the DB server:

```bash
# Quick network check
ping -c 5 your-db-server.example.edu

# Real query round trip (after creating the DB, before cutover)
psql "postgresql://user:pass@your-db-server:5432/litellm" -c '\timing' -c 'SELECT 1;'
```

If a `SELECT 1` takes more than ~2 ms, expect user-visible slowdowns. 10+ ms will feel
broken. Same-rack or same-campus is fine; cross-region or consumer-cloud is not.

## Pre-migration checklist

- [ ] RTT from compose host to DB server measured and ≈1 ms or less
- [ ] Same or newer PostgreSQL major version on the target (we use 16)
- [ ] `max_connections` on the target sized for the stack: LiteLLM (workers × pool limit)
      + OpenWebUI (workers × (pool + overflow)) + dashboard ≈ 165 with the default tuning
      in this repo — plus anything *else* using that server
- [ ] An app user/role created (avoid superuser); password URL-encoded if it contains
      special characters (it goes inside `DATABASE_URL`)
- [ ] `postgresql-client` installed **on the compose host** — the cron scripts will need
      `psql` once the local container (their current client) is gone
- [ ] A maintenance window: the cutover needs a brief full-stack stop for a consistent copy

## Migration steps

### 1. Create databases and user on the target server

```sql
CREATE ROLE ai_gateway_app LOGIN PASSWORD '...';
CREATE DATABASE litellm OWNER ai_gateway_app;
CREATE DATABASE openwebui OWNER ai_gateway_app;
```

### 2. Copy the data

```bash
docker compose stop litellm openwebui dashboard   # stop writers, keep postgres up

docker exec chat-postgres pg_dump -U $POSTGRES_USER -Fc litellm   > litellm.dump
docker exec chat-postgres pg_dump -U $POSTGRES_USER -Fc openwebui > openwebui.dump

pg_restore -d "postgresql://ai_gateway_app:PASS@db-server:5432/litellm"   --no-owner litellm.dump
pg_restore -d "postgresql://ai_gateway_app:PASS@db-server:5432/openwebui" --no-owner openwebui.dump
```

> **Dumps contain full user chat history.** Treat them like credentials: restore, verify,
> then delete them. This repo's `.gitignore` blocks `*.dump` / `*.sql`, but don't rely on
> that — don't leave them on disk at all.

### 3. Recreate the budget trigger on the new database

This is easy to miss and it matters. The `BEFORE INSERT` budget trigger (layer 2 of the
budget enforcement) is created by `postgres-init/02-budget-trigger.sh`, which only runs
when a **fresh local container** initializes. `pg_restore` carries it over if you restore
the full schema, but if you let LiteLLM's migrations create the schema on the new server
instead, the trigger will not exist. Verify it:

```bash
psql "$DB_URL" -c "SELECT tgname FROM pg_trigger WHERE tgrelid = '\"LiteLLM_EndUserTable\"'::regclass AND NOT tgisinternal;"
```

If missing, run the SQL from `postgres-init/02-budget-trigger.sh` against the new DB.
(The hourly `enforce-budgets.sh` cron self-heals the trigger too — once you've completed
step 5 so it points at the right database.)

### 4. Point the stack at the new server

In `docker-compose.yml`:

- Update every `DATABASE_URL` / `OPENWEBUI_DATABASE_URL` from `@postgres:5432/...` to
  `@your-db-server:5432/...` (add `POSTGRES_HOST` to `.env` and use `@${POSTGRES_HOST}:5432`
  so it lives in one place)
- Remove the `postgres:` service definition
- Remove the `postgres: condition: service_healthy` entry from **every** `depends_on`
  (litellm, openwebui, dashboard) — compose refuses to start if a `depends_on` references
  a service that no longer exists
- Consider requiring TLS: append `?sslmode=require` to the URLs if the server supports it

Then:

```bash
docker compose up -d
docker compose ps        # everything healthy?
```

Keep the old `./data/postgres` directory untouched until you're confident — it's your
rollback path.

### 5. Update the maintenance scripts — do not skip this

**Our embarrassing lesson:** after our move, the cron scripts kept running
`docker exec chat-postgres psql ...` against the old local container. It still had a
month-old copy of the data, so `enforce-budgets.sh` cheerfully logged
"OK — all users have budget_id" every hour **against a frozen database**, while the real
production database went unmonitored. Nothing failed loudly; everything was simply
pointed at the wrong place. We only caught it in a full review a month later.

Every script that does `docker exec chat-postgres psql` must switch to a direct
connection string. The pattern we use:

```bash
ENV_FILE="${COMPOSE_DIR}/.env"

env_var() {
    grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'"
}

DB_URL="postgresql://$(env_var POSTGRES_USER):$(env_var POSTGRES_PASSWORD)@$(env_var POSTGRES_HOST):5432/litellm"

psql_cmd() {
    psql "$DB_URL" -t -A -c "$1" 2>&1
}
```

Apply this to `scripts/enforce-budgets.sh`, `scripts/cleanup-spendlogs.sh`, and
`scripts/diagnose-performance.sh`, and delete their "is chat-postgres running" guard
(replace it with a `SELECT 1` reachability check that fires the webhook on failure, so
a down database alerts instead of silently skipping).

### 6. Replace the container health check with an external one

The local container had a Docker health check, autoheal coverage, and a slot in
`monitor-containers.sh`. All three disappear with the container. Add an external
reachability check to `monitor-containers.sh` (runs every 5 minutes):

```bash
POSTGRES_HOST=$(env_var POSTGRES_HOST)
if ! pg_isready -h "$POSTGRES_HOST" -p 5432 -t 5 >/dev/null 2>&1; then
    send_webhook "postgres (${POSTGRES_HOST})" "down" "investigate DB server" \
        "External PostgreSQL at ${POSTGRES_HOST}:5432 is not accepting connections"
fi
```

(Remove `chat-postgres` from the `CONTAINERS` array at the same time.)

## Post-migration gotchas

**One database, one live stack.** Never point two running deployments (e.g. the compose
stack and a Kubernetes staging environment) at the same OpenWebUI database. OpenWebUI
persists most of its settings in the DB (`PersistentConfig`), so the two stacks overwrite
each other's config — we had a staging cluster silently rewrite the production
`openai.api_base_urls` to a hostname that only resolved inside Kubernetes. Production
kept working *only because the running container held the old value in memory*; a restart
would have taken every model offline. Give each environment its own database until you
actually cut over.

**PostgreSQL slow-query logging moves.** With the local container you could see >250 ms
queries via `docker logs chat-postgres`. That signal now lives on the DB server — set
`log_min_duration_statement = 250` there and know where its logs go, or you lose a layer
of your performance observability.

**Connection budget is now shared.** Anything else using that server draws from the same
`max_connections`. Recount the math when you add consumers.

**The old data directory.** `./data/postgres` keeps the pre-migration data. Keep it for
a couple of weeks as a rollback path, then delete it — a stale copy of user chat history
is a liability, and worse, a plausible-looking wrong target if anything ever gets pointed
at a local database again.

## Rollback

If the move goes badly (latency, instability), rollback is the reverse and it's fast:
restore the `postgres:` service and `depends_on` entries in `docker-compose.yml`
(git history has them), point the `DATABASE_URL`s back at `@postgres:5432`, and
`docker compose up -d`. If writes happened on the external server after cutover, dump
and restore those databases back into the local container first — don't just restart on
the stale `./data/postgres` copy.
