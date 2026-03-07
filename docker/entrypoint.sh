#!/bin/bash
set -e

# Wait for PostgreSQL to be ready
echo "Waiting for PostgreSQL..."
until PGPASSWORD="$DB_PASSWORD" pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USER:-proxmoxdeploy}" -q 2>/dev/null; do
    sleep 1
done
echo "PostgreSQL is ready."

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

# Start monitoring collector loop in background (10s interval)
(
    while true; do
        . /etc/docker-env.sh
        /usr/local/bin/php /var/www/html/cli/monitoring-collect.php 2>> /var/www/html/data/monitoring.log
        sleep 10
    done
) &

exec "$@"
