#!/usr/bin/env bash
# Start Horizon inside WSL.
#
# Lives in a file rather than inline in infra.bat because the `&` and quoting
# get mangled going through cmd.exe -> wsl.exe -> bash, and the supervisor then
# silently never starts.
cd /mnt/d/Xpost || exit 1

pkill -f "artisan horizon" 2>/dev/null
sleep 1

# nohup + disown so the supervisor outlives the wsl.exe invocation.
nohup php8.5 artisan horizon > /tmp/xpost-horizon.log 2>&1 &
disown

sleep 6
if pgrep -f "artisan horizon" >/dev/null; then
  echo "  horizon: $(pgrep -c -f 'artisan horizon') processes"
else
  echo "  horizon: FAILED"
  tail -5 /tmp/xpost-horizon.log
fi
