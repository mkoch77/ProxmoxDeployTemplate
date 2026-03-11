#!/usr/bin/env bash
# =============================================================================
# ProxmoxDeploy — Interactive First-Run Setup
# Generates .env and optionally starts the stack via docker compose.
# =============================================================================
set -euo pipefail

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

# ── Helpers ───────────────────────────────────────────────────────────────────
ask() {
    # ask <var_name> <prompt> [default]
    local var="$1" prompt="$2" default="${3:-}"
    local display_default=""
    [[ -n "$default" ]] && display_default=" ${YELLOW}[${default}]${RESET}"
    while true; do
        printf "${CYAN}${prompt}${RESET}${display_default}: "
        read -r value
        value="${value:-$default}"
        if [[ -n "$value" ]]; then
            printf -v "$var" '%s' "$value"
            return
        fi
        echo -e "${RED}  Pflichtfeld — bitte einen Wert eingeben.${RESET}"
    done
}

ask_optional() {
    # ask_optional <var_name> <prompt> [default]
    local var="$1" prompt="$2" default="${3:-}"
    local display_default=""
    [[ -n "$default" ]] && display_default=" ${YELLOW}[${default}]${RESET}"
    printf "${CYAN}${prompt}${RESET}${display_default}: "
    read -r value
    printf -v "$var" '%s' "${value:-$default}"
}

ask_secret() {
    # ask_secret <var_name> <prompt>
    local var="$1" prompt="$2"
    while true; do
        printf "${CYAN}${prompt}${RESET}: "
        read -rs value
        echo
        if [[ -n "$value" ]]; then
            printf -v "$var" '%s' "$value"
            return
        fi
        echo -e "${RED}  Pflichtfeld — bitte einen Wert eingeben.${RESET}"
    done
}

ask_yn() {
    # ask_yn <prompt> — returns 0 for yes, 1 for no
    local prompt="$1" default="${2:-n}"
    local hint="j/N"
    [[ "$default" == "j" ]] && hint="J/n"
    printf "${CYAN}${prompt}${RESET} ${YELLOW}(${hint})${RESET}: "
    read -r yn
    yn="${yn:-$default}"
    [[ "$yn" =~ ^[jJyY] ]]
}

gen_secret() {
    # generate a 48-char random hex string
    if command -v openssl &>/dev/null; then
        openssl rand -hex 24
    else
        tr -dc 'a-f0-9' < /dev/urandom | head -c 48
    fi
}

header() {
    echo -e "\n${BOLD}${CYAN}══════════════════════════════════════════${RESET}"
    echo -e "${BOLD}  $1${RESET}"
    echo -e "${BOLD}${CYAN}══════════════════════════════════════════${RESET}\n"
}

# ── Guard: .env already exists ────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

if [[ -f "$ENV_FILE" ]]; then
    echo -e "${YELLOW}⚠  .env existiert bereits.${RESET}"
    if ! ask_yn "Überschreiben und neu konfigurieren?"; then
        echo -e "${GREEN}Setup übersprungen. Starte mit bestehender Konfiguration.${RESET}"
        exit 0
    fi
    echo
fi

# ─────────────────────────────────────────────────────────────────────────────
echo -e "\n${BOLD}ProxmoxDeploy — Ersteinrichtung${RESET}"
echo -e "Alle Pflichtfelder sind ${RED}*${RESET} markiert. Leere Eingabe übernimmt den Standardwert.\n"

# ── 1. Proxmox ────────────────────────────────────────────────────────────────
header "1 / 5 · Proxmox Verbindung"

ask        PROXMOX_HOST          "* Proxmox primäre IP / Hostname (z.B. 192.168.1.100)"
ask_optional PROXMOX_PORT        "  Port"                        "8006"
ask_optional PROXMOX_FALLBACK_HOSTS "  Fallback-Hosts (kommagetrennt, leer = keine)" ""

echo
echo -e "  SSL-Zertifikat prüfen?"
echo -e "  ${YELLOW}false${RESET} = selbstsignierte Proxmox-Zertifikate akzeptieren (empfohlen für Heimnetz)"
ask_optional PROXMOX_VERIFY_SSL "  PROXMOX_VERIFY_SSL"           "false"

