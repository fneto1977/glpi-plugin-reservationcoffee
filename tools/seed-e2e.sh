#!/usr/bin/env bash
# Seeds synthetic E2E data into the local stack and writes the IDs to e2e/.auth/seed.json.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"
if [[ ! -f "${env_file}" ]]; then
    echo "Missing ${env_file}; copy .env.example first." >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "${env_file}"
set +a

output_dir="${repo_root}/e2e/.auth"
mkdir -p "${output_dir}"

docker compose --env-file "${env_file}" -f "${repo_root}/docker/compose.e2e.yml" \
    exec -T -u www-data \
    -e E2E_PASSWORD_SELFSERVICE \
    -e E2E_PASSWORD_SELFSERVICE2 \
    -e E2E_PASSWORD_TECH \
    -e E2E_PASSWORD_OTHERENTITY \
    glpi php /opt/reservationcoffee-tools/seed_e2e.php > "${output_dir}/seed.json.tmp" || {
        cat "${output_dir}/seed.json.tmp" >&2
        rm -f "${output_dir}/seed.json.tmp"
        exit 1
    }

mv "${output_dir}/seed.json.tmp" "${output_dir}/seed.json"
echo "Seed written to ${output_dir}/seed.json"
