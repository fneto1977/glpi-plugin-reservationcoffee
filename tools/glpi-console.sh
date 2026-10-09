#!/usr/bin/env bash
# Runs a GLPI console command inside the local E2E container, as www-data.
# Usage: tools/glpi-console.sh plugin:list
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"
if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; copy .env.example first." >&2
    exit 1
fi

exec docker compose --env-file "${env_file}" -f "${repo_root}/docker/compose.e2e.yml" \
    exec -T -u www-data glpi bin/console "$@"