echo
echo -e "  API-Token anlegen: Datacenter → Permissions → API Tokens"
echo -e "  Format: ${YELLOW}user@realm!tokenname${RESET} z.B. root@pam!deploy"
ask        PROXMOX_TOKEN_ID      "* Token-ID"
ask_secret PROXMOX_TOKEN_SECRET  "* Token-Secret (UUID)"

# ── 2. Datenbank ──────────────────────────────────────────────────────────────
header "2 / 5 · Datenbank (PostgreSQL)"

ask_optional DB_NAME     "  Datenbankname"  "proxmoxdcm"
ask_optional DB_USER     "  Datenbanknutzer" "proxmoxdcm"
echo -e "  Datenbankpasswort wird automatisch generiert. Eigenes eingeben oder Enter für Zufallswert."
printf "${CYAN}  DB_PASSWORD${RESET}: "
read -rs DB_PASSWORD_INPUT
echo
if [[ -z "$DB_PASSWORD_INPUT" ]]; then
    DB_PASSWORD="$(gen_secret | head -c 32)"
    echo -e "${GREEN}  → Zufallspasswort generiert.${RESET}"
else
    DB_PASSWORD="$DB_PASSWORD_INPUT"
fi

# ── 3. Ports & App ────────────────────────────────────────────────────────────
header "3 / 5 · Ports & App-Secret"

ask_optional HTTP_PORT  "  HTTP-Port"   "80"
ask_optional HTTPS_PORT "  HTTPS-Port"  "443"

echo -e "\n  APP_SECRET wird automatisch generiert (sicherer Zufallswert)."
APP_SECRET="$(gen_secret)"
echo -e "${GREEN}  → App-Secret generiert.${RESET}"

# ── 4. SSH ────────────────────────────────────────────────────────────────────
header "4 / 5 · SSH (für Maintenance, Rolling-Updates, Community-Scripts)"
echo -e "  SSH ermöglicht: Terminal, Community-Scripts, Rolling-Updates,"
echo -e "  Cloud-Init Deploy, Custom-Image-Verteilung, Maintenance-Modus."
echo -e "  ${YELLOW}Ohne SSH funktioniert die Proxmox REST API weiterhin vollständig.${RESET}\n"

SSH_ENABLED="true"
SSH_USER="root"
SSH_PORT="22"
SSH_PASSWORD=""

if ask_yn "SSH-Zugang zu Proxmox-Nodes aktivieren?" "j"; then
    SSH_ENABLED="true"
    echo -e "\n  ${YELLOW}Hinweis:${RESET} SSH-Schlüsselpaar wird beim ersten Container-Start automatisch generiert"
    echo -e "  und unter ${YELLOW}data/.ssh/id_ed25519${RESET} gespeichert.\n"

    ask_optional SSH_USER "  SSH-Nutzername auf Proxmox-Nodes" "root"
    ask_optional SSH_PORT "  SSH-Port"                         "22"

    echo -e "\n  SSH-Passwort nur nötig, wenn kein SSH-Key-Deployment gewünscht (leer lassen empfohlen)."
    printf "${CYAN}  SSH_PASSWORD${RESET} ${YELLOW}[leer]${RESET}: "
    read -rs SSH_PASSWORD
    echo
else
    SSH_ENABLED="false"
    echo -e "${GREEN}  → SSH deaktiviert. SSH-abhängige Features werden ausgeblendet.${RESET}"
fi

# ── 5. Optionale Features ─────────────────────────────────────────────────────
header "5 / 6 · Admin-Account"
echo -e "  Dieser Account wird beim ersten Container-Start automatisch angelegt.\n"

ask_optional ADMIN_USER "  Admin-Benutzername" "admin"

