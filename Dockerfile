FROM php:8.5-apache-bookworm

ARG DEBIAN_FRONTEND=noninteractive

LABEL org.opencontainers.image.title="Switchly" \
      org.opencontainers.image.description="Self-hosted Mikrotik SwitchOS Control Panel" \
      org.opencontainers.image.vendor="Switchly" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.version="1.4.7-beta"

# The official PHP 8.5 image already provides the required PHP modules.
# Verify them instead of rebuilding them; rebuilding built-in modules can fail
# with "cp: cannot stat 'modules/*'" and unnecessarily increases build time.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libcurl4 \
        libsqlite3-0 \
    && php -r 'foreach (["pdo_sqlite", "curl", "openssl", "Zend OPcache"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "$extension extension missing\n"); exit(1); } }' \
    && a2enmod headers expires \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY deploy/docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/switchly.ini
COPY deploy/docker/entrypoint.sh /usr/local/bin/switchly-entrypoint
COPY deploy/docker/switchly-monitor-loop.sh /usr/local/bin/switchly-monitor-loop
COPY --chown=root:www-data . /var/www/html/

RUN chmod 755 /usr/local/bin/switchly-entrypoint /usr/local/bin/switchly-monitor-loop \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && find /var/www/html -type d -exec chmod 750 {} + \
    && find /var/www/html -type f -exec chmod 640 {} + \
    && mkdir -p /var/lib/switchly \
    && chown -R www-data:www-data /var/lib/switchly \
    && chmod 770 /var/lib/switchly

ENV SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite \
    APACHE_RUN_DIR=/tmp/apache2/run \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_PID_FILE=/tmp/apache2/run/apache2.pid \
    APACHE_LOG_DIR=/tmp/apache2/log

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8080/api/health.php >/dev/null || exit 1

USER www-data:www-data
ENTRYPOINT ["/usr/local/bin/switchly-entrypoint"]
CMD ["apache2-foreground"]
