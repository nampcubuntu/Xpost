#!/usr/bin/env bash
#
# Poll GitHub and deploy when the watched branch moves.
#
# Driven by a systemd timer (deploy/xpost-autodeploy.timer). Polling rather
# than a webhook because the server sits on a LAN behind NAT: GitHub cannot
# reach in, so the server has to ask. Swap this for a webhook only once the
# box is publicly reachable -- polling costs one `git ls-remote` a minute and
# needs no inbound port, no secret stored at GitHub, and no public endpoint.
#
# Usage:
#   sudo -u www-data bash /var/www/xpost/bin/auto-deploy.sh
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xpost}"

# `staging`, not `main`: CI fast-forwards that branch only after the test job
# passes, so a red commit never reaches this box. Watching `main` directly
# would deploy code the moment it is pushed, tests still running.
BRANCH="${BRANCH:-staging}"
PHP="${PHP:-/usr/bin/php8.5}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/up}"
LOCK="/tmp/xpost-autodeploy.lock"

# A deploy takes longer than the timer interval. Without this, a slow build
# would have a second run start on top of it mid-`composer install`.
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "$(date '+%F %T')  một lần deploy khác đang chạy — bỏ qua lượt này"
    exit 0
fi

cd "$APP_DIR"

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git ls-remote origin "refs/heads/${BRANCH}" | cut -f1)

if [[ -z $REMOTE ]]; then
    echo "$(date '+%F %T')  không đọc được origin/${BRANCH} — bỏ qua"
    exit 1
fi

if [[ $LOCAL == "$REMOTE" ]]; then
    # Quiet on the common path: this runs every minute and there is no value in
    # a log line saying nothing happened.
    exit 0
fi

echo "════════════════════════════════════════════════════════════"
echo "$(date '+%F %T')  commit mới trên ${BRANCH}: ${LOCAL:0:7} → ${REMOTE:0:7}"
echo "════════════════════════════════════════════════════════════"

bash "${APP_DIR}/bin/deploy.sh"

# Confirm the site actually answers. Deliberately no automatic rollback: a
# migration has already run by this point and `migrate:rollback` is not a safe
# thing to fire unattended. Better to fail loudly and let a human look.
echo
echo "→ Kiểm tra sức khoẻ: ${HEALTH_URL}"

if curl -fsS --max-time 15 "$HEALTH_URL" >/dev/null 2>&1; then
    echo "✓ Deploy $(git rev-parse --short HEAD) OK"
else
    echo "✗ CẢNH BÁO: $(git rev-parse --short HEAD) đã deploy nhưng /up không trả lời."
    echo "  Xem: journalctl -u xpost-autodeploy -n 50"
    echo "        tail -50 ${APP_DIR}/storage/logs/laravel.log"
    exit 1
fi