while true; do
    printf "${CYAN}* Admin-Passwort${RESET}: "
    read -rs ADMIN_PASSWORD
    echo
    if [[ -z "$ADMIN_PASSWORD" ]]; then
        echo -e "${RED}  Pflichtfeld — bitte ein Passwort eingeben.${RESET}"
        continue
    fi
    printf "${CYAN}* Admin-Passwort bestätigen${RESET}: "
    read -rs ADMIN_PASSWORD_CONFIRM
    echo
    if [[ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]]; then
        break
    fi
    echo -e "${RED}  Passwörter stimmen nicht überein. Bitte erneut eingeben.${RESET}\n"
done
echo -e "${GREEN}  → Admin-Account wird beim ersten Start erstellt.${RESET}"

# Generate SSH keypair for admin user (for VM access via cloud-init)
ADMIN_SSH_PUBKEY=""
echo
if ask_yn "SSH-Keypair für Admin generieren? (wird für VM-Zugang via Cloud-Init benötigt)" "j"; then
    ADMIN_KEY_DIR="$SCRIPT_DIR/data/.ssh"
    ADMIN_KEY_FILE="$ADMIN_KEY_DIR/admin_ed25519"
    mkdir -p "$ADMIN_KEY_DIR"
    if [[ -f "$ADMIN_KEY_FILE" ]]; then
        echo -e "${YELLOW}  SSH-Key existiert bereits: ${ADMIN_KEY_FILE}${RESET}"
        ADMIN_SSH_PUBKEY="$(cat "${ADMIN_KEY_FILE}.pub")"
    else
        ssh-keygen -t ed25519 -f "$ADMIN_KEY_FILE" -N "" -C "${ADMIN_USER}@proxmox-deploy" -q
        chmod 600 "$ADMIN_KEY_FILE"
        chmod 644 "${ADMIN_KEY_FILE}.pub"
        ADMIN_SSH_PUBKEY="$(cat "${ADMIN_KEY_FILE}.pub")"
        echo -e "${GREEN}  → SSH-Keypair generiert:${RESET}"
        echo -e "    Private Key: ${YELLOW}${ADMIN_KEY_FILE}${RESET}"
        echo -e "    Public Key:  ${YELLOW}${ADMIN_KEY_FILE}.pub${RESET}"
    fi
    echo -e "  ${YELLOW}Hinweis:${RESET} Der Private Key wird automatisch im Profil hinterlegt."
    echo -e "  Für SSH-Zugang zu VMs: ${CYAN}ssh -i ${ADMIN_KEY_FILE} <user>@<vm-ip>${RESET}"
fi

# ── 6. Optionale Features ─────────────────────────────────────────────────────
header "6 / 6 · Optionale Features"

# EntraID
ENTRAID_TENANT_ID="" ENTRAID_CLIENT_ID="" ENTRAID_CLIENT_SECRET="" ENTRAID_REDIRECT_URI=""
if ask_yn "Microsoft Entra ID (Azure AD) Login aktivieren?"; then
    echo
    ask        ENTRAID_TENANT_ID     "* Tenant-ID"
    ask        ENTRAID_CLIENT_ID     "* Client-ID"
    ask_secret ENTRAID_CLIENT_SECRET "* Client-Secret"
    ask        ENTRAID_REDIRECT_URI  "* Redirect-URI (z.B. https://meinedomain.de/api/auth-callback.php)"
fi

# Let's Encrypt
DOMAIN="" LETSENCRYPT_EMAIL=""
if ask_yn "Let's Encrypt SSL einrichten?"; then
    echo
    ask DOMAIN            "* Domain (z.B. proxmox.example.com)"
    ask LETSENCRYPT_EMAIL "* E-Mail für Let's Encrypt"
fi

