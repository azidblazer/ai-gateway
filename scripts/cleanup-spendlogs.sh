#!/bin/bash
# Clean up LiteLLM SpendLogs to prevent unbounded DB growth
# Runs via cron daily at 3 AM (0 3 * * *)
#
# Two responsibilities:
# 1. Delete SpendLogs rows older than 90 days
# 2. NULL out messages/response on rows older than 7 days
#    (catches pre-turn_off_message_logging data)

set -euo pipefail

COMPOSE_DIR="/opt/ai-gateway"
LOG_FILE="${COMPOSE_DIR}/logs/spendlogs-cleanup.log"
mkdir -p "${COMPOSE_DIR}/logs"
ENV_FILE="${COMPOSE_DIR}/.env"
DB_NAME="litellm"
DB_USER="openwebui"

# Load webhook URL from .env
WEBHOOK_URL=""
if [[ -f "$ENV_FILE" ]]; then
    WEBHOOK_URL=$(grep -E '^MONITOR_WEBHOOK_URL=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
fi

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

send_webhook() {
    local status="$1"
    local message="$2"

    log "ALERT [${status}] ${message}"

    if [[ -z "$WEBHOOK_URL" ]]; then
        return 0
    fi

    local emoji
    case "$status" in
        fixed)  emoji=":broom:" ;;
        error)  emoji=":red_circle:" ;;
        *)      emoji=":information_source:" ;;
    esac

    local timestamp hostname payload
    timestamp=$(date -Iseconds)
    hostname=$(hostname)

    payload=$(python3 -c "
import json
print(json.dumps({
    'text': '${emoji} *[SPENDLOGS ${status^^}]* on \`${hostname}\`\n${message}\n_${timestamp}_'
}))
")

    curl -s -X POST "$WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "$payload" \
        --max-time 10 \
        >> "$LOG_FILE" 2>&1 || log "WEBHOOK: Failed to send notification"
}

# Check if postgres container is running
if ! docker ps --format '{{.Names}}' | grep -q '^chat-postgres$'; then
    log "SKIP - chat-postgres is not running"
    exit 0
fi

psql_cmd() {
    docker exec chat-postgres psql -U "$DB_USER" -d "$DB_NAME" -t -A -c "$1" 2>&1
}

# --- 1. Delete rows older than 90 days ---

deleted_count=$(psql_cmd "
    WITH deleted AS (
        DELETE FROM \"LiteLLM_SpendLogs\"
        WHERE \"startTime\" < NOW() - INTERVAL '90 days'
        RETURNING 1
    )
    SELECT COUNT(*) FROM deleted;
") || {
    send_webhook "error" "Failed to delete old SpendLogs: ${deleted_count}"
    exit 1
}
deleted_count=$(echo "$deleted_count" | tr -d '[:space:]')

# --- 2. NULL out messages/response on rows older than 7 days ---

nulled_count=$(psql_cmd "
    WITH updated AS (
        UPDATE \"LiteLLM_SpendLogs\"
        SET messages = NULL, response = NULL
        WHERE \"startTime\" < NOW() - INTERVAL '7 days'
          AND (messages IS NOT NULL OR response IS NOT NULL)
        RETURNING 1
    )
    SELECT COUNT(*) FROM updated;
") || {
    send_webhook "error" "Failed to null out message content: ${nulled_count}"
    exit 1
}
nulled_count=$(echo "$nulled_count" | tr -d '[:space:]')

# --- Report ---

if [[ "$deleted_count" -gt 0 || "$nulled_count" -gt 0 ]]; then
    msg="Deleted ${deleted_count} rows >90d, nulled messages on ${nulled_count} rows >7d"
    log "CLEANUP: ${msg}"
    send_webhook "fixed" "${msg}"
else
    log "OK - No SpendLogs cleanup needed"
fi
