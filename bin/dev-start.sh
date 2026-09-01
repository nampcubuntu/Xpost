#!/usr/bin/env bash
#
# Bring the whole development stack up after a reboot.
#
#   bash bin/dev-start.sh
#
# Six things have to be running and only one of them survives a restart, so
# this has been done by hand more than once -- and the failure it produces is
# Cloudflare's Error 1033, which says nothing about which of the six is missing.
#
# Safe to run when things are already up: every step checks first and starts
# only what is down, so this is also the way to recover from "one of them died"
# without working out which one.
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

PHP="${PHP:-./.tools/php85/php.exe}"
DISTRO="${WSL_DISTRO:-Ubuntu-Redis}"
CLOUDFLARED="${CLOUDFLARED:-C:/Program Files (x86)/cloudflared/cloudflared.exe}"
TUNNEL="${TUNNEL_NAME:-xpost-dev}"
CF_CONFIG="${CF_CONFIG:-D:/Xpost/bin/cloudflared.yml}"
PORT="${PORT:-8000}"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32mok\033[0m   %s\n' "$*"; }
skip() { printf '    --   %s\n' "$*"; }
fail() { printf '    \033[31mLỖI\033[0m  %s\n' "$*"; }

# Whether a Windows process is running: an executable name, then a fragment of
# its command line.
#
# The name is not decoration. Matching on the command line alone finds this
# very query -- the powershell process carries the pattern it is searching for
# in its own arguments, so every check answered "already running" and the whole
# script became a no-op that then failed its own health checks. -Filter is
# evaluated by WMI before the pipeline sees anything, so powershell.exe is gone
# before the comparison happens.
running() {
    powershell -NoProfile -Command \
        "if (Get-CimInstance Win32_Process -Filter \"Name='$1'\" | Where-Object { \$_.CommandLine -like '*$2*' }) { exit 0 } else { exit 1 }" \
        >/dev/null 2>&1
}

# Detached, so it outlives this shell and the terminal it was started from.
spawn() {
    local exe=$1 args=$2
    powershell -NoProfile -Command \
        "Start-Process -FilePath '$exe' -ArgumentList $args -WorkingDirectory 'D:\\Xpost' -WindowStyle Hidden" \
        >/dev/null 2>&1
}

say "WSL + Docker (MySQL, Redis, Mailpit)"

# Any command starts the distro, which starts Docker, which starts the
# containers. Nothing else here needs the output.
wsl -d "$DISTRO" -- true 2>/dev/null && ok "$DISTRO đang chạy" || { fail "không khởi động được $DISTRO"; exit 1; }

: "${WAIT:=60}"

say "Chờ MySQL và Redis nhận kết nối từ Windows"

# Polled rather than slept through.
#
# The containers report healthy several seconds before Windows can reach them:
# .wslconfig uses networkingMode=mirrored, and the port is not published on the
# Windows loopback the instant the container binds it. A fixed sleep is either
# too short -- and every later step fails with "target machine actively refused
# it" -- or longer than it needs to be, every single time.
for service in "MySQL:3307" "Redis:6380"; do
    name=${service%%:*}
    port=${service##*:}

    for (( i = 0; i < WAIT; i++ )); do
        if timeout 1 bash -c "</dev/tcp/127.0.0.1/$port" 2>/dev/null; then
            ok "$name :$port (sau ${i}s)"
            continue 2
        fi
        sleep 1
    done

    fail "$name :$port không phản hồi sau ${WAIT}s"
    wsl -d "$DISTRO" -- bash -lc "docker ps --format '      {{.Names}}  {{.Status}}'" 2>/dev/null
    exit 1
done

say "Tài nguyên giao diện"

# A hot file behind an https APP_URL is a blank page, every time.
#
# `npm run dev` writes public/hot, and @vite then points the page at
# http://localhost:5173 instead of the built files. Served over the tunnel that
# is mixed content, so the browser refuses every asset without saying so: HTTP
# 200, a full HTML document, and nothing on screen. It is also left behind when
# Vite is killed rather than stopped, so the page stays blank with no dev server
# anywhere to explain it.
#
# Only when APP_URL is https. On localhost the hot file is doing its job.
APP_URL_CHECK=$(grep -E '^APP_URL=' .env | cut -d= -f2-)

if [[ -f public/hot && $APP_URL_CHECK == https://* ]]; then
    rm -f public/hot
    ok "đã xoá public/hot (Vite dev không dùng được sau tunnel https)"
elif [[ -f public/hot ]]; then
    skip "public/hot còn — Vite dev, hợp lệ với APP_URL http"
else
    skip "public/hot sạch"
fi

if [[ ! -f public/build/manifest.json ]]; then
    fail "chưa có public/build/manifest.json — chạy: npm run build"
else
    ok "đã có bản build"
fi

say "Máy chủ web"

if running php.exe "artisan serve"; then
    skip "artisan serve đã chạy"
else
    # APP_ENV is captured from this shell's environment at startup and passed
    # to the child, so a serve started before .env changed keeps serving the
    # old value. Starting it fresh here is what makes that correct.
    spawn "D:\\Xpost\\.tools\\php85\\php.exe" "'artisan','serve','--host=127.0.0.1','--port=$PORT'"
    sleep 3
    ok "artisan serve :$PORT"
fi

say "Cloudflare tunnel"

if running cloudflared.exe "tunnel run"; then
    skip "cloudflared đã chạy"
else
    # --config, not --url: the socket and the app live on different ports, and
    # --url is a single destination that overrides every ingress rule.
    spawn "$CLOUDFLARED" "'tunnel','--config','$CF_CONFIG','run','$TUNNEL'"
    sleep 4
    ok "cloudflared → $TUNNEL"
fi

say "Hàng đợi và lịch"

# Horizon lives in WSL: the workers need Redis on the container network, and
# the supervisor has to outlive the wsl.exe call that starts it.
# The bracket keeps the pattern from matching the wrapper that carries it:
# pgrep -f sees this bash -lc command line too, and '[a]rtisan' as a regex
# matches "artisan" while the literal "[a]rtisan" in the wrapper does not.
if wsl -d "$DISTRO" -- bash -lc "pgrep -f '[a]rtisan horizon' >/dev/null" 2>/dev/null; then
    skip "horizon đã chạy"
else
    wsl -d "$DISTRO" bash -lc "cd /mnt/d/Xpost && bash bin/horizon-start.sh" 2>&1 | tail -2
fi

if running php.exe "schedule:work"; then
    skip "schedule:work đã chạy"
else
    spawn "D:\\Xpost\\.tools\\php85\\php.exe" "'artisan','schedule:work'"
    sleep 2
    ok "schedule:work"
fi

say "Websocket"

# In WSL for the same reason Horizon is: it needs Redis on the container
# network, and the supervisor has to outlive the wsl.exe call that starts it.
#
# A failure here is reported and not fatal, but it is no longer cheap: the
# posts list dropped its five second poll when this became the way status
# arrives, so a stopped Reverb means that column stops moving. The page says so
# rather than freezing quietly -- still, read the line below when it appears.
if wsl -d "$DISTRO" -- bash -lc "pgrep -f '[a]rtisan reverb' >/dev/null" 2>/dev/null; then
    skip "reverb đã chạy"
else
    wsl -d "$DISTRO" bash -lc "cd /mnt/d/Xpost && bash bin/reverb-start.sh" 2>&1 | tail -2
fi

say "Kiểm tra"

check() {
    local label=$1 url=$2 want=$3
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$url")

    if [[ $code == "$want" ]]; then
        ok "$(printf '%-34s %s' "$label" "$code")"
    else
        fail "$(printf '%-34s %s (mong đợi %s)' "$label" "$code" "$want")"
    fi
}

check "http://127.0.0.1:$PORT/up" "http://127.0.0.1:$PORT/up" 200

APP_URL=$(grep -E '^APP_URL=' .env | cut -d= -f2-)

if [[ $APP_URL == https://* ]]; then
    check "$APP_URL/up" "$APP_URL/up" 200
    # 403 is the right answer for a signed-out visitor: Horizon's own gate
    # refusing. A 401 here means Sentinel blocked it instead, which is
    # APP_ENV=local behind a tunnel -- see bin/use-tunnel.sh.
    check "$APP_URL/horizon (khách)" "$APP_URL/horizon" 403
fi

# grep rather than tail: artisan pads its output with blank lines, so `tail -1`
# reports an empty string whether Horizon is up or not.
status=$("$PHP" artisan horizon:status 2>&1 | grep -E 'INFO|ERROR' | head -1)
[[ $status == *running* ]] && ok "${status# }" || fail "${status:-horizon: không trả lời}"

printf '\n\033[1mXong.\033[0m %s\n\n' "${APP_URL:-http://127.0.0.1:$PORT}"
