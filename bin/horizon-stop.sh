#!/usr/bin/env bash
cd /mnt/d/Xpost || exit 1
php8.5 artisan horizon:terminate 2>/dev/null
sleep 2
pkill -f "artisan horizon" 2>/dev/null
echo "  horizon stopped"
