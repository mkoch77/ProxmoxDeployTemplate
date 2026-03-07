#!/bin/bash
set -e

# Auto-generate SSH key pair if not present (persisted in data volume)
KEY_PATH=/var/www/html/data/.ssh/id_ed25519
KEY_DIR=$(dirname "$KEY_PATH")

if [ ! -f "$KEY_PATH" ]; then
    mkdir -p "$KEY_DIR"
    ssh-keygen -t ed25519 -f "$KEY_PATH" -N "" -C "proxmox-deploy" -q
    chmod 700 "$KEY_DIR"
    chmod 600 "$KEY_PATH"
    chmod 644 "${KEY_PATH}.pub"
    # Flag: deploy key to nodes on next cron run
    touch "$KEY_DIR/needs_deploy"
fi

chown -R www-data:www-data "$KEY_DIR"

# Export Docker env vars so cron jobs can read them
printenv | grep -v '^_=' | sed "s/'/'\\\\''/g; s/\([^=]*\)=\(.*\)/export \1='\2'/" > /etc/docker-env.sh

# Start cron daemon in background
cron

exec "$@"
