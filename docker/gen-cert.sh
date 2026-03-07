#!/usr/bin/env bash
# Generates a self-signed TLS certificate for local/internal use.
# For production, replace cert.pem / key.pem with your real certificate.

set -e
CERT_DIR="$(dirname "$0")/nginx/certs"
mkdir -p "$CERT_DIR"

openssl req -x509 -nodes -newkey rsa:4096 \
    -keyout "$CERT_DIR/key.pem" \
    -out    "$CERT_DIR/cert.pem" \
    -days   3650 \
    -subj   "/CN=proxmoxdeploy/O=ProxmoxDeploy" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"

chmod 600 "$CERT_DIR/key.pem"
echo "Certificate written to $CERT_DIR/"
