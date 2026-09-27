#!/usr/bin/env bash
set -euo pipefail

project_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"

find "${project_root}/game" "${project_root}/tests" -type f -name '*.php' -print0 \
    | xargs -0 -n1 php -l