# ── Zusammenfassung ───────────────────────────────────────────────────────────
echo -e "\n${BOLD}${CYAN}══════════════════════════════════════════${RESET}"
echo -e "${BOLD}  Zusammenfassung${RESET}"
echo -e "${BOLD}${CYAN}══════════════════════════════════════════${RESET}"
echo -e "  Proxmox Host  : ${GREEN}${PROXMOX_HOST}:${PROXMOX_PORT}${RESET}"
echo -e "  Token-ID      : ${GREEN}${PROXMOX_TOKEN_ID}${RESET}"
echo -e "  Datenbank     : ${GREEN}${DB_USER}@${DB_NAME}${RESET}"
echo -e "  Admin-Account : ${GREEN}${ADMIN_USER}${RESET}"
echo -e "  Ports         : ${GREEN}HTTP=${HTTP_PORT}  HTTPS=${HTTPS_PORT}${RESET}"
[[ -n "$DOMAIN" ]] && echo -e "  Domain        : ${GREEN}${DOMAIN}${RESET}"
[[ -n "$ENTRAID_TENANT_ID" ]] && echo -e "  Entra ID      : ${GREEN}aktiviert${RESET}"
echo

if ! ask_yn "Konfiguration speichern und .env schreiben?" "j"; then
    echo -e "${YELLOW}Abgebrochen. Keine Datei geschrieben.${RESET}"
    exit 0
fi

# ── .env schreiben ────────────────────────────────────────────────────────────
cat > "$ENV_FILE" <<EOF
# ProxmoxDeploy — generiert von setup.sh am $(date '+%Y-%m-%d %H:%M:%S')
# Dieses File enthält Secrets — niemals in Git einchecken!

# ── PostgreSQL ────────────────────────────────────────────────────────────────
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}

# ── Proxmox API ───────────────────────────────────────────────────────────────
PROXMOX_HOST=${PROXMOX_HOST}
PROXMOX_PORT=${PROXMOX_PORT}
PROXMOX_VERIFY_SSL=${PROXMOX_VERIFY_SSL}
PROXMOX_FALLBACK_HOSTS=${PROXMOX_FALLBACK_HOSTS}
PROXMOX_TOKEN_ID=${PROXMOX_TOKEN_ID}
PROXMOX_TOKEN_SECRET=${PROXMOX_TOKEN_SECRET}

# ── App ───────────────────────────────────────────────────────────────────────
APP_SECRET=${APP_SECRET}
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}

# ── SSH ───────────────────────────────────────────────────────────────────────
SSH_ENABLED=${SSH_ENABLED}
SSH_PORT=${SSH_PORT}
SSH_USER=${SSH_USER}
SSH_KEY_PATH=/var/www/html/data/.ssh/id_ed25519
SSH_PASSWORD=${SSH_PASSWORD}

# ── Let's Encrypt (leer = deaktiviert) ───────────────────────────────────────
DOMAIN=${DOMAIN}
LETSENCRYPT_EMAIL=${LETSENCRYPT_EMAIL}

# ── Admin-Account (einmalig beim ersten Start angelegt, danach ignoriert) ─────
ADMIN_USER=${ADMIN_USER}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
ADMIN_SSH_PUBKEY=${ADMIN_SSH_PUBKEY}

# ── Entra ID / Azure AD (leer = deaktiviert) ─────────────────────────────────
ENTRAID_TENANT_ID=${ENTRAID_TENANT_ID}
ENTRAID_CLIENT_ID=${ENTRAID_CLIENT_ID}
ENTRAID_CLIENT_SECRET=${ENTRAID_CLIENT_SECRET}
ENTRAID_REDIRECT_URI=${ENTRAID_REDIRECT_URI}
EOF

chmod 600 "$ENV_FILE"
echo -e "\n${GREEN}✔  .env wurde geschrieben (chmod 600).${RESET}"

# ── Docker starten? ───────────────────────────────────────────────────────────
echo
if ask_yn "Docker-Stack jetzt starten? (docker compose up --build -d)" "j"; then
    echo
    cd "$SCRIPT_DIR"
    docker compose up --build -d
    echo -e "\n${GREEN}✔  Stack gestartet.${RESET}"
    echo -e "   App erreichbar unter: ${CYAN}http://localhost:${HTTP_PORT}${RESET}"
    echo -e "   Logs: ${YELLOW}docker compose logs -f app${RESET}"
else
    echo -e "\n${YELLOW}Start übersprungen.${RESET} Manuell starten mit:"
    echo -e "   ${CYAN}docker compose up --build -d${RESET}"
fi

echo
