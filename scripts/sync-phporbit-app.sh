#!/usr/bin/env bash
# Pushes phporbit's current branch to origin, then updates the phporbit/phporbit
# dependency in the sibling phporbit-app checkout so it tracks main's tip.
#
# Installed as this repo's post-commit hook by scripts/install-hooks.sh, so it
# normally runs automatically after every commit. Skip once with:
#   SKIP_ORBIT_SYNC=1 git commit ...
set -euo pipefail

orbit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
app_dir="$orbit_dir/../phporbit-app"

if [ ! -d "$app_dir" ]; then
    echo "[sync-phporbit-app] $app_dir not found, skipping" >&2
    exit 0
fi
app_dir="$(cd "$app_dir" && pwd)"

branch="$(git -C "$orbit_dir" rev-parse --abbrev-ref HEAD)"
if [ "$branch" = "HEAD" ]; then
    echo "[sync-phporbit-app] detached HEAD, not pushing" >&2
    exit 1
fi

echo "[sync-phporbit-app] pushing $branch to origin..."
git -C "$orbit_dir" push origin "HEAD:$branch"

echo "[sync-phporbit-app] updating phporbit/phporbit in phporbit-app (composer resolves dev-main against Packagist, which re-indexes shortly after the push above)..."
( cd "$app_dir" && composer update phporbit/phporbit --with-all-dependencies )

echo "[sync-phporbit-app] done: phporbit-app/vendor/phporbit/phporbit now tracks $(git -C "$orbit_dir" rev-parse --short HEAD)."
echo "[sync-phporbit-app] phporbit-app/composer.lock changed locally and is NOT committed/pushed automatically - review and commit it there when ready."
