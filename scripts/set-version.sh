#!/usr/bin/env bash
# Updates every current-release reference from the value stored in VERSION.
# Historical CHANGELOG entries are intentionally never modified.
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_dir"

usage() {
    printf '%s\n' \
        'Usage: bash scripts/set-version.sh <major.minor.patch[-beta]>' \
        'Example: bash scripts/set-version.sh 1.4.6'
}

requested_version="${1:-}"
if [[ "$requested_version" == "-h" || "$requested_version" == "--help" ]]; then
    usage
    exit 0
fi

if [[ "$requested_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    new_numeric_version="$requested_version"
    new_release_version="${requested_version}-beta"
elif [[ "$requested_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+-beta$ ]]; then
    new_release_version="$requested_version"
    new_numeric_version="${requested_version%-beta}"
else
    usage >&2
    exit 2
fi

current_release_version="$(tr -d '[:space:]' < VERSION)"
if [[ ! "$current_release_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+-beta$ ]]; then
    printf 'Invalid current VERSION value: %s\n' "$current_release_version" >&2
    exit 2
fi
current_numeric_version="${current_release_version%-beta}"

if [[ "$new_release_version" == "$current_release_version" ]]; then
    printf 'Version is already %s. Running quality checks only.\n' "$new_release_version"
    bash scripts/quality-check.sh
    exit 0
fi

# These files contain only references to the current release. The changelog is
# excluded so past release numbers remain immutable.
version_files=(
    src/AppInfo.php
    Dockerfile
    README.md
    docs/installation.md
    docs/reference.md
    public/index.php
    public/login.php
    public/profile.php
    public/verify_email.php
    public/assets/switchly-logo.svg
)

old_release_pattern="${current_release_version//./\\.}"
old_numeric_pattern="${current_numeric_version//./\\.}"
for file in "${version_files[@]}"; do
    [[ -f "$file" ]] || { printf 'Required version file is missing: %s\n' "$file" >&2; exit 1; }
    sed -i \
        -e "s/${old_release_pattern}/${new_release_version}/g" \
        -e "s/${old_numeric_pattern}/${new_numeric_version}/g" \
        "$file"
done

printf '%s\n' "$new_release_version" > VERSION

printf 'Updated Switchly from %s to %s.\n' "$current_release_version" "$new_release_version"
printf '%s\n' 'CHANGELOG.md was not modified; add the release notes manually.'
bash scripts/quality-check.sh
