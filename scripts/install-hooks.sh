#!/usr/bin/env bash
# Installs this repo's git hooks. Run once after cloning (or after pulling a
# change to scripts/post-commit):
#   ./scripts/install-hooks.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install -m 755 "$root/scripts/post-commit" "$root/.git/hooks/post-commit"
echo "installed post-commit hook -> .git/hooks/post-commit"
