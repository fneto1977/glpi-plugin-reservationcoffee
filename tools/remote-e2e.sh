#!/usr/bin/env bash
# Remote commands are built locally on purpose.
# shellcheck disable=SC2029

# Runs the E2E suite against a remote homologation server reached over SSH
# (never production). The plugin must already be installed and active there.
#
#   tools/remote-e2e.sh prepare    copies the server-side helpers to /tmp/rc-e2e
#   tools/remote-e2e.sh seed       creates the E2E users/entity/form and configures the plugin
#   tools/remote-e2e.sh test [...] runs Playwright (extra arguments are passed through)
#   tools/remote-e2e.sh teardown   removes /tmp/rc-e2e from the server
#
# Required environment (never stored in the repository):
#   E2E_REMOTE_SSH            user@host with key-based access
#   E2E_REMOTE_GLPI_DIR       GLPI root on the server
#   E2E_REMOTE_GLPI_LOG_DIR   GLPI_LOG_DIR on the server
#   E2E_BASE_URL              URL of the GLPI site
#   E2E_SEED_ROOM_ITEMS       4 existing ReservationItem IDs used as rooms
#   E2E_SEED_NON_ROOM_ITEM    1 existing ReservationItem ID that is not a room
# Optional: E2E_REMOTE_GLPI_CONFIG_DIR (default /etc/glpi); E2E_REMOTE_API_ADDR, local
# address the site answers on (default 127.0.0.1), which a GLPI API client must
# allow without app token for E2E-18.
# The E2E users' passwords come from the local .env (tools/init-env.sh).
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
remote_dir=/tmp/rc-e2e
: "${E2E_REMOTE_SSH:?}" "${E2E_REMOTE_GLPI_DIR:?}" "${E2E_REMOTE_GLPI_LOG_DIR:?}" "${E2E_BASE_URL:?}"

ssh_opts=(-o BatchMode=yes)
remote_env() {
    printf "RC_GLPI_DIR=%q RC_GLPI_CONFIG_DIR=%q RC_GLPI_LOG_DIR=%q RC_HOST=%q E2E_SEED_ROOM_ITEMS=%q E2E_SEED_NON_ROOM_ITEM=%q" \
        "${E2E_REMOTE_GLPI_DIR}" "${E2E_REMOTE_GLPI_CONFIG_DIR:-/etc/glpi}" "${E2E_REMOTE_GLPI_LOG_DIR}" \
        "$(sed -E 's#^[a-z]+://([^/]+).*#\1#' <<<"${E2E_BASE_URL}")" \
        "${E2E_SEED_ROOM_ITEMS:-}" "${E2E_SEED_NON_ROOM_ITEM:-}"
}

case "${1:-}" in
    prepare)
        ssh "${ssh_opts[@]}" "${E2E_REMOTE_SSH}" "install -d -m 755 ${remote_dir}"
        scp -q "${ssh_opts[@]}" "${repo_root}/tools/remote/glpi-remote.sh" "${repo_root}/tools/seed_e2e.php" "${E2E_REMOTE_SSH}:${remote_dir}/"
        ssh "${ssh_opts[@]}" "${E2E_REMOTE_SSH}" "chmod 755 ${remote_dir}/glpi-remote.sh && chmod 644 ${remote_dir}/seed_e2e.php"
        echo "Helpers copied to ${E2E_REMOTE_SSH}:${remote_dir}"
        ;;
    seed)
        : "${E2E_SEED_ROOM_ITEMS:?}" "${E2E_SEED_NON_ROOM_ITEM:?}"
        set -a
        # shellcheck disable=SC1091
        source "${repo_root}/.env"
        set +a
        output_dir="${repo_root}/e2e/.auth"
        mkdir -p "${output_dir}"
        printf '%s\n' "${E2E_PASSWORD_SELFSERVICE:?}" "${E2E_PASSWORD_SELFSERVICE2:?}" "${E2E_PASSWORD_TECH:?}" "${E2E_PASSWORD_OTHERENTITY:?}" |
            ssh "${ssh_opts[@]}" "${E2E_REMOTE_SSH}" "$(remote_env) ${remote_dir}/glpi-remote.sh seed" > "${output_dir}/seed.json.tmp" || {
                cat "${output_dir}/seed.json.tmp" >&2
                rm -f "${output_dir}/seed.json.tmp"
                exit 1
            }
        mv "${output_dir}/seed.json.tmp" "${output_dir}/seed.json"
        ssh "${ssh_opts[@]}" "${E2E_REMOTE_SSH}" "$(remote_env) ${remote_dir}/glpi-remote.sh console cache:clear" >/dev/null
        echo "Seed written to ${output_dir}/seed.json"
        ;;
    test)
        shift
        cd "${repo_root}/e2e"
        npx playwright test "$@"
        ;;
    teardown)
        ssh "${ssh_opts[@]}" "${E2E_REMOTE_SSH}" "rm -rf -- ${remote_dir}"
        echo "Removed ${E2E_REMOTE_SSH}:${remote_dir}"
        ;;
    *)
        echo "usage: $0 prepare|seed|test|teardown" >&2
        exit 2
        ;;
esac
