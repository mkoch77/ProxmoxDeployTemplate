#!/usr/bin/env bash
# ============================================================
# Let's Encrypt – Certificate renewal
#
# Run manually or via cron:
#   0 3 * * * bash /opt/pvedcm/docker/letsencrypt-renew.sh >> /var/log/pvedcm/letsencrypt.log 2>&1
# ============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CERT_DIR="$SCRIPT_DIR/nginx/certs"

# Load DOMAIN from .env if not set
if [[ -z "$DOMAIN" ]]; then
    ENV_FILE="$SCRIPT_DIR/../.env"
    if [[ -f "$ENV_FILE" ]]; then
        export $(grep -E '^DOMAIN=' "$ENV_FILE" | xargs)
    fi
fi

if [[ -z "$DOMAIN" ]]; then
    echo "ERROR: DOMAIN is not set. Add DOMAIN=yourdomain.com to .env or export it."
    exit 1
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Renewing certificate for $DOMAIN ..."

# Renew (certbot only replaces the cert if it expires within 30 days)
docker compose --profile letsencrypt run --rm certbot renew

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Copying renewed certificates ..."

docker run --rm \
    -v letsencrypt:/etc/letsencrypt:ro \
    -v "$CERT_DIR":/out \
    alpine sh -c "
        cp /etc/letsencrypt/live/${DOMAIN}/fullchain.pem /out/cert.pem
        cp /etc/letsencrypt/live/${DOMAIN}/privkey.pem   /out/key.pem
        chmod 644 /out/cert.pem
        chmod 600 /out/key.pem
    "

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reloading Nginx ..."
docker compose -f "$SCRIPT_DIR/../docker-compose.yml" exec nginx nginx -s reload

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Renewal complete."
