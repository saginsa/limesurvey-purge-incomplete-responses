#!/usr/bin/env bash
# Integration tests: LimeSurvey + MySQL in Docker, the plugin installed from the release zip
# through "Upload & install", scenarios in test_plugin.py.
#   LS_TAG=7.5.1+261007   LimeSurvey tag on GitHub (default)
#   LS_TARBALL=path       use a local copy of the tag archive instead of downloading it
#   KEEP=1                leave the containers running (http://localhost:${PIR_PORT:-8099}/index.php/admin)
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
LS_TAG=${LS_TAG:-7.5.1+261007}
export PIR_PORT=${PIR_PORT:-8099}

"$root/tools/build-zip.sh" >/dev/null
version=$(sed -n 's:.*<version>\([0-9][0-9.]*\)</version>.*:\1:p' "$root/config.xml" | head -1)
export PIR_ZIP="$root/dist/PurgeIncompleteResponses-$version.zip"

if [ -n "${LS_TARBALL:-}" ]; then
    cp "$LS_TARBALL" "$here/limesurvey.tar.gz"
elif [ ! -s "$here/limesurvey.tar.gz" ]; then
    curl -fsSL -o "$here/limesurvey.tar.gz" "https://github.com/LimeSurvey/LimeSurvey/archive/refs/tags/${LS_TAG}.tar.gz"
fi

cd "$here"
compose() { docker compose -f "$here/docker-compose.yml" "$@"; }
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then compose logs --tail=50 web || true; fi
    if [ "${KEEP:-0}" != 1 ]; then compose down -v --remove-orphans >/dev/null 2>&1 || true; fi
    exit $status
}
trap cleanup EXIT

compose down -v --remove-orphans >/dev/null 2>&1 || true
compose up -d --build --wait
compose exec -T -u www-data web php application/commands/console.php install admin "${PIR_ADMIN_PASSWORD:-Pir-test-2026}" Admin admin@example.com
python3 "$here/test_plugin.py"
