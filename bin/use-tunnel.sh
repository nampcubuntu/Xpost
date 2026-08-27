#!/usr/bin/env bash
#
# Point the local .env at a tunnel URL, or back at localhost.
#
#   bash bin/use-tunnel.sh https://cf9a002bc9ec45.lhr.life
#   bash bin/use-tunnel.sh off
#
# X refuses a redirect_uri that is not a public https URL, so testing OAuth
# locally means running behind a tunnel. Three settings have to move together
# and forgetting SANCTUM_STATEFUL_DOMAINS is the one that bites: the login
# succeeds, then the very next request is treated as token auth instead of
# session auth, returns 401, and the SPA bounces you back to the login screen.
#
set -euo pipefail

cd "$(dirname "$0")/.."

TARGET="${1:-}"
PHP="${PHP:-./.tools/php85/php.exe}"

if [[ -z $TARGET ]]; then
    echo "Dùng: bash bin/use-tunnel.sh <https://xxx.lhr.life | off>"
    exit 1
fi

if [[ $TARGET == "off" ]]; then
    URL="http://localhost:8000"
    HOST="localhost:8000"
else
    URL="${TARGET%/}"
    HOST="${URL#https://}"
    HOST="${HOST#http://}"

    if [[ $URL != https://* ]]; then
        echo "Tunnel phải là https — X từ chối mọi redirect_uri khác." >&2
        exit 1
    fi
fi

[[ -f .env.backup-tunnel ]] || cp .env .env.backup-tunnel

set_env() {
    if grep -qE "^$1=" .env; then
        perl -pi -e "s|^\Q$1\E=.*|$1=$2|" .env
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}

set_env APP_URL "$URL"
set_env FRONTEND_URL "$URL"
# localhost stays in the list so the app is still usable directly while the
# tunnel is pointed elsewhere.
set_env SANCTUM_STATEFUL_DOMAINS "${HOST},localhost,localhost:8000,127.0.0.1,127.0.0.1:8000"

# Laravel's Sentinel guard refuses /horizon whenever a `local` app is reached
# from a public IP through a trusted proxy -- which is exactly what a tunnel
# is. It answers a bare 401 that reads as a permissions bug and is not one:
# the dashboard's own gate never gets to run, so no account can pass it. A
# publicly reachable URL is no place for APP_DEBUG either.
#
# `staging` rather than `production`: config/horizon.php has supervisors for
# it, and the strict model checks in XpostServiceProvider stay on.
if [[ $TARGET == "off" ]]; then
    set_env APP_ENV local
    set_env APP_DEBUG true
else
    set_env APP_ENV staging
    set_env APP_DEBUG false
fi

"$PHP" artisan config:clear >/dev/null

echo "APP_URL  = $URL"
echo "stateful = ${HOST},localhost,…"
echo "env      = $(grep -E '^APP_ENV=' .env | cut -d= -f2)"
echo
# `artisan serve` hands APP_ENV to its child process from its own environment,
# captured when it started -- so it keeps serving the old one however many
# times .env changes underneath it.
echo "⚠  Khởi động lại 'artisan serve' và Horizon để nhận APP_ENV mới."
echo

if [[ $TARGET != "off" ]]; then
    if [[ -f public/hot ]]; then
        # Vite serves assets over http://localhost:5173; on an https page the
        # browser blocks them as mixed content and the SPA renders blank.
        echo "⚠  public/hot đang tồn tại — Vite dev đang chạy."
        echo "   Tắt Vite rồi chạy: rm public/hot && npm run build"
        echo
    fi

    echo "Dán chuỗi này vào X Developer Portal và ô Callback URL trong Xpost:"
    echo "  ${URL}/x/oauth/callback"
else
    echo "Đã trả về localhost. Chạy 'npm run dev' để có lại HMR."
fi
