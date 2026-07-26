#!/bin/bash
# Exports Docker container stats as Prometheus metrics via node_exporter textfile collector.
# Runs every 15s via cron or a loop, writes to a .prom file that node_exporter scrapes.

TEXTFILE_DIR="/opt/ai-gateway/monitoring/data/textfile"
PROM_FILE="${TEXTFILE_DIR}/docker_stats.prom"
TEMP_FILE="${PROM_FILE}.tmp"

mkdir -p "$TEXTFILE_DIR"

# Get docker stats in a parseable format
# Fields: Name, CPUPerc, MemUsage (bytes used), MemLimit (bytes), MemPerc, NetIO_in, NetIO_out
docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}' 2>/dev/null | while IFS=$'\t' read -r name cpu_pct mem_usage mem_pct; do
    # Skip empty lines
    [ -z "$name" ] && continue

    # Clean CPU percentage (remove %)
    cpu=$(echo "$cpu_pct" | tr -d '%')

    # Parse memory usage: "3.961GiB / 5GiB" -> extract used and limit
    mem_used_raw=$(echo "$mem_usage" | awk -F' / ' '{print $1}' | xargs)
    mem_limit_raw=$(echo "$mem_usage" | awk -F' / ' '{print $2}' | xargs)

    # Convert memory to bytes
    to_bytes() {
        local val=$(echo "$1" | sed 's/[A-Za-z]*$//')
        local unit=$(echo "$1" | grep -oE '[A-Za-z]+$')
        case "$unit" in
            B)    echo "$val" ;;
            KiB)  echo "$val * 1024" | bc ;;
            MiB)  echo "$val * 1048576" | bc ;;
            GiB)  echo "$val * 1073741824" | bc ;;
            kB)   echo "$val * 1000" | bc ;;
            MB)   echo "$val * 1000000" | bc ;;
            GB)   echo "$val * 1000000000" | bc ;;
            *)    echo "0" ;;
        esac
    }

    mem_used=$(to_bytes "$mem_used_raw")
    mem_limit=$(to_bytes "$mem_limit_raw")
    mem_percent=$(echo "$mem_pct" | tr -d '%')

    # Write metrics
    echo "container_cpu_usage_percent{name=\"$name\"} $cpu"
    echo "container_memory_usage_bytes{name=\"$name\"} $mem_used"
    echo "container_memory_limit_bytes{name=\"$name\"} $mem_limit"
    echo "container_memory_usage_percent{name=\"$name\"} $mem_percent"
done > "$TEMP_FILE"

# Add help/type headers
{
    echo "# HELP container_cpu_usage_percent Container CPU usage percentage"
    echo "# TYPE container_cpu_usage_percent gauge"
    echo "# HELP container_memory_usage_bytes Container memory usage in bytes"
    echo "# TYPE container_memory_usage_bytes gauge"
    echo "# HELP container_memory_limit_bytes Container memory limit in bytes"
    echo "# TYPE container_memory_limit_bytes gauge"
    echo "# HELP container_memory_usage_percent Container memory usage as percentage of limit"
    echo "# TYPE container_memory_usage_percent gauge"
    cat "$TEMP_FILE"
} > "${TEMP_FILE}.final"

# Atomic move to prevent partial reads
mv "${TEMP_FILE}.final" "$PROM_FILE"
rm -f "$TEMP_FILE"
