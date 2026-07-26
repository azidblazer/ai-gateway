# AI Gateway

Production-ready deployment of [Open WebUI](https://github.com/open-webui/open-webui) + [LiteLLM](https://github.com/BerriAI/litellm) with multi-provider AI access, per-user budget tracking, and a custom usage dashboard.

Built for organizations (universities, enterprises) that need to provide employees with a shared AI chat interface while controlling costs.

## Features

- **Multi-provider AI access** — Anthropic, OpenAI, Google AI Studio (Gemini), Azure OpenAI (all configurable)
- **Per-user budget tracking** — $5/week per user (configurable), enforced at the database level
- **Usage dashboard** — Custom `/dashboard` page showing each user's spend, model usage breakdown, and weekly budget progress
- **AI coaching** — Optional personalized tips analyzing conversation patterns and spending to help users get more value from their budget
- **Feedback form** — Built-in user feedback form that sends email via SMTP
- **Microsoft Entra ID SSO** — OIDC authentication with role-based access control
- **Image generation** — Budget-tracked image generation via OpenAI and Gemini
- **Production-hardened** — Health checks, auto-restart, monitoring scripts, log rotation, connection pooling, Redis caching

## Architecture

```
                    ┌─────────────────────────────────────┐
                    │           NGINX (443/80)            │
                    │  SSL termination, domain routing    │
                    └──────────────┬──────────────────────┘
                                   │
              ┌────────────────────┴────────────────────┐
              │                                         │
              ▼                                         ▼
┌─────────────────────────┐               ┌─────────────────────────┐
│  chat.example.edu       │               │  litellm.example.edu    │
│  OpenWebUI (8080)       │               │  LiteLLM Admin (4000)   │
│  + /dashboard           │               │  Usage, keys, logs      │
└───────────┬─────────────┘               └─────────────────────────┘
            │                                         │
            │  Internal Docker network                │
            ▼                                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                      LiteLLM Proxy (4000)                       │
│              AI gateway, budget tracking, routing               │
└──────────────────────────────┬──────────────────────────────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
         Anthropic         OpenAI          Google / Azure
```

## Services

| Service | Image | Purpose |
|---------|-------|---------|
| postgres | postgres:16-alpine | Database for OpenWebUI + LiteLLM |
| redis | redis:7-alpine | WebSocket manager, LiteLLM cache, router state |
| litellm | ghcr.io/berriai/litellm:v1.90.0 | AI gateway, budget tracking, usage monitoring |
| openwebui | ghcr.io/open-webui/open-webui:v0.10.2 | Chat UI with SSO |
| dashboard | ./dashboard (custom build) | Usage dashboard with feedback form |
| nginx | nginx:1.28-alpine | SSL termination, domain routing |
| tika | apache/tika:3.3.1.0-full | Document parsing for file uploads (2G cap, 1G parser heap via `tika/tika-config.xml`) |
| autoheal | willfarrell/autoheal | Auto-restart unhealthy containers |
| watchtower | containrrr/watchtower | Auto-update container images (disabled by default) |

## Quick Start

### Prerequisites

- Docker and Docker Compose
- Two domains pointing to your server (e.g., `chat.example.edu` and `litellm.example.edu`)
- SSL certificate covering both domains (see `ssl/README.md`)
- API keys for at least one AI provider (Anthropic, OpenAI, or Google)
- Microsoft Entra ID app registration (or another OIDC provider — adjust `docker-compose.yml`)

### Setup

1. **Clone and configure:**
   ```bash
   git clone https://github.com/jonbarclay/ai-gateway.git ai-gateway
   cd ai-gateway
   cp .env.example .env
   ```

2. **Edit `.env`** — fill in all required values (API keys, OIDC credentials, domains, passwords)

3. **Place SSL certificates** in `ssl/chat.crt` and `ssl/chat.key` (see `ssl/README.md`)

4. **Update domains** in `nginx/nginx.conf` — replace `chat.example.edu` and `litellm.example.edu` with your actual domains

5. **Update CORS origin** in `dashboard/main.py` — replace `chat.example.edu` with your chat domain

6. **Start services:**
   ```bash
   docker compose up -d
   ```

7. **Verify:**
   - Chat UI: `https://chat.example.edu`
   - Admin UI: `https://litellm.example.edu`
   - Dashboard: `https://chat.example.edu/dashboard/`

### Post-Setup

- **Set admin emails** in `.env` (`ADMIN_EMAIL`) — these users get admin role in OpenWebUI
- **Set default model** via Admin Panel > Settings > Models > Default Models
- **Upload model icons** via Admin Panel > Workspace > Models > Edit > Avatar Photo
- **Add branding** — see `branding/README.md`

## Budget Tracking

Users are tracked by their email address (forwarded from OpenWebUI via `x-openwebui-user-email` header). Each user gets a weekly budget (default: $5).

### Budget Enforcement (Three Layers)

LiteLLM's `default_end_user_params` config is unreliable for users created via header-based identification. Three layers ensure budget assignment:

1. **Config** (`litellm/config.yaml`) — `default_end_user_params.budget_id` (first line of defense)
2. **PostgreSQL trigger** (`postgres-init/02-budget-trigger.sh`) — `BEFORE INSERT` trigger that sets `budget_id` when NULL
3. **Hourly cron** (`scripts/enforce-budgets.sh`) — Self-heals trigger after migrations, fixes any users with missing budget

### Changing the Budget

To change the default budget amount, update these three places:
1. `litellm/config.yaml` → `max_end_user_budget` and `budget_duration`
2. Create the budget in LiteLLM's `LiteLLM_BudgetTable` (via Admin UI or SQL)
3. Update `scripts/enforce-budgets.sh` → `DEFAULT_BUDGET_ID`

## Scheduled Jobs (Cron)

| Schedule | Script | Purpose |
|----------|--------|---------|
| `*/5 * * * *` | `scripts/monitor-containers.sh` | Container health checks + webhook alerts |
| `0 * * * *` | `scripts/enforce-budgets.sh` | Ensure budget trigger exists + fix unbudgeted users |
| `0 3 * * *` | `scripts/cleanup-spendlogs.sh` | Delete SpendLogs >90d, null messages >7d |

Update the `COMPOSE_DIR` variable in each script to match your installation path.
Script logs are written to `<COMPOSE_DIR>/logs/` (created automatically, gitignored).

## Configuration Files

| File | Purpose |
|------|---------|
| `docker-compose.yml` | Service definitions, environment variables |
| `litellm/config.yaml` | Model routing, budget limits, caching, router settings |
| `nginx/nginx.conf` | SSL termination, domain-based routing |
| `.env` | API keys, secrets, domain names |
| `postgres-init/` | Database initialization scripts |
| `scripts/` | Cron jobs for monitoring, budget enforcement, cleanup |

## Customization

### Adding Models

Add new model entries to `litellm/config.yaml`. See the [LiteLLM model docs](https://docs.litellm.ai/docs/providers) for provider-specific configuration.

### Authentication

This template uses Microsoft Entra ID OIDC. To use a different provider:
1. Update the OAuth environment variables in `docker-compose.yml` under the `openwebui` service
2. See the [OpenWebUI SSO docs](https://docs.openwebui.com/getting-started/advanced-topics/sso/) for supported providers

### Dashboard

The usage dashboard at `/dashboard` piggybacks on OpenWebUI's authentication — no separate login flow. Users must be logged into OpenWebUI first.

Key files:
- `dashboard/templates/index.html` — Frontend (Tailwind CSS)
- `dashboard/api/usage.py` — Usage data API
- `dashboard/api/feedback.py` — Feedback form endpoint
- `dashboard/services/coaching.py` — AI coaching pipeline

### Moving the Database to Its Own Server

The bundled PostgreSQL container is the right default, but if you outgrow it (managed
backups, HA, Kubernetes prep), see **[docs/external-database.md](docs/external-database.md)**
— a step-by-step migration guide including the hard-won lessons from our own production
move: why database latency matters more than anything else (our first attempt made the
app 10–25× slower and was rolled back in a day), the budget trigger you must recreate,
and the maintenance-script traps that fail silently.

### Performance Tuning

The default settings are optimized for ~1000 concurrent users on a 6-core / 16GB RAM VM. Key tuning parameters:

- **LiteLLM workers**: `--num_workers 6` in `docker-compose.yml` (match CPU cores)
- **OpenWebUI workers**: `UVICORN_WORKERS=4` (leave headroom for other services)
- **Thread pool**: `THREAD_POOL_SIZE=2000` (prevents blocking under load)
- **Batch writes**: `proxy_batch_write_at: 60` in `litellm/config.yaml` (biggest DB relief)
- **Redis caching**: `cache: true` with 1hr TTL (deduplicates similar queries)

See the comments in `docker-compose.yml` and `litellm/config.yaml` for detailed explanations.

## Monitoring

- **Container health**: `scripts/monitor-containers.sh` checks all containers + HTTP endpoints every 5 minutes
- **Auto-restart**: `autoheal` container watches Docker health checks and restarts unhealthy containers
- **Webhook alerts**: Set `MONITOR_WEBHOOK_URL` in `.env` to receive alerts via Slack, Teams, etc.
- **Prometheus metrics**: LiteLLM exposes `/metrics` (blocked externally, available on Docker network)
- **Performance diagnostics**: `scripts/diagnose-performance.sh` generates a detailed report

### Prometheus + Grafana Stack (optional)

The `monitoring/` directory contains a full Prometheus + Grafana + node_exporter
stack for capacity planning: pre-provisioned dashboards (host, containers,
LiteLLM application metrics, capacity planning), alert rules, and exporters for
container stats and DB user counts. It runs as a separate compose stack:

```bash
cd monitoring
docker compose -f docker-compose.monitoring.yml up -d
```

Grafana is served at `https://<your litellm domain>/grafana/` via the included
nginx location block. See [monitoring/README.md](monitoring/README.md) for
setup — including the required LiteLLM metrics credentials file.

## Lessons Learned / Gotchas

Hard-won operational lessons from running this stack in production:

- **PersistentConfig variables are DB-backed after first launch.** Open WebUI
  stores many env vars (`WEBUI_URL`, `DEFAULT_MODELS`, and others marked
  `PersistentConfig` in the docs) in its database on first startup. After that,
  changing the env var in `docker-compose.yml` is silently ignored — the
  DB-stored value wins. Change these via **Admin Panel > Settings** instead.

- **Frozen model cache.** After adding or renaming models in LiteLLM, Open
  WebUI's model picker may not update — it caches the model list in Redis with
  no TTL. Clear it with:

  ```bash
  docker exec chat-redis redis-cli -n 1 del open-webui:models
  ```

- **Domain migrations: never blanket-redirect.** If you ever rename your
  domain, 308-redirect **only** `/` and `/auth` from the old domain and keep
  proxying everything else. WebSocket upgrade requests cannot follow redirects
  — a blanket redirect produced 73,000+ broken `/ws/socket.io` redirect loops
  in 8 hours before we caught it.

## License

This project is licensed under the [MIT License](LICENSE).

It builds on these open-source components:
- [Open WebUI](https://github.com/open-webui/open-webui) — MIT License
- [LiteLLM](https://github.com/BerriAI/litellm) — MIT License
