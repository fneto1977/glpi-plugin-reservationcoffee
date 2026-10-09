#!/usr/bin/env bash
# Creates .env from .env.example with random passwords. Never overwrites an existing .env.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"

if [[ -f "${env_file}" ]]; then
    echo "${env_file} already exists; leaving it untouched."
    exit 0
fi

umask 077
while IFS= read -r line; do
    if [[ "${line}" =~ ^(E2E_[A-Z0-9_]*PASSWORD[A-Z0-9_]*)=change-me ]]; then
        echo "${BASH_REMATCH[1]}=$(openssl rand -hex 16)"
    else
        echo "${line}"
    fi
done < "${repo_root}/.env.example" > "${env_file}"

echo "Created ${env_file} with random passwords."
