#!/usr/bin/env bash
# Stop Horizon and start it again on the current code.
#
# Horizon workers are long-lived: they load their classes once at boot and keep
# them, so a change to any PHP a job touches has no effect until they restart.
# That has quietly invalidated several debugging sessions here -- a fix is
# applied, the job runs the old code, and the same error comes back looking
# like the fix did not work.
#
# Rule of thumb: web requests pick up PHP changes immediately, queued jobs do
# not. Change something a job runs, run this.
cd "$(dirname "$0")/.." || exit 1

bash bin/horizon-stop.sh
bash bin/horizon-start.sh
