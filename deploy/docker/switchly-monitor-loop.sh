#!/bin/sh
# Schedules one-shot PHP monitor runs inside the all-in-one container. The
# interval is reduced by execution time so long polls do not accumulate drift.
set -eu

interval="${SWITCHLY_MONITOR_INTERVAL:-60}"
case "$interval" in *[!0-9]*|'') interval=60 ;; esac
[ "$interval" -ge 15 ] || interval=15

echo "Switchly background worker started (interval: ${interval}s)."
while :; do
    started="$(date +%s)"
    if ! /usr/local/bin/php /var/www/html/bin/monitor.php; then
        echo "Switchly background worker: poll failed; retrying on next interval." >&2
    fi
    finished="$(date +%s)"
    elapsed=$((finished - started))
    delay=$((interval - elapsed))
    [ "$delay" -ge 1 ] || delay=1
    sleep "$delay"
done
