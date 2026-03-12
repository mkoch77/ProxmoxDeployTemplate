#!/bin/bash
set -e

# Warn if critical env vars are missing (setup.sh was not run)
if [[ -z "${PROXMOX_HOST:-}" || -z "${PROXMOX_TOKEN_SECRET:-}" || -z "${APP_SECRET:-}" ]]; then
    echo "╔══════════════════════════════════════════════════════╗"
    echo "║  FEHLER: Konfiguration unvollständig!                ║"
    echo "║                                                      ║"
    echo "║  Bitte setup.sh auf dem Host ausführen:              ║"
    echo "║    ./setup.sh                                        ║"
    echo "║                                                      ║"
    echo "║  Das Script erzeugt die .env mit allen nötigen       ║"
    echo "║  Einstellungen und startet den Stack automatisch.    ║"
    echo "╚══════════════════════════════════════════════════════╝"
    exit 1
fi

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

# Create initial admin user if ADMIN_USER and ADMIN_PASSWORD are set
if [[ -n "${ADMIN_USER:-}" && -n "${ADMIN_PASSWORD:-}" ]]; then
    echo "Creating admin user '${ADMIN_USER}'..."
    if php /var/www/html/cli/seed-admin.php "${ADMIN_USER}" "${ADMIN_PASSWORD}"; then
        echo "Admin user created."
    else
        echo "Admin user already exists or creation failed — skipping."
    fi
    # Save admin SSH public key to profile if provided
    if [[ -n "${ADMIN_SSH_PUBKEY:-}" ]]; then
        echo "Saving admin SSH public key to profile..."
        php -r "
            require_once '/var/www/html/vendor/autoload.php';
            \$db = App\Database::connection();
            \$stmt = \$db->prepare('UPDATE users SET ssh_public_keys = ? WHERE username = ?');
            \$stmt->execute([trim(\$argv[1]), \$argv[2]]);
            echo (\$stmt->rowCount() > 0) ? 'SSH key saved.' : 'User not found — skipping.';
            echo PHP_EOL;
        " "${ADMIN_SSH_PUBKEY}" "${ADMIN_USER}"
    fi

    # Remove credentials from .env so they are not stored in plaintext
    ENV_FILE=/var/www/html/.env
    if [[ -f "$ENV_FILE" ]]; then
        sed -i '/^ADMIN_USER=/d' "$ENV_FILE"
        sed -i '/^ADMIN_PASSWORD=/d' "$ENV_FILE"
        sed -i '/^ADMIN_SSH_PUBKEY=/d' "$ENV_FILE"
        echo "Admin credentials removed from .env."
    fi
fi

# Export Docker env vars so cron jobs can read them
printenv | grep -v '^_=' | sed "s/'/'\\\\''/g; s/\([^=]*\)=\(.*\)/export \1='\2'/" > /etc/docker-env.sh

# Start cron daemon in background
cron

# Initial SSH key deployment + rotate on startup (in background — needs running services)
if [[ "${SSH_ENABLED:-true}" != "false" && -f "$KEY_PATH" ]]; then
    (
        # Wait briefly for services to be ready
        sleep 10
        . /etc/docker-env.sh
        ENV_FILE=/var/www/html/.env

        # If needs_deploy flag exists, do initial deployment first (uses password auth)
        if [[ -f "$KEY_DIR/needs_deploy" ]]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') Initial SSH key deployment..."
            /usr/local/bin/php /var/www/html/cli/ssh-deploy-key.php

            # If deployment succeeded (flag removed), password is no longer needed
            if [[ ! -f "$KEY_DIR/needs_deploy" && -f "$ENV_FILE" ]]; then
                sed -i 's/^SSH_PASSWORD=.*/SSH_PASSWORD=/' "$ENV_FILE"
                echo "$(date '+%Y-%m-%d %H:%M:%S') SSH_PASSWORD removed from .env (key-based auth active)."
            fi
        fi

        # Rotate key (generate new, deploy, remove old)
        echo "$(date '+%Y-%m-%d %H:%M:%S') Container start — rotating SSH key..."
        /usr/local/bin/php /var/www/html/cli/ssh-rotate-key.php
    ) >> /var/www/html/data/ssh-rotate.log 2>&1 &
fi

# Sync maintenance state from Proxmox (detect nodes already in maintenance)
(
    sleep 5
    . /etc/docker-env.sh
    /usr/local/bin/php /var/www/html/cli/maintenance-sync.php
) >> /var/www/html/data/maintenance-sync.log 2>&1 &

# Start monitoring collector loop in background (10s interval)
(
    while true; do
        . /etc/docker-env.sh
        /usr/local/bin/php /var/www/html/cli/monitoring-collect.php 2>> /var/www/html/data/monitoring.log
        sleep 10
    done
) &

exec "$@"
