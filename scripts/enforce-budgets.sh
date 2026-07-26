#!/bin/bash
# Enforce budget_id assignment for all LiteLLM end users
# Runs via cron every hour (0 * * * *)
#
# Two responsibilities:
# 1. Ensure the BEFORE INSERT trigger exists on LiteLLM_EndUserTable
# 2. Fix any existing users with NULL/empty budget_id
#
# This is the "suspenders" layer — the trigger is the "belt".
# Together they guarantee every end user has a budget assigned,
# even if LiteLLM's default_end_user_params config is unreliable.

set -euo pipefail

COMPOSE_DIR="/opt/ai-gateway"
LOG_FILE="${COMPOSE_DIR}/logs/budget-enforce.log"
mkdir -p "${COMPOSE_DIR}/logs"
ENV_FILE="${COMPOSE_DIR}/.env"
DB_NAME="litellm"
DB_USER="openwebui"
DEFAULT_BUDGET_ID="weekly-5-usd"

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
        fixed)  emoji=":wrench:" ;;
        error)  emoji=":red_circle:" ;;
        *)      emoji=":information_source:" ;;
    esac

    local timestamp hostname payload
    timestamp=$(date -Iseconds)
    hostname=$(hostname)

    payload=$(python3 -c "
import json
print(json.dumps({
    'text': '${emoji} *[BUDGET ${status^^}]* on \`${hostname}\`\n${message}\n_${timestamp}_'
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

# --- 1. Ensure trigger exists ---

trigger_exists=$(psql_cmd "
    SELECT COUNT(*) FROM pg_trigger t
    JOIN pg_class c ON t.tgrelid = c.oid
    WHERE t.tgname = 'trg_default_budget_id'
      AND c.relname = 'LiteLLM_EndUserTable';
") || {
    send_webhook "error" "Failed to query pg_trigger: ${trigger_exists}"
    exit 1
}

# Trim whitespace
trigger_exists=$(echo "$trigger_exists" | tr -d '[:space:]')

if [[ "$trigger_exists" != "1" ]]; then
    log "Trigger missing — recreating"
    result=$(psql_cmd "
        CREATE OR REPLACE FUNCTION set_default_budget_id()
        RETURNS TRIGGER AS \$\$
        BEGIN
            IF NEW.budget_id IS NULL OR NEW.budget_id = '' THEN
                NEW.budget_id := '${DEFAULT_BUDGET_ID}';
            END IF;
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql;

        DROP TRIGGER IF EXISTS trg_default_budget_id ON \"LiteLLM_EndUserTable\";
        CREATE TRIGGER trg_default_budget_id
            BEFORE INSERT ON \"LiteLLM_EndUserTable\"
            FOR EACH ROW
            EXECUTE FUNCTION set_default_budget_id();
    ") || {
        send_webhook "error" "Failed to recreate trigger: ${result}"
        exit 1
    }
    send_webhook "fixed" "Recreated BEFORE INSERT budget trigger on LiteLLM_EndUserTable"
fi

# --- 2. Fix unbudgeted users ---

unbudgeted_users=$(psql_cmd "
    SELECT user_id FROM \"LiteLLM_EndUserTable\"
    WHERE budget_id IS NULL OR budget_id = '';
") || {
    send_webhook "error" "Failed to query unbudgeted users: ${unbudgeted_users}"
    exit 1
}

if [[ -n "$unbudgeted_users" ]]; then
    count=$(echo "$unbudgeted_users" | wc -l | tr -d '[:space:]')
    user_list=$(echo "$unbudgeted_users" | head -10 | tr '\n' ', ' | sed 's/,$//')

    fix_result=$(psql_cmd "
        UPDATE \"LiteLLM_EndUserTable\"
        SET budget_id = '${DEFAULT_BUDGET_ID}'
        WHERE budget_id IS NULL OR budget_id = '';
    ") || {
        send_webhook "error" "Failed to fix unbudgeted users: ${fix_result}"
        exit 1
    }

    log "Fixed ${count} users: ${user_list}"
    send_webhook "fixed" "Assigned budget_id='${DEFAULT_BUDGET_ID}' to ${count} user(s): ${user_list}"
else
    log "OK - All users have budget_id assigned"
fi
