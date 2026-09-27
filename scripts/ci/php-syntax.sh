#!/usr/bin/env bash
set -euo pipefail

project_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
syntax_jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '2')"
case "${syntax_jobs}" in
    ''|*[!0-9]*) syntax_jobs=2 ;;
esac
[ "${syntax_jobs}" -gt 8 ] && syntax_jobs=8
[ "${syntax_jobs}" -lt 1 ] && syntax_jobs=1

lint_file() {
    local file="$1"
    local output
    local status

    output="$(php -l "${file}" 2>&1)"
    status=$?
    printf '%s\n' "${output}"
    if [ "${status}" -ne 0 ]; then
        diagnostic="$(printf '%s' "${output}" | tr '\r\n' '  ')"
        printf '::error file=%s::PHP syntax check failed with exit code %s: %s\n' "${file}" "${status}" "${diagnostic}" >&2
        return "${status}"
    fi
}

export -f lint_file

find "${project_root}/game" "${project_root}/tests" -type f -name '*.php' -print0 \
    | xargs -0 -n1 -P "${syntax_jobs}" bash -c 'lint_file "$1"' bash
