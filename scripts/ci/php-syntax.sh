#!/usr/bin/env bash
set -euo pipefail

project_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
syntax_jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '2')"
case "${syntax_jobs}" in
    ''|*[!0-9]*) syntax_jobs=2 ;;
esac
[ "${syntax_jobs}" -gt 8 ] && syntax_jobs=8
[ "${syntax_jobs}" -lt 1 ] && syntax_jobs=1

find "${project_root}/game" "${project_root}/tests" -type f -name '*.php' -print0 \
    | xargs -0 -n1 -P "${syntax_jobs}" php -l
