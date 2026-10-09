#!/usr/bin/env bash
# Starts the GLPI 11.0.11 homologation stack, installs and activates the plugin
# and seeds the synthetic E2E data. Safe to run repeatedly.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"
if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; run tools/init-env.sh first." >&2
    exit 1
fi

compose=(docker compose --env-file "${env_file}" -f "${repo_root}/docker/compose.e2e.yml")
console=("${compose[@]}" exec -T -u www-data glpi bin/console --no-interaction)

"${compose[@]}" up -d --wait
# plugin:install/activate exit non-zero when there is nothing to do.
run_idempotent() {
    local already="$1"
    shift
    local output
    if ! output="$("${console[@]}" "$@" 2>&1)" && ! grep -q "${already}" <<<"${output}"; then
        echo "${output}" >&2
        exit 1
    fi
    echo "${output}"
}

run_idempotent 'is already installed' plugin:install --username=glpi reservationcoffee
run_idempotent 'is already active' plugin:activate reservationcoffee
"${console[@]}" cache:clear
"${repo_root}/tools/seed-e2e.sh"
