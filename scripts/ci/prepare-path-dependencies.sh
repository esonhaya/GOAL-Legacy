#!/usr/bin/env bash
set -euo pipefail

project_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
dependency_root="${project_root}/../BoardPrep-Doctor"
required_commit="10ed9d1192f33c75bcfff2d4cf0539f8a2843b86"

if [ -d "${dependency_root}/.git" ]; then
    current_commit="$(git -C "${dependency_root}" rev-parse HEAD)"
    if [ "${current_commit}" = "${required_commit}" ]; then
        exit 0
    fi

    if [ "${CI:-}" != "true" ]; then
        printf 'BoardPrep-Doctor is at %s; expected locked commit %s.\n' "${current_commit}" "${required_commit}" >&2
        exit 1
    fi
fi

if [ -e "${dependency_root}" ] && [ ! -d "${dependency_root}/.git" ]; then
    printf 'Cannot prepare path dependency at %s because it is not a Git checkout.\n' "${dependency_root}" >&2
    exit 1
fi

if [ ! -d "${dependency_root}/.git" ]; then
    git init "${dependency_root}" >/dev/null
    git -C "${dependency_root}" remote add origin https://github.com/esonhaya/haya-doctor.git
fi

git -C "${dependency_root}" fetch --depth=1 origin "${required_commit}"
git -C "${dependency_root}" checkout --detach FETCH_HEAD >/dev/null
