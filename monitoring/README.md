# Monitoring Stack (optional)

Prometheus + Grafana + node_exporter for capacity planning and performance monitoring.
Runs as a separate compose stack alongside the main AI Gateway deployment.

## What you get

- **Prometheus** (127.0.0.1:9090) — scrapes LiteLLM `/metrics`, node_exporter, and itself; 15-day retention; alert rules in `prometheus/alerts.yml` (CPU, memory, disk, container limits, LiteLLM error rate / latency / TTFT / queue time)
- **Grafana** (127.0.0.1:3001, also served at `https://<litellm domain>/grafana` via nginx) — pre-provisioned dashboards: host overview, container overview, LiteLLM application, capacity planning
- **node_exporter** — host metrics plus container stats and DB user counts via the textfile collector (`scripts/`)

## Setup

1. **Create the LiteLLM metrics credentials file** (required, gitignored):

   LiteLLM v1.87+ requires admin authentication on `/metrics` — virtual or
   read-only keys get a 401, so this file must hold the LiteLLM master key
   (the `LITELLM_MASTER_KEY` from your main `.env`):

   ```bash
   echo "sk-your-litellm-master-key" > monitoring/prometheus/litellm_metrics_key
   chmod 600 monitoring/prometheus/litellm_metrics_key
   ```

   Prometheus reads it via `credentials_file` in `prometheus/prometheus.yml`
   and sends it as a Bearer token when scraping `litellm:4000/metrics`.

2. **Check the external network name** in `docker-compose.monitoring.yml` —
   it must match your main stack's network (default assumes the repo is at
   `/opt/ai-gateway`, giving `ai-gateway_chat-network`). Verify with
   `docker network ls`.

3. **Set environment variables** (in your shell or a local `.env`):
   - `GRAFANA_ADMIN_PASSWORD` — Grafana admin password (default `changeme`)
   - `LITELLM_DOMAIN` — your LiteLLM admin domain, used for Grafana's root URL

4. **Start the stack**:

   ```bash
   cd monitoring
   docker compose -f docker-compose.monitoring.yml up -d
   ```

5. **(Optional) container + DB metrics via textfile collector** — add a cron
   entry to run the exporter loop once per minute:

   ```
   * * * * * /opt/ai-gateway/monitoring/scripts/docker-stats-loop.sh
   ```

## Accessing Grafana

The main `nginx/nginx.conf` proxies `/grafana/` on the LiteLLM domain to the
Grafana container, so browse to `https://<your litellm domain>/grafana/`.
Locally, it's also on `http://127.0.0.1:3001`.

## Data

Prometheus TSDB and Grafana state live under `monitoring/data/` (gitignored).
