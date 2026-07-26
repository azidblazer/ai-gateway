#!/bin/bash
# Exports LiteLLM database metrics as Prometheus metrics via node_exporter textfile collector.
# Queries PostgreSQL directly since LiteLLM's built-in litellm_total_users metric
# doesn't count end users created via header-based identification.
#
# Runs every minute via docker-stats-loop.sh alongside container metrics.

TEXTFILE_DIR="/opt/ai-gateway/monitoring/data/textfile"
PROM_FILE="${TEXTFILE_DIR}/db_metrics.prom"
TEMP_FILE="${PROM_FILE}.tmp"

DB_USER="openwebui"
DB_NAME="litellm"

mkdir -p "$TEXTFILE_DIR"

psql_cmd() {
    docker exec chat-postgres psql -U "$DB_USER" -d "$DB_NAME" -t -c "$1"
}

# Query registered end user count
user_count=$(psql_cmd "SELECT COUNT(*) FROM \"LiteLLM_EndUserTable\";" 2>/dev/null | xargs)

# Query daily active users (distinct users with requests in last 24h)
dau=$(psql_cmd "SELECT COUNT(DISTINCT end_user) FROM \"LiteLLM_SpendLogs\" WHERE \"startTime\" >= NOW() - INTERVAL '24 hours' AND end_user IS NOT NULL AND end_user != '';" 2>/dev/null | xargs)

# Only write if we got valid numbers
if [[ "$user_count" =~ ^[0-9]+$ ]]; then
    {
        echo "# HELP litellm_registered_users Total end users in LiteLLM_EndUserTable"
        echo "# TYPE litellm_registered_users gauge"
        echo "litellm_registered_users $user_count"

        if [[ "$dau" =~ ^[0-9]+$ ]]; then
            echo "# HELP litellm_daily_active_users Distinct users with requests in last 24 hours"
            echo "# TYPE litellm_daily_active_users gauge"
            echo "litellm_daily_active_users $dau"
        fi
    } > "$TEMP_FILE"

    # Atomic move to prevent partial reads
    mv "$TEMP_FILE" "$PROM_FILE"
fi
