#!/bin/bash
# Reset a single end user's accumulated spend to $0 so they can keep using the gateway.
#
# Usage:
#   ./scripts/reset-user-spend.sh <email>
#   ./scripts/reset-user-spend.sh <email> --flush-cache
#
# The DB update takes effect immediately, but LiteLLM caches end-user budget
# state in memory for ~60s. Pass --flush-cache to wipe LiteLLM's Redis cache
# (db 0) so the user can resume right away. That also clears the shared
# response cache for all users — usually fine, but worth knowing.

set -euo pipefail

DB_USER="openwebui"
DB_NAME="litellm"

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <email> [--flush-cache]" >&2
    exit 1
fi

EMAIL="$1"
FLUSH_CACHE="false"
if [[ "${2:-}" == "--flush-cache" ]]; then
    FLUSH_CACHE="true"
fi

# Pass the email as a psql variable so any literal value is safely quoted.
psql_cmd() {
    docker exec chat-postgres psql -U "$DB_USER" -d "$DB_NAME" -t -A -v email="$EMAIL" -c "$1"
}

row=$(psql_cmd "SELECT spend, budget_id, blocked FROM \"LiteLLM_EndUserTable\" WHERE user_id = :'email';")

if [[ -z "$row" ]]; then
    echo "No LiteLLM end user found for: $EMAIL" >&2
    exit 1
fi

IFS='|' read -r current_spend budget_id blocked <<< "$row"

echo "User:      $EMAIL"
echo "Spend:     \$${current_spend}"
echo "Budget:    ${budget_id:-<none>}"
echo "Blocked:   ${blocked}"
echo
read -r -p "Reset spend to \$0? [y/N] " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "Aborted."
    exit 0
fi

psql_cmd "UPDATE \"LiteLLM_EndUserTable\" SET spend = 0 WHERE user_id = :'email';" >/dev/null
echo "Spend reset to \$0 in database."

if [[ "$FLUSH_CACHE" == "true" ]]; then
    if docker ps --format '{{.Names}}' | grep -q '^chat-redis$'; then
        docker exec chat-redis redis-cli -n 0 FLUSHDB >/dev/null
        echo "Flushed LiteLLM Redis cache (db 0). User can resume immediately."
    else
        echo "WARN: chat-redis not running — cache not flushed." >&2
    fi
else
    echo "LiteLLM's in-memory cache may take up to 60s to pick up the change."
    echo "Pass --flush-cache to make it instant (also clears the shared response cache)."
fi
