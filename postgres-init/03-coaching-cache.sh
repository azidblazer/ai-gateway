#!/bin/bash
set -e

# Create the dashboard_coaching_cache table in the litellm database.
# This stores one cached AI coaching result per user per day.
#
# Created here (rather than only in dashboard's Python migration) because
# LiteLLM's schema migrations can drop tables it doesn't recognize, causing
# a race condition when the dashboard creates the table at startup.

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "litellm" <<-'EOSQL'
CREATE TABLE IF NOT EXISTS dashboard_coaching_cache (
    user_email   TEXT NOT NULL,
    cache_date   DATE NOT NULL,
    profile      JSON NOT NULL,
    coaching     JSON,
    generated_at TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (user_email, cache_date)
);
EOSQL

echo "Coaching cache table init script completed"
