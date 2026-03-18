# ProxmoxVE Datacenter Manager – Docker-Installation (Ubuntu 24.04)

## Voraussetzungen

- Ubuntu 24.04 LTS (frische Installation)
- Root-Zugriff oder ein Benutzer mit `sudo`
- Ein Proxmox VE API-Token (Datacenter → Permissions → API Tokens)
- SSH-Schlüssel auf dem Server, der Zugriff auf die Proxmox-Nodes hat

---

## 1. Docker installieren (Ubuntu 24.04)

```bash
# Abhängigkeiten installieren
apt-get update
apt-get install -y ca-certificates curl

# Docker GPG-Schlüssel und Repository einrichten
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list

# Docker Engine und Compose installieren
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Docker beim Systemstart aktivieren
systemctl enable --now docker
```

---

## 2. Projekt einrichten

```bash
# Installationsverzeichnis erstellen
mkdir -p /opt/pvedcm
cd /opt/pvedcm

# Repository klonen
git clone https://github.com/mkoch77/pvedcm.git .
```

---

## 3. Proxmox API-Token erstellen

Der Datacenter Manager benötigt einen API-Token mit ausreichenden Privilegien. Am einfachsten erstellt man eine eigene Rolle:

```bash
# Auf einem beliebigen Proxmox-Node ausführen:

# 1. Rolle mit allen benötigten Privilegien erstellen
pveum role add DatacenterManager -privs "Sys.Audit,Sys.Modify,VM.Audit,VM.PowerMgmt,VM.Config.Disk,VM.Config.CPU,VM.Config.Memory,VM.Config.Network,VM.Config.Options,VM.Config.CDROM,VM.Config.HWType,VM.Allocate,VM.Clone,VM.Migrate,VM.Snapshot,VM.Console,Datastore.Allocate,Datastore.AllocateSpace,Datastore.Audit"

# 2. API-Token erstellen (WICHTIG: Privilege Separation deaktivieren ODER Rolle zuweisen)
pveum user token add root@pam deploy --privsep 0

# 3. Alternativ mit Privilege Separation (empfohlen für Produktion):
pveum user token add root@pam deploy
pveum aclmod / -token 'root@pam!deploy' -role DatacenterManager
```

> **Token-Secret merken!** Das Secret wird nur einmal angezeigt und wird in Schritt 5 für die `.env`-Datei benötigt.

**Übersicht der Privilegien:**

| Privileg | Wofür |
|---|---|
| `Sys.Audit` | Node-Status, Cluster-Health, Storage-Info |
| `Sys.Modify` | HA-Management, Maintenance Mode |
| `VM.Audit` | VM/CT-Liste und Details |
| `VM.PowerMgmt` | Start, Stop, Shutdown, Reboot |
| `VM.Config.Disk` | Disk-Konfiguration beim Deploy |
| `VM.Config.CPU` | CPU-Konfiguration |
| `VM.Config.Memory` | RAM-Konfiguration |
| `VM.Config.Network` | Netzwerk-Konfiguration |
| `VM.Config.Options` | Allgemeine VM-Optionen (Boot, Agent, etc.) |
| `VM.Config.CDROM` | CD-ROM mounten/unmounten |
| `VM.Config.HWType` | Hardware-Typ (BIOS, Machine, TPM) |
| `VM.Allocate` | VMs erstellen und löschen |
| `VM.Clone` | Templates klonen |
| `VM.Migrate` | Live-Migration |
| `VM.Snapshot` | Snapshots erstellen/löschen |
| `VM.Console` | QEMU Guest Agent, Sendkey, Konsole |
| `Datastore.Allocate` | Disk-Images erstellen |
| `Datastore.AllocateSpace` | Storage-Platz reservieren |
| `Datastore.Audit` | Storage-Inhalt auflisten |

---

## 4. SSH-Schlüssel vorbereiten

Der Server benötigt einen SSH-Schlüssel, mit dem er sich auf den Proxmox-Nodes einloggen kann.

```bash
# Schlüssel generieren falls noch keiner vorhanden
ssh-keygen -t ed25519 -f /root/.ssh/id_rsa -N ""

# Öffentlichen Schlüssel auf alle Proxmox-Nodes kopieren
ssh-copy-id -i /root/.ssh/id_rsa.pub root@192.168.12.75
ssh-copy-id -i /root/.ssh/id_rsa.pub root@192.168.12.78
ssh-copy-id -i /root/.ssh/id_rsa.pub root@192.168.12.79

# Verbindung testen
ssh -i /root/.ssh/id_rsa root@192.168.12.75 "echo ok"
```

---

## 5. Konfiguration erstellen

