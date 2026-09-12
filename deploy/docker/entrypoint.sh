#!/bin/sh
# Initializes writable runtime storage as the unprivileged application user,
# bootstraps the database and starts the optional monitor loop before Apache.
set -eu

DB_PATH="${SWITCHLY_DB_PATH:-/var/lib/switchly/database.sqlite}"
DB_DIR="$(dirname "$DB_PATH")"

mkdir -p "$DB_DIR" "${APACHE_RUN_DIR:-/tmp/apache2/run}" "${APACHE_LOCK_DIR:-/tmp/apache2/lock}" "${APACHE_LOG_DIR:-/tmp/apache2/log}"

# Trigger schema migrations and one-time administrator creation before Apache
# starts. A generated initial password is therefore visible in container logs.
/usr/local/bin/php -r "require '/var/www/html/src/Database.php'; Database::getConnection();"

MONITOR_ENABLED="${SWITCHLY_MONITOR_ENABLED:-1}"
if [ "$MONITOR_ENABLED" = "1" ]; then
    /usr/local/bin/switchly-monitor-loop &
fi

exec "$@"
