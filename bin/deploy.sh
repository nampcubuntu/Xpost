#!/usr/bin/env bash
#
# Deploy the current main branch. Run as www-data:
#
#   sudo -u www-data bash /var/www/xpost/bin/deploy.sh
#   sudo -u www-data bash /var/www/xpost/bin/deploy.sh --first-run
#
# --first-run skips the `git pull` and the maintenance window, for a box that
# bin/server-bootstrap.sh has just cloned into.
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

if [[ $FIRST_RUN == false ]]; then
    say "Bật chế độ bảo trì"
    $PHP artisan down --retry=60

    say "Kéo mã mới"
    git pull --ff-only
fi

say "Dependency PHP"
composer install --no-dev --optimize-autoloader --no-interaction

say "Build frontend"
npm ci --silent
npm run build

say "Migrate"
# --force because production migrations are never interactive; --isolated so a
# second deploy running concurrently does not migrate the same database twice.
$PHP artisan migrate --force --isolated

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

say "Kiểm tra"
$PHP artisan about --only=environment,drivers
echo
echo "Horizon:  $($PHP artisan horizon:status)"
echo "Xong."
