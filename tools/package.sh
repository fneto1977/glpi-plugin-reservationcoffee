#!/usr/bin/env bash
# Builds dist/reservationcoffee-<version>.zip with only the runtime files of the
# plugin (allowlist): no tests, no vendor, no Composer or tooling files.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_dir="${repo_root}/reservationcoffee"
dist_dir="${repo_root}/dist"

version="$(sed -nE "s/^define\('PLUGIN_RESERVATIONCOFFEE_VERSION', '([^']+)'\);/\1/p" "${plugin_dir}/setup.php")"
if [[ -z "${version}" ]]; then
    echo "Unable to read the plugin version from setup.php" >&2
    exit 1
fi
if ! grep -q "<num>${version}</num>" "${plugin_dir}/reservationcoffee.xml"; then
    echo "reservationcoffee.xml does not declare version ${version}" >&2
    exit 1
fi

staging="$(mktemp -d)"
trap 'rm -rf "${staging}"' EXIT
target="${staging}/reservationcoffee"
mkdir -p "${target}/locales"

cp "${plugin_dir}/setup.php" "${plugin_dir}/hook.php" "${plugin_dir}/LICENSE" "${plugin_dir}/reservationcoffee.xml" "${target}/"
cp -R "${plugin_dir}/src" "${plugin_dir}/templates" "${plugin_dir}/public" "${plugin_dir}/front" "${plugin_dir}/ajax" "${target}/"
cp "${plugin_dir}"/locales/*.po "${plugin_dir}"/locales/*.mo "${target}/locales/"
cp "${repo_root}/README.md" "${repo_root}/CHANGELOG.md" "${target}/"

# Reproducible archive: fixed timestamps, sorted entries, no extra attributes.
find "${target}" -name '.DS_Store' -delete
find "${target}" -exec touch -t 202601010000 {} +
mkdir -p "${dist_dir}"
archive="${dist_dir}/reservationcoffee-${version}.zip"
rm -f "${archive}"
(cd "${staging}" && find reservationcoffee -type f | LC_ALL=C sort | zip -X -q "${archive}" -@)

(cd "${dist_dir}" && shasum -a 256 "$(basename "${archive}")" > "$(basename "${archive}").sha256")
echo "${archive}"
cat "${archive}.sha256"
