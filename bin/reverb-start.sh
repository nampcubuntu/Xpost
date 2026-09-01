#!/usr/bin/env bash
# Start the websocket server inside WSL.
#
# Same shape as bin/horizon-start.sh, and for the same reason: the quoting for
# `&` and `nohup` gets mangled going through wsl.exe, and what you get is a
# server that appears to start and is gone by the next command.
#
# Bound to 0.0.0.0 rather than 127.0.0.1. Under WSL2 mirrored networking the
# Windows side reaches it either way, but a loopback bind inside the distro is
# not reachable from a browser on the host once mirroring is off.
cd /mnt/d/Xpost || exit 1

PORT="${REVERB_PORT:-8080}"

pkill -f "artisan reverb" 2>/dev/null
sleep 1

nohup php8.5 artisan reverb:start --host=0.0.0.0 --port="$PORT" > /tmp/xpost-reverb.log 2>&1 &
disown

sleep 3

if pgrep -f "[a]rtisan reverb" >/dev/null; then
  echo "  reverb: nghe cổng $PORT"
else
  echo "  reverb: FAILED"
  tail -8 /tmp/xpost-reverb.log
fi
