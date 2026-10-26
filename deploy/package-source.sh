#!/usr/bin/env bash
# Package public source files from Git's index/worktree, excluding secrets and runtime state.
set -euo pipefail
PACKAGE_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PACKAGE_ROOT"
git ls-files --cached --others --exclude-standard -z -- . ':!:var/**' ':!:public/build/**' ':!:public/bundles/**' |
    COPYFILE_DISABLE=1 tar --no-xattrs --null --no-recursion -czf "$PACKAGE_ROOT/deploy/source.tar.gz" -T -
