#!/usr/bin/env bash
#
# Deploy the current branch. Run as www-data:
#
#   sudo -u www-data bash /var/www/xpost/bin/deploy.sh
#   sudo -u www-data bash /var/www/xpost/bin/deploy.sh --first-run
#
# --first-run skips the `git pull` and the maintenance window, for a box that
# bin/server-bootstrap.sh has just cloned into.
#
# Composer and the frontend build are skipped when nothing they depend on
# changed. On a typical push that touches only PHP files this turns a two
# minute deploy into about fifteen seconds, which is what makes the
# auto-deploy loop in bin/auto-deploy.sh usable.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xpost}"
PHP="${PHP:-/usr/bin/php8.5}"
FIRST_RUN=false

[[ "${1:-}" == "--first-run" ]] && FIRST_RUN=true

cd "$APP_DIR"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

# Always lift maintenance mode on the way out, including after a failure --
# a deploy that dies half way must not leave the site showing a 503 forever.
cleanup() {
    if [[ $FIRST_RUN == false ]] && [[ -f storage/framework/maintenance.php ]]; then
        $PHP artisan up || true
    fi
}
trap cleanup EXIT

BEFORE=""

if [[ $FIRST_RUN == false ]]; then
    BEFORE=$(git rev-parse HEAD)

    say "Kéo mã mới"
    git pull --ff-only

    AFTER=$(git rev-parse HEAD)

    if [[ $BEFORE == "$AFTER" ]]; then
        say "Không có commit mới — không làm gì"
        exit 0
    fi

    echo "  ${BEFORE:0:7} → ${AFTER:0:7}"
    git --no-pager log --oneline "$BEFORE..$AFTER" | head -10

    say "Bật chế độ bảo trì"
    $PHP artisan down --retry=60
fi

# True when any path given changed between the two commits, or on a first run.
changed() {
    [[ -z $BEFORE ]] && return 0
    git diff --name-only "$BEFORE" HEAD -- "$@" | grep -q .
}

if changed composer.lock composer.json; then
    say "Dependency PHP"
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "→ composer.lock không đổi, bỏ qua composer install"
fi

# vite.config.js and resources/ matter as much as the lockfile: a changed Vue
# component with an unchanged package-lock still needs a rebuild.
if changed package-lock.json package.json vite.config.js resources/; then
    say "Build frontend"
    changed package-lock.json package.json && npm ci --silent
    npm run build
else
    echo "→ frontend không đổi, bỏ qua npm build"
fi

if changed database/migrations/; then
    say "Migrate"
    # --force because production migrations are never interactive; --isolated so
    # a second deploy running concurrently does not migrate the same database twice.
    $PHP artisan migrate --force --isolated
else
    echo "→ không có migration mới"
fi

if [[ $FIRST_RUN == true ]]; then
    say "Storage link"
    $PHP artisan storage:link
fi

say "Cache cấu hình"
# Clear before caching: a stale config cache silently outranks the .env you
# just edited, which is the classic "why is it still using the old password".
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

say "Khởi động lại worker"
# terminate, not restart: workers finish the job in flight before exiting, and
# Supervisor brings them back on the new code.
$PHP artisan horizon:terminate

if [[ $FIRST_RUN == false ]]; then
    say "Tắt chế độ bảo trì"
    $PHP artisan up
fi

say "Đã deploy $(git rev-parse --short HEAD)"
