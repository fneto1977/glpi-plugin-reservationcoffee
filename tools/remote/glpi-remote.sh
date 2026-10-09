#!/usr/bin/env bash
# Server side of the remote E2E mode (see tools/remote-e2e.sh). Runs as root on
# the GLPI host and gives the suite the same primitives the Docker stack offers.
#
#   glpi-remote.sh sql                 SQL on stdin, tab-separated rows with header
#   glpi-remote.sh console ARGS...     bin/console as www-data
#   glpi-remote.sh log NAME [OFFSET]   GLPI log file content after OFFSET bytes (empty if missing)
#   glpi-remote.sh log-size NAME       size in bytes of a GLPI log file (0 if missing)
#   glpi-remote.sh has-file PATH       "yes" when PATH exists under the GLPI root
#   glpi-remote.sh api METHOD ENDPOINT [HEADER...]   legacy REST API from localhost, body on stdin
#   glpi-remote.sh seed                runs the seed; passwords on stdin, one per line
#
# Required environment: RC_GLPI_DIR, RC_GLPI_CONFIG_DIR, RC_GLPI_LOG_DIR, RC_HOST.
# Optional: RC_API_ADDR, address the site answers on locally (default 127.0.0.1);
# the GLPI API client must allow requests coming from it.
set -euo pipefail

glpi_dir="${RC_GLPI_DIR:?}"
config_dir="${RC_GLPI_CONFIG_DIR:?}"
log_dir="${RC_GLPI_LOG_DIR:?}"
host="${RC_HOST:?}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

run_sql() {
    umask 077
    local cnf db
    cnf="$(mktemp)"
    trap 'rm -f "${cnf}"' RETURN
    # Credentials go straight from config_db.php to a private temporary file.
    # shellcheck disable=SC2016
    db="$(php -r '
        class DBmysql {}
        require $argv[1] . "/config_db.php";
        $d = new DB();
        file_put_contents($argv[2], sprintf("[client]\nhost=%s\nuser=%s\npassword=\"%s\"\n", $d->dbhost, $d->dbuser, addcslashes($d->dbpassword, "\"\\")));
        echo $d->dbdefault;
    ' "${config_dir}" "${cnf}")"
    mariadb --defaults-extra-file="${cnf}" -D "${db}" --batch --raw
}

case "${1:-}" in
    sql)
        run_sql
        ;;
    console)
        shift
        cd "${glpi_dir}"
        sudo -u www-data php bin/console --no-interaction "$@"
        ;;
    log)
        tail -c "+$(( ${3:-0} + 1 ))" "${log_dir}/${2:?}" 2>/dev/null || true
        ;;
    log-size)
        stat -c %s "${log_dir}/${2:?}" 2>/dev/null || echo 0
        ;;
    has-file)
        if [[ -e "${glpi_dir}/${2:?}" ]]; then echo yes; else echo no; fi
        ;;
    api)
        method="${2:?}"
        endpoint="${3:?}"
        shift 3
        args=(-s -o - -w '\n%{http_code}' -X "${method}" -H "Host: ${host}")
        for header in "$@"; do
            args+=(-H "${header}")
        done
        if [[ "${method}" != GET ]]; then
            args+=(-H 'Content-Type: application/json' --data-binary @-)
        fi
        curl "${args[@]}" "http://${RC_API_ADDR:-127.0.0.1}/apirest.php/${endpoint}"
        ;;
    seed)
        IFS= read -r E2E_PASSWORD_SELFSERVICE
        IFS= read -r E2E_PASSWORD_SELFSERVICE2
        IFS= read -r E2E_PASSWORD_TECH
        IFS= read -r E2E_PASSWORD_OTHERENTITY
        export E2E_PASSWORD_SELFSERVICE E2E_PASSWORD_SELFSERVICE2 E2E_PASSWORD_TECH E2E_PASSWORD_OTHERENTITY
        export E2E_GLPI_ROOT="${glpi_dir}"
        sudo -u www-data \
            --preserve-env=E2E_PASSWORD_SELFSERVICE,E2E_PASSWORD_SELFSERVICE2,E2E_PASSWORD_TECH,E2E_PASSWORD_OTHERENTITY,E2E_GLPI_ROOT,E2E_SEED_ROOM_ITEMS,E2E_SEED_NON_ROOM_ITEM \
            php "${here}/seed_e2e.php"
        ;;
    *)
        echo "usage: $0 sql|console|log|log-size|has-file|api|seed" >&2
        exit 2
        ;;
esac
