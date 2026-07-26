#!/bin/bash
# Runs the docker stats exporter 4 times per minute (every 15 seconds).
# DB metrics exporter runs once per invocation (user count doesn't need 15s resolution).
# Called once per minute by cron.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

"$SCRIPT_DIR/db-metrics-exporter.sh"
"$SCRIPT_DIR/docker-stats-exporter.sh"
sleep 15
"$SCRIPT_DIR/docker-stats-exporter.sh"
sleep 15
"$SCRIPT_DIR/docker-stats-exporter.sh"
sleep 15
"$SCRIPT_DIR/docker-stats-exporter.sh"