```bash
cd /opt/pvedcm
cp .env.example .env
```

`.env` bearbeiten:

```bash
nano /opt/pvedcm/.env
```

Inhalt:

```bash
# Proxmox API
PROXMOX_HOST=192.168.12.75
PROXMOX_FALLBACK_HOSTS=192.168.12.78,192.168.12.79
PROXMOX_PORT=8006
PROXMOX_VERIFY_SSL=false
PROXMOX_TOKEN_ID=root@pam!deploy
PROXMOX_TOKEN_SECRET=<dein-token-secret>

# App-Secret (zufällig generieren)
APP_SECRET=$(openssl rand -hex 32)

# SSH
SSH_KEY_PATH=/root/.ssh/id_rsa
SSH_PORT=22
SSH_USER=root

# Ports (Standard: 80 und 443)
HTTP_PORT=80
HTTPS_PORT=443
```

> Für Let's Encrypt zusätzlich `DOMAIN` und `LETSENCRYPT_EMAIL` setzen – siehe Abschnitt 7.

---

## 6. TLS-Zertifikat und Start

### Option A: Selbstsigniertes Zertifikat (intern / ohne öffentliche Domain)

```bash
cd /opt/pvedcm
bash docker/gen-cert.sh
docker compose up -d
```

Die App ist unter **https://\<server-ip\>** erreichbar. Der Browser zeigt eine Zertifikatswarnung – einmalig bestätigen.

---

### Option B: Eigenes Zertifikat (von einer CA ausgestellt)

```bash
mkdir -p /opt/pvedcm/docker/nginx/certs

# Zertifikat und Schlüssel an die richtige Stelle kopieren
cp /etc/ssl/certs/mein-zertifikat.crt /opt/pvedcm/docker/nginx/certs/cert.pem
cp /etc/ssl/private/mein-schluessel.key /opt/pvedcm/docker/nginx/certs/key.pem
chmod 644 /opt/pvedcm/docker/nginx/certs/cert.pem
chmod 600 /opt/pvedcm/docker/nginx/certs/key.pem

docker compose up -d
```

---

### Option C: Let's Encrypt (öffentlich erreichbarer Server mit Domain)

**Voraussetzung:** Port 80 muss vom Internet erreichbar sein und der DNS-Eintrag der Domain muss auf die Server-IP zeigen.

**`.env` ergänzen:**

```bash
DOMAIN=proxmox.example.com
LETSENCRYPT_EMAIL=admin@example.com
```

**Stack mit selbstsigniertem Zertifikat starten** (für den ersten Bootvorgang):

```bash
cd /opt/pvedcm
bash docker/gen-cert.sh
docker compose up -d
```

**Let's Encrypt-Zertifikat ausstellen:**

```bash
bash /opt/pvedcm/docker/letsencrypt-init.sh
```

Das Skript:
- Lässt Certbot das Zertifikat per HTTP-01-Challenge ausstellen
- Kopiert die Zertifikate nach `/opt/pvedcm/docker/nginx/certs/`
- Lädt Nginx automatisch neu

**Automatische Verlängerung** (Cron-Job auf dem Server):

```bash
# Log-Verzeichnis anlegen
mkdir -p /var/log/pvedcm

# Cron-Job einrichten
echo "0 3 * * * root bash /opt/pvedcm/docker/letsencrypt-renew.sh >> /var/log/pvedcm/letsencrypt.log 2>&1" \
  > /etc/cron.d/pvedcm-letsencrypt

chmod 644 /etc/cron.d/pvedcm-letsencrypt
```

---

## 7. Loadbalancer Cron-Job (automatisches Load-Balancing)

```bash
# Log-Verzeichnis anlegen (falls noch nicht vorhanden)
mkdir -p /var/log/pvedcm

# Cron-Job einrichten (alle 5 Minuten)
echo "*/5 * * * * root docker compose -f /opt/pvedcm/docker-compose.yml exec -T app php /var/www/html/cli/loadbalancer-run.php >> /var/log/pvedcm/loadbalancer.log 2>&1" \
  > /etc/cron.d/pvedcm-loadbalancer

chmod 644 /etc/cron.d/pvedcm-loadbalancer
```

---

## 8. Konfigurationsreferenz

