#!/usr/bin/env bash
# ============================================================
# Let's Encrypt – Initial certificate issuance
#
# Usage:
#   bash docker/letsencrypt-init.sh <domain> <email>
#
# Example:
#   bash docker/letsencrypt-init.sh proxmox.example.com admin@example.com
#
# Prerequisites:
#   - Docker Compose stack is running (docker compose up -d)
#   - Port 80 is publicly reachable under <domain>
#   - A self-signed cert already exists (docker/gen-cert.sh ran once)
# ============================================================

set -e

DOMAIN="${1:-${DOMAIN}}"
EMAIL="${2:-${LETSENCRYPT_EMAIL}}"

if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
    echo "Usage: $0 <domain> <email>"
    echo "  or set DOMAIN and LETSENCRYPT_EMAIL in .env"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CERT_DIR="$SCRIPT_DIR/nginx/certs"

echo "==> Requesting Let's Encrypt certificate for $DOMAIN ..."

# Run certbot inside its container (part of the letsencrypt profile)
DOMAIN="$DOMAIN" LETSENCRYPT_EMAIL="$EMAIL" \
    docker compose --profile letsencrypt run --rm certbot

echo "==> Copying certificates into $CERT_DIR ..."

# Extract certs from the named Docker volume into the local certs folder
docker run --rm \
    -v letsencrypt:/etc/letsencrypt:ro \
    -v "$CERT_DIR":/out \
    alpine sh -c "
        cp /etc/letsencrypt/live/${DOMAIN}/fullchain.pem /out/cert.pem
        cp /etc/letsencrypt/live/${DOMAIN}/privkey.pem   /out/key.pem
        chmod 644 /out/cert.pem
        chmod 600 /out/key.pem
    "

echo "==> Reloading Nginx ..."
docker compose exec nginx nginx -s reload

echo ""
echo "Done! Certificate for $DOMAIN is active."
echo "Run 'bash docker/letsencrypt-renew.sh' to renew (or set up a cron job)."
