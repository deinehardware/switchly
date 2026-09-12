#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_dir"

failures=0

if command -v php >/dev/null 2>&1; then
    printf '%s\n' 'PHP-Syntax prüfen …'
    if ! find . -type f -name '*.php' -not -path './data/*' -exec php -l {} \; >/dev/null; then
        failures=$((failures + 1))
    fi
else
    printf '%s\n' 'Hinweis: PHP ist nicht installiert; PHP-Syntaxprüfung übersprungen.'
fi

if command -v node >/dev/null 2>&1; then
    printf '%s\n' 'JavaScript-Syntax prüfen …'
    if ! find public/assets -type f -name '*.js' -exec node --check {} \; >/dev/null; then
        failures=$((failures + 1))
    fi
    printf '%s\n' 'Markdown-Verweise prüfen …'
    node scripts/check-markdown-links.mjs || failures=$((failures + 1))
else
    printf '%s\n' 'Hinweis: Node.js ist nicht installiert; JavaScript-Syntaxprüfung übersprungen.'
fi

printf '%s\n' 'CSS-Dokumentation prüfen …'
if ! grep -q 'Zentrale Farben, Abstände und Effekte' public/assets/style.css; then
    printf '%s\n' '  Einleitende CSS-Dokumentation fehlt.' >&2
    failures=$((failures + 1))
fi
css_rule_count="$(grep -Ec '^[[:space:]]*[^@/].*\{$' public/assets/style.css)"
css_comment_count="$(grep -c '/\*' public/assets/style.css)"
if ((css_comment_count < css_rule_count)); then
    printf '  Das Stylesheet ist nicht vollständig kommentiert (%d Regeln, %d Kommentare).\n' "$css_rule_count" "$css_comment_count" >&2
    failures=$((failures + 1))
fi

printf '%s\n' 'Shell-Syntax prüfen …'
if ! find deploy scripts -type f -name '*.sh' -exec bash -n {} \;; then
    failures=$((failures + 1))
fi
if [[ ! -x scripts/version-change.sh ]]; then
    printf '%s\n' '  Versionsskript ist nicht ausführbar.' >&2
    failures=$((failures + 1))
fi
if [[ ! -x scripts/set-version.sh ]]; then
    printf '%s\n' '  Kompatibilitätsalias für das Versionsskript ist nicht ausführbar.' >&2
    failures=$((failures + 1))
fi

if command -v php >/dev/null 2>&1; then
    printf '%s\n' 'JSON-Assets prüfen …'
    if ! find public/assets -type f \( -name '*.json' -o -name '*.webmanifest' \) \
        -exec php -r '$data=file_get_contents($argv[1]); json_decode($data, true, 512, JSON_THROW_ON_ERROR);' {} \;; then
        failures=$((failures + 1))
    fi
fi

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    printf '%s\n' 'Docker-Compose-Konfiguration prüfen …'
    docker compose config --quiet || failures=$((failures + 1))
else
    printf '%s\n' 'Hinweis: Docker Compose ist nicht installiert; Compose-Prüfung übersprungen.'
fi

printf '%s\n' 'Versionskonsistenz prüfen …'
release_version="$(tr -d '[:space:]' < VERSION)"
case "$release_version" in
    [0-9]*.[0-9]*.[0-9]*-beta) ;;
    *) printf '  Ungültige VERSION: %s\n' "$release_version" >&2; failures=$((failures + 1)) ;;
esac
numeric_version="${release_version%-beta}"
grep -q "VERSION = '$numeric_version'" src/AppInfo.php || failures=$((failures + 1))
grep -q "CHANNEL = 'Beta'" src/AppInfo.php || failures=$((failures + 1))
grep -q "org.opencontainers.image.version=\"$release_version\"" Dockerfile || failures=$((failures + 1))
grep -q "v${numeric_version} Beta" README.md || failures=$((failures + 1))
grep -q 'org.opencontainers.image.licenses="GPL-3.0-only"' Dockerfile || failures=$((failures + 1))
grep -q 'GNU GENERAL PUBLIC LICENSE' LICENSE || failures=$((failures + 1))
grep -q 'Version 3, 29 June 2007' LICENSE || failures=$((failures + 1))

printf '%s\n' 'Webroot und lokale Bibliotheken prüfen …'
grep -q 'DocumentRoot /var/www/html/public' deploy/docker/apache/000-default.conf || failures=$((failures + 1))
grep -q '^USER www-data:www-data$' Dockerfile || failures=$((failures + 1))
grep -q '^EXPOSE 8080$' Dockerfile || failures=$((failures + 1))
if grep -q 'docker-php-ext-install' Dockerfile; then
    printf '%s\n' '  Bereits enthaltene PHP-Erweiterungen werden unnötig neu kompiliert.' >&2
    failures=$((failures + 1))
