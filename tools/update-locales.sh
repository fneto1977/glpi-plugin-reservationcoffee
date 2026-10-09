#!/usr/bin/env bash
# Extracts translatable strings into locales/reservationcoffee.pot, merges them
# into every .po file and compiles the .mo files GLPI loads at runtime.
# Requires GNU gettext (xgettext, msgmerge, msgfmt).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
plugin="$root/reservationcoffee"
locales="$plugin/locales"
pot="$locales/reservationcoffee.pot"
keywords=(--keyword=__ --keyword=__s --keyword=_n:1,2 --keyword=_sn:1,2 --keyword=_x:1c,2 --keyword=_sx:1c,2 --keyword=_nx:1c,2,3)

cd "$plugin"
php_files=$(find setup.php hook.php src -name '*.php' | sort)
twig_files=$(find templates -name '*.twig' | sort)

# shellcheck disable=SC2086
xgettext --from-code=UTF-8 --language=PHP --no-wrap --sort-by-file \
  --package-name=reservationcoffee --copyright-holder='reservationcoffee contributors' \
  "${keywords[@]}" -o "$pot" $php_files
# Twig call syntax is close enough to Python for gettext extraction.
# shellcheck disable=SC2086
xgettext --from-code=UTF-8 --language=Python --no-wrap --sort-by-file --join-existing \
  "${keywords[@]}" -o "$pot" $twig_files

for po in "$locales"/*.po; do
  msgmerge --quiet --update --backup=none --no-wrap "$po" "$pot"
  msgfmt --check --output-file="${po%.po}.mo" "$po"
done

untranslated=0
for po in "$locales"/*.po; do
  stats=$(msgfmt --statistics --output-file=/dev/null "$po" 2>&1)
  echo "$(basename "$po"): $stats"
  if grep -qE 'untranslated|fuzzy' <<<"$stats"; then
    untranslated=1
  fi
done
exit "$untranslated"
