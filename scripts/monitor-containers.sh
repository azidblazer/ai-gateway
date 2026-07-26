#!/bin/bash
# Monitor Docker container health and send webhook alerts
# Runs via cron every 5 minutes
#
# - Checks container running state AND health status
# - Sends webhook notifications on issues and recoveries
# - Tracks state to avoid duplicate alerts
# - Autoheal container handles restarts; this script handles notifications

set -euo pipefail

COMPOSE_DIR="/opt/ai-gateway"
LOG_FILE="${COMPOSE_DIR}/logs/monitor.log"
mkdir -p "${COMPOSE_DIR}/logs"
STATE_FILE="/tmp/monitor-state.json"
ENV_FILE="${COMPOSE_DIR}/.env"

# Containers to monitor (name:has_healthcheck)
# Note: chat-watchtower is intentionally excluded — it runs with replicas=0 (disabled)
# and would trigger docker compose up -d every 5 minutes if included here.
CONTAINERS=(
    "chat-postgres:true"
    "chat-redis:true"
    "chat-litellm:true"
    "chat-openwebui:true"
    "chat-dashboard:true"
    "chat-nginx:false"
    "chat-autoheal:false"
)

# HTTP endpoints to monitor (state_key|url|description)
# Checked after container loop to detect 502s and routing failures
HTTP_ENDPOINTS=(
    "http-openwebui|https://chat.example.edu/|OpenWebUI chat interface"
    "http-litellm|https://litellm.example.edu/health/liveliness|LiteLLM health endpoint"
    "http-dashboard|https://chat.example.edu/dashboard/health|Dashboard health endpoint"
)

# Load webhook URL from .env
WEBHOOK_URL=""
if [[ -f "$ENV_FILE" ]]; then
    WEBHOOK_URL=$(grep -E '^MONITOR_WEBHOOK_URL=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
fi

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Initialize state file if missing
if [[ ! -f "$STATE_FILE" ]]; then
    echo '{}' > "$STATE_FILE"
fi

# Read previous alert state for a container
get_state() {
    local container="$1"
    python3 -c "
import json, sys
with open('${STATE_FILE}') as f:
    state = json.load(f)
print(state.get('${container}', ''))
" 2>/dev/null || echo ""
}

# Write alert state for a container
set_state() {
    local container="$1"
    local status="$2"
    python3 -c "
import json
with open('${STATE_FILE}', 'r') as f:
    state = json.load(f)
if '${status}' == '':
    state.pop('${container}', None)
else:
    state['${container}'] = '${status}'
with open('${STATE_FILE}', 'w') as f:
    json.dump(state, f)
" 2>/dev/null
}

# Send webhook notification
send_webhook() {
    local service="$1"
    local status="$2"
    local action="$3"
    local message="$4"

    log "ALERT [${status}] ${service}: ${message} (action: ${action})"

    if [[ -z "$WEBHOOK_URL" ]]; then
        log "WEBHOOK: No MONITOR_WEBHOOK_URL configured, skipping notification"
        return 0
    fi

    local timestamp
    timestamp=$(date -Iseconds)
    local hostname
    hostname=$(hostname)

    local emoji
    case "$status" in
        down)       emoji=":red_circle:" ;;
        unhealthy)  emoji=":warning:" ;;
        http_error) emoji=":rotating_light:" ;;
        recovered)  emoji=":white_check_mark:" ;;
        *)          emoji=":information_source:" ;;
    esac

    local payload
    payload=$(python3 -c "
import json
print(json.dumps({
    'text': '${emoji} *[${status^^}]* \`${service}\` on \`${hostname}\`\n${message}\n_Action: ${action} | ${timestamp}_'
}))
")

    curl -s -X POST "$WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "$payload" \
        --max-time 10 \
        >> "$LOG_FILE" 2>&1 || log "WEBHOOK: Failed to send notification for ${service}"
}

# Main monitoring loop
any_issues=false

for entry in "${CONTAINERS[@]}"; do
    container="${entry%%:*}"
    has_healthcheck="${entry##*:}"

    # Check if container is running
    if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        any_issues=true
        prev_state=$(get_state "$container")

        if [[ "$prev_state" != "down" ]]; then
            send_webhook "$container" "down" "docker compose up -d" \
                "Container ${container} is not running"
            set_state "$container" "down"
        fi

        # Try to start it
        log "STARTING: ${container} is not running, running docker compose up -d"
        cd "$COMPOSE_DIR" && docker compose up -d >> "$LOG_FILE" 2>&1 || true
        continue
    fi

    # Check health status (only for containers with health checks)
    if [[ "$has_healthcheck" == "true" ]]; then
        health=$(docker inspect --format='{{.State.Health.Status}}' "$container" 2>/dev/null || echo "unknown")

        if [[ "$health" == "unhealthy" ]]; then
            any_issues=true
            prev_state=$(get_state "$container")

            if [[ "$prev_state" != "unhealthy" ]]; then
                send_webhook "$container" "unhealthy" "autoheal will restart" \
                    "Container ${container} is unhealthy (health check failing)"
                set_state "$container" "unhealthy"
            fi
            continue
        fi
    fi

    # Container is running (and healthy if it has a health check)
    prev_state=$(get_state "$container")
    if [[ -n "$prev_state" ]]; then
        send_webhook "$container" "recovered" "none" \
            "Container ${container} has recovered (was: ${prev_state})"
        set_state "$container" ""
    fi
done

# HTTP endpoint checks - detect 502s and routing failures even when containers report healthy
# Skip if nginx isn't running (endpoints would all fail)
if docker ps --format '{{.Names}}' | grep -q '^chat-nginx$'; then
    for entry in "${HTTP_ENDPOINTS[@]}"; do
        state_key="${entry%%|*}"
        remainder="${entry#*|}"
        url="${remainder%%|*}"
        description="${remainder#*|}"

        http_code=$(curl -sk --resolve "$(echo "$url" | sed 's|https://\([^/]*\).*|\1|'):443:127.0.0.1" \
            --max-time 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo "000")

        if [[ "$http_code" -ge 200 && "$http_code" -lt 400 ]]; then
            # Endpoint is responding OK
            prev_state=$(get_state "$state_key")
            if [[ -n "$prev_state" ]]; then
                send_webhook "$description" "recovered" "none" \
                    "${description} is responding again (HTTP ${http_code}, was: ${prev_state})"
                set_state "$state_key" ""
            fi
        else
            any_issues=true
            prev_state=$(get_state "$state_key")

            if [[ "$prev_state" != "http_error" ]]; then
                send_webhook "$description" "http_error" "investigate nginx/upstream" \
                    "${description} returned HTTP ${http_code} (URL: ${url})"
                set_state "$state_key" "http_error"
            fi
        fi
    done
else
    log "SKIP HTTP checks - chat-nginx is not running"
fi

if [[ "$any_issues" == "false" ]]; then
    log "OK - All containers running and healthy"
fi
