#!/usr/bin/env bash
# RC-052: runs the full E2E suite against the release package (not the source
# tree). Builds the package, mounts its extracted copy in the GLPI container,
# runs Playwright, then mounts the source tree back.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env"
compose=(docker compose --env-file "${env_file}" -f "${repo_root}/docker/compose.e2e.yml")
plugin_path=/var/www/glpi/plugins/reservationcoffee

package_output="$("${repo_root}/tools/package.sh")"
archive="${package_output%%$'\n'*}"
extract_dir="${repo_root}/dist/homologation"
rm -rf "${extract_dir}"
mkdir -p "${extract_dir}"
unzip -q "${archive}" -d "${extract_dir}"

restore_source_mount() {
    unset E2E_PLUGIN_DIR
    "${compose[@]}" up -d --wait --force-recreate glpi >/dev/null 2>&1 || true
    echo "Source tree mounted back in the GLPI container."
}
trap restore_source_mount EXIT

export E2E_PLUGIN_DIR="${extract_dir}/reservationcoffee"
"${compose[@]}" up -d --wait --force-recreate glpi

# Make sure the container really runs the package, not the source tree.
"${compose[@]}" exec -T glpi sh -c "test -f ${plugin_path}/CHANGELOG.md && test ! -e ${plugin_path}/tests && test ! -e ${plugin_path}/vendor && test ! -e ${plugin_path}/composer.json"
echo "Package $(basename "${archive}") mounted at ${plugin_path}."

"${repo_root}/tools/e2e-up.sh"
(cd "${repo_root}/e2e" && npx playwright test "$@")