| Variable | Pflicht | Beschreibung | Standard |
|----------|---------|-------------|---------|
| `PROXMOX_HOST` | ja | Primärer Proxmox-Node (IP) | – |
| `PROXMOX_FALLBACK_HOSTS` | nein | Kommagetrennte Fallback-IPs | – |
| `PROXMOX_PORT` | nein | API-Port | `8006` |
| `PROXMOX_VERIFY_SSL` | nein | TLS-Zertifikat prüfen | `false` |
| `PROXMOX_TOKEN_ID` | ja | API-Token-ID | – |
| `PROXMOX_TOKEN_SECRET` | ja | API-Token-Secret | – |
| `APP_SECRET` | ja | Zufälliger String für CSRF-Tokens | – |
| `SSH_KEY_PATH` | nein | Pfad zum privaten SSH-Schlüssel auf dem Host | `/root/.ssh/id_rsa` |
| `SSH_PORT` | nein | SSH-Port der Nodes | `22` |
| `SSH_USER` | nein | SSH-Benutzer | `root` |
| `SSH_PASSWORD` | nein | SSH-Passwort (alternativ zum Key) | – |
| `SSH_HOST_<NODE>` | nein | Node-IP-Überschreibung, wenn Name nicht per DNS auflösbar | – |
| `HTTP_PORT` | nein | HTTP-Port auf dem Host | `80` |
| `HTTPS_PORT` | nein | HTTPS-Port auf dem Host | `443` |
| `DOMAIN` | nein | Domain für Let's Encrypt | – |
| `LETSENCRYPT_EMAIL` | nein | E-Mail für Let's Encrypt | – |
| `ENTRAID_TENANT_ID` | nein | Azure AD Tenant-ID | – |
| `ENTRAID_CLIENT_ID` | nein | Azure AD App-Client-ID | – |
| `ENTRAID_CLIENT_SECRET` | nein | Azure AD App-Secret | – |
| `ENTRAID_REDIRECT_URI` | nein | OAuth2-Callback-URL | – |

---

## 9. Daten sichern

```bash
# Backup der SQLite-Datenbank
docker run --rm \
  -v pvedcm_data:/data \
  -v /var/backups/pvedcm:/backup \
  alpine tar czf /backup/db-$(date +%Y%m%d-%H%M).tar.gz -C /data .

# Backup-Verzeichnis anlegen
mkdir -p /var/backups/pvedcm

# Automatisches tägliches Backup per Cron
echo "0 2 * * * root docker run --rm -v pvedcm_data:/data -v /var/backups/pvedcm:/backup alpine tar czf /backup/db-\$(date +\%Y\%m\%d).tar.gz -C /data . && find /var/backups/pvedcm -name '*.tar.gz' -mtime +30 -delete" \
  > /etc/cron.d/pvedcm-backup

chmod 644 /etc/cron.d/pvedcm-backup
```

**Wiederherstellen:**

```bash
docker compose down
docker run --rm \
  -v pvedcm_data:/data \
  -v /var/backups/pvedcm:/backup \
  alpine sh -c "rm -rf /data/* && tar xzf /backup/db-20240101.tar.gz -C /data"
docker compose up -d
```

---

## 10. Update

```bash
cd /opt/pvedcm
git pull
docker compose build --no-cache
docker compose up -d
```

Datenbankmigrationen laufen automatisch beim ersten Request nach dem Update.

---

## 11. Fehlerbehebung

### Logs anzeigen

```bash
# App-Logs
docker compose -f /opt/pvedcm/docker-compose.yml logs -f app

# Nginx-Logs
docker compose -f /opt/pvedcm/docker-compose.yml logs -f nginx

# Alle Container
docker compose -f /opt/pvedcm/docker-compose.yml logs -f
```

### Proxmox-Verbindung testen

```bash
# API direkt testen (Werte aus .env)
source /opt/pvedcm/.env
curl -k "https://${PROXMOX_HOST}:${PROXMOX_PORT:-8006}/api2/json/nodes" \
  -H "Authorization: PVEAPIToken=${PROXMOX_TOKEN_ID}=${PROXMOX_TOKEN_SECRET}"
```

### SSH-Verbindung testen

```bash
# Schlüssel im Container prüfen
docker compose -f /opt/pvedcm/docker-compose.yml exec app ls -la /ssh/

# SSH-Verbindung direkt aus dem Container testen
docker compose -f /opt/pvedcm/docker-compose.yml exec app \
  ssh -i /ssh/id_rsa -o StrictHostKeyChecking=no root@192.168.12.75 "echo ok"
```

### Datenbankfehler

```bash
# Berechtigungen der DB prüfen
docker compose -f /opt/pvedcm/docker-compose.yml exec app ls -la /var/www/html/data/

# Berechtigungen reparieren
docker compose -f /opt/pvedcm/docker-compose.yml exec app \
  chown www-data:www-data /var/www/html/data/app.db
```

### Container startet nicht

```bash
docker compose -f /opt/pvedcm/docker-compose.yml logs app --tail=50
```
