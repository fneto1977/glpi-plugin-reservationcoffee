#!/usr/bin/env bash
# Runs the PHPUnit integration suite inside the GLPI container, against the
# seeded E2E database (start it first with tools/e2e-up.sh).
# PHPUnit is used as a PHAR because its dependencies are scoped and do not
# clash with the Symfony components shipped by GLPI.
set -euo pipefail

phpunit_version="11.5.57"
phpunit_sha256="c704fdd37c6c6ab7adb386f31b4e186cd51156801fa9028b5ea8099a2cd7f106"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"
cache_dir="${repo_root}/tools/.cache"
phar="${cache_dir}/phpunit-${phpunit_version}.phar"

if [[ ! -f "${phar}" ]]; then
    mkdir -p "${cache_dir}"
    curl -fsSL -o "${phar}.tmp" "https://phar.phpunit.de/phpunit-${phpunit_version}.phar"
    mv "${phar}.tmp" "${phar}"
fi
if ! echo "${phpunit_sha256}  ${phar}" | shasum -a 256 -c - >/dev/null 2>&1; then
    echo "Checksum mismatch for ${phar}; delete it and retry." >&2
    exit 1
fi

docker compose --env-file "${env_file}" -f "${repo_root}/docker/compose.e2e.yml" \
    exec -T -u www-data -w /var/www/glpi/plugins/reservationcoffee glpi \
    php "/opt/reservationcoffee-tools/.cache/phpunit-${phpunit_version}.phar" \
    -c phpunit.integration.xml.dist "$@"