fi
grep -q 'foreach (\["pdo_sqlite", "curl", "openssl", "Zend OPcache"\]' Dockerfile || failures=$((failures + 1))
grep -q 'restart: always' docker-compose.yml || failures=$((failures + 1))
grep -q 'no-new-privileges:true' docker-compose.yml || failures=$((failures + 1))
if grep -R -n 'SWOS_[A-Z_]*' Dockerfile docker-compose.yml .env.example src public bin deploy docs README.md; then
    printf '%s\n' '  Veraltete SWOS_*-Umgebungsvariable gefunden.' >&2
    failures=$((failures + 1))
fi
grep -q 'DocumentRoot /var/www/switchly/public' deploy/bare-metal/apache-switchly.conf || failures=$((failures + 1))
grep -q 'Chart.js v4.5.0' public/assets/vendor/chart.js/chart.umd.min.js || failures=$((failures + 1))
grep -q 'assets/vendor/chart.js/chart.umd.min.js?v=4.5.0' public/assets/dashboard.js || failures=$((failures + 1))
grep -q 'assets/vendor/qrcodejs/qrcode.min.js?v=1.0.0' public/profile.php || failures=$((failures + 1))
grep -q "require_once __DIR__ . '/../../src/TwoFactorService.php'" public/api/2fa.php || failures=$((failures + 1))
if grep -q -E 'Database::|TOTP::|UPDATE users|SELECT .*users' public/api/2fa.php; then
    printf '%s\n' '  2FA-Geschäftslogik im öffentlichen HTTP-Controller gefunden.' >&2
    failures=$((failures + 1))
fi
grep -q 'Workerstatus ist derzeit nicht verfügbar.' public/api/health.php || failures=$((failures + 1))
if grep -q "\$monitor.*getMessage" public/api/health.php; then
    printf '%s\n' '  Interne Health-Fehlermeldung wird öffentlich ausgegeben.' >&2
    failures=$((failures + 1))
fi
grep -q 'The MIT License' licenses/Chart.js-LICENSE.md || failures=$((failures + 1))
grep -q 'MIT License' licenses/QRCode.js-LICENSE.md || failures=$((failures + 1))
grep -q 'smtp_password_configured' public/api/alerts.php || failures=$((failures + 1))
if grep -Fq "unset(\$settings['smtp_password']);" public/api/alerts.php; then :; else
    printf '%s\n' '  SMTP-Passwort wird von der Alert-API nicht redigiert.' >&2
    failures=$((failures + 1))
fi
printf '%s  %s\n' \
    'e0059220c10bff95fdb7f5b77b9bfffba68b3ccf4eab4f7398ee7aef3cffca2b' \
    'public/assets/vendor/chart.js/chart.umd.min.js' \
    '7ce71906192b4a8bb2601599413a8740e9666c99486010fd42cf2a521cd02ff7' \
    'public/assets/vendor/qrcodejs/qrcode.min.js' | sha256sum --check --status || failures=$((failures + 1))
if grep -R -n -E 'cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|unpkg\.com' public --exclude-dir=vendor; then
    printf '%s\n' '  Externer CDN-Verweis im öffentlichen Anwendungscode gefunden.' >&2
    failures=$((failures + 1))
fi

printf '%s\n' 'Quellcodedokumentation prüfen …'
for source_file in $(find public src bin -type f -name '*.php' | sort); do
    if ! grep -q '/\*\*' "$source_file"; then
        printf '  PHP-Dokumentationsblock fehlt: %s\n' "$source_file" >&2
        failures=$((failures + 1))
    fi
done
for source_file in public/assets/app.js public/assets/dashboard.js public/assets/profile.js; do
    if ! grep -q '/\*\*' "$source_file"; then
        printf '  JavaScript-Dokumentationsblock fehlt: %s\n' "$source_file" >&2
        failures=$((failures + 1))
    fi
done

printf '%s\n' 'Repository-Inhalt prüfen …'
for required_file in \
    deploy/bare-metal/apache-switchly.conf \
    deploy/bare-metal/apache2-switchly.service.conf \
    deploy/bare-metal/switchly.env.example \
    deploy/bare-metal/switchly-worker.service \
    deploy/bare-metal/switchly-worker.timer; do
    if [[ ! -f "$required_file" ]]; then
        printf '  Bare-Metal-Vorlage fehlt: %s\n' "$required_file" >&2
        failures=$((failures + 1))
    fi
done
if find data -type f ! -name '.gitkeep' -print -quit | grep -q .; then
    printf '%s\n' '  Laufzeitdaten im data-Verzeichnis gefunden.' >&2
    failures=$((failures + 1))
fi
if find . -type f \( -name '.env' -o -name '*.sqlite' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' -o -name '*.swb' \) -print -quit | grep -q .; then
    printf '%s\n' '  Nicht veröffentlichbare Konfigurations- oder Laufzeitdatei gefunden.' >&2
    failures=$((failures + 1))
fi

if ((failures > 0)); then
    printf 'Qualitätsprüfung fehlgeschlagen: %d Fehler.\n' "$failures" >&2
    exit 1
fi

printf '%s\n' 'Alle verfügbaren Qualitätsprüfungen waren erfolgreich.'
