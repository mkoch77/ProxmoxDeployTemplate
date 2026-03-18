# ProxmoxVE Datacenter Manager

Web-basiertes Management-Dashboard für Proxmox VE Cluster. Verwaltet VMs, Container, Templates, Cloud-Init, SSH-Zugang, Rolling-Updates, Community-Scripts und mehr — alles in einer Oberfläche.

## Features

- **Dashboard** — Cluster-Übersicht mit Node-Status, Ressourcen und Gästen
- **VM/CT Management** — Erstellen, starten, stoppen, migrieren, Snapshots
- **Cloud-Init Deploy** — VMs aus Cloud-Images deployen (Ubuntu, Debian, Rocky, Alma, etc.)
- **Templates** — Eigene und Community-Script Templates verwalten
- **Terminal** — Web-Terminal via SSH zu Proxmox-Nodes
- **Rolling Updates** — Automatische apt-Updates über alle Nodes
- **Maintenance-Modus** — Nodes in Wartung setzen mit automatischer VM-Migration
- **Monitoring** — CPU, RAM, Storage Verlauf mit Diagrammen
- **Benutzerverwaltung** — Rollen & Berechtigungen, optional Microsoft Entra ID (Azure AD)
- **Vault** — AES-256-GCM verschlüsselter Secrets-Store (kein Klartext in .env)

## Voraussetzungen

- Docker + Docker Compose
- Git
- Ein Proxmox VE Cluster mit API-Token

## Installation

### 1. Docker installieren (Ubuntu 24.04)

```bash
# Grundpakete
sudo apt update && sudo apt install -y ca-certificates curl gnupg git

# Docker Repo einrichten
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu noble stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Docker installieren
sudo apt update && sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# User berechtigen
sudo usermod -aG docker $USER
newgrp docker
```

<details>
<summary>Debian 12 (Bookworm)</summary>

```bash
sudo apt update && sudo apt install -y ca-certificates curl gnupg git
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/debian bookworm stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update && sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker $USER
newgrp docker
```

</details>

### 2. ProxmoxVE Datacenter Manager installieren

```bash
git clone https://github.com/mkoch77/ProxmoxDeployTemplate.git /opt/pvedcm
cd /opt/pvedcm
./setup.sh
```

> **Empfohlenes Verzeichnis:** `/opt/pvedcm` — Standard für Server-Applikationen unter Linux.
> Alternativ jedes beliebige Verzeichnis, z.B. `~/pvedcm`.

Das Setup-Script fragt interaktiv ab:
- Proxmox Host + API-Token
- Datenbank-Credentials (Passwort wird automatisch generiert)
- HTTP/HTTPS Ports
- SSH-Zugang zu Proxmox-Nodes (optional)
- Cloud-Init Distributionen
- Admin-Account
- Microsoft Entra ID (optional)
- Let's Encrypt SSL (optional)

Am Ende startet `setup.sh` den Docker-Stack automatisch.

### 3. Zugriff

```
https://<server-ip>
```

Login mit dem im Setup angelegten Admin-Account.

## Architektur

```
┌─────────────┐     ┌──────────────┐     ┌──────────────┐
│   Nginx     │────>│  PHP/Apache  │────>│  PostgreSQL  │
│   (Proxy)   │     │  (App)       │     │  (DB)        │
│   :80/:443  │     │              │     │              │
└─────────────┘     └──────────────┘     └──────────────┘
                           │
                    Docker Secrets
                    ├── encryption_key.txt
                    └── db_password.txt
```

- **Nginx** — Reverse-Proxy, SSL-Terminierung
- **App** — PHP 8.2 + Apache, enthält die gesamte Anwendungslogik
- **DB** — PostgreSQL 16, Daten persistent in Docker Volume
- **Secrets** — Encryption Key und DB-Passwort als Docker Secrets (nicht in .env)
- **Vault** — Alle weiteren Secrets (Proxmox-Token, SSH, EntraID) AES-256-GCM verschlüsselt in der DB

## Verwaltung

```bash
cd /opt/pvedcm

# Logs anzeigen
docker compose logs -f app

# Stack neu starten
docker compose restart

# Update (neuen Code pullen + rebuilden)
git pull
docker compose up --build -d

# SSL-Zertifikat mit Let's Encrypt
docker compose run --rm certbot

# Selbstsigniertes Zertifikat generieren
./docker/gen-cert.sh
```

## Secrets & Sicherheit

| Speicherort | Inhalt |
|---|---|
| `secrets/encryption_key.txt` | Vault Master-Key (Docker Secret) |
| `secrets/db_password.txt` | PostgreSQL Passwort (Docker Secret) |
| `.env` | Nur nicht-geheime Config (DB-Name, Ports) |
| Vault (DB) | Proxmox-Token, SSH, EntraID, App-Secret (AES-256-GCM) |

Secrets verwalten: **Settings > Vault** im Web-UI.

## Proxmox API-Token erstellen

1. Proxmox Web-UI > Datacenter > Permissions > API Tokens
2. User: `root@pam` (oder dedizierter User)
3. Token erstellen, z.B. `deploy`
4. **Privilege Separation** deaktivieren (Token erbt User-Rechte)
5. Token-ID: `root@pam!deploy`, Token-Secret: UUID kopieren

## Lizenz

MIT
