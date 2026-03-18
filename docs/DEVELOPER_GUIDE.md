# ProxmoxVE Datacenter Manager – Entwicklerhandbuch

## Inhaltsverzeichnis

1. [Tech-Stack](#tech-stack)
2. [Projektstruktur](#projektstruktur)
3. [Installation und Konfiguration](#installation-und-konfiguration)
4. [Datenbank und Migrations-System](#datenbank-und-migrations-system)
5. [Backend-Klassen](#backend-klassen)
6. [API-Endpoints](#api-endpoints)
7. [Authentifizierung und Berechtigungen](#authentifizierung-und-berechtigungen)
8. [SSH-Integration](#ssh-integration)
9. [Frontend-Architektur](#frontend-architektur)
10. [Neue Features hinzufügen](#neue-features-hinzufügen)
11. [Sicherheitshinweise](#sicherheitshinweise)

---

## Tech-Stack

| Schicht | Technologie |
|---------|------------|
| Backend | PHP 8.1+, keine Frameworks |
| Datenbank | SQLite 3 (via PDO) |
| SSH | phpseclib 3 |
| Frontend | Vanilla JS (ES2020+), Bootstrap 5, Bootstrap Icons |
| Auth | Session-Cookie (HttpOnly, SameSite=Lax) + optionales Azure AD / Entra ID OAuth2 |

---

## Projektstruktur

```
pvedcm/
├── config/
│   ├── config.php          # Aktive Konfiguration (nicht im Git!)
│   └── config.example.php  # Vorlage mit allen Schlüsseln
├── src/                    # PHP-Klassen (Namespace App\)
│   ├── Auth.php            # Session-Authentifizierung, Permissions
│   ├── Bootstrap.php       # App-Initialisierung (DB, Session, CORS)
│   ├── Config.php          # Konfigurationsloader (.env / config.php)
│   ├── Database.php        # PDO-Singleton für SQLite
│   ├── EntraID.php         # Azure AD / Entra ID OAuth2-Flow
│   ├── Helpers.php         # Hilfsfunktionen (validateNodeName, createAPI)
│   ├── Loadbalancer.php    # Loadbalancer-Engine: Auswertung, Empfehlungen, Migration
│   ├── MaintenanceManager.php # Wartungsmodus-Logik
│   ├── Migrator.php        # DB-Migrations-System
│   ├── ProxmoxAPI.php      # HTTP-Client für Proxmox VE API
│   ├── Request.php         # HTTP-Request-Helfer (CSRF, Body-Parsing)
│   ├── Response.php        # JSON-Response-Helfer
│   ├── Session.php         # CSRF-Token-Verwaltung
│   ├── SSH.php             # SSH-Ausführung via phpseclib
│   └── UserManager.php     # Benutzerverwaltung
├── public/
│   ├── index.php           # Haupt-SPA-Seite (nach Login)
│   ├── login.php           # Login-Seite
│   ├── api/                # REST-API-Endpoints (ein PHP-File pro Ressource)
│   └── assets/
│       ├── css/app.css     # Alle Styles
│       └── js/
│           ├── api.js          # Zentraler API-Client (fetch-Wrapper)
│           ├── app.js          # Router, Theme, Cluster-Health-Checks
│           ├── permissions.js  # Permissions-Objekt (vom Server injiziert)
│           ├── utils.js        # Hilfsfunktionen (formatBytes, escapeHtml, …)
│           └── components/     # Ein JS-Objekt pro Seite/Feature
│               ├── controls.js     # VM-Steuerungsbuttons
│               ├── dashboard.js    # Dashboard-Tabelle, Grupierung, Detail-Modal
│               ├── deploy.js       # Template-Deploy-Dialog
│               ├── health.js       # Cluster-Health-Seite
│               ├── loadbalancer.js # Loadbalancer-Seite
│               ├── maintenance.js  # Wartungsmodus-Seite
│               ├── tasks.js        # Task-Übersicht
│               ├── templates.js    # Template-Auswahl
│               ├── toast.js        # Toast-Benachrichtigungen
│               ├── updater.js      # Rolling-Update-Seite
│               └── users.js        # Benutzerverwaltungs-Seite
├── cli/
│   └── loadbalancer-run.php # Cron-Einstiegspunkt für automatischen Loadbalancer-Lauf
├── vendor/                 # Composer-Abhängigkeiten
└── composer.json
```

---

## Installation und Konfiguration

### Voraussetzungen

- PHP 8.1+ mit Extensions: `pdo_sqlite`, `curl`, `openssl`, `json`
- Composer
- Webserver (Apache / Nginx) mit Rewrite-Support oder PHP built-in server

### Setup

```bash
composer install
cp config/config.example.php config/config.php
# config/config.php bearbeiten
```

### Konfigurationsschlüssel (`config/config.php`)

| Schlüssel | Beschreibung | Beispiel |
|-----------|-------------|---------|
| `PROXMOX_HOST` | Primäre Proxmox-API-Adresse | `192.168.1.100` |
| `PROXMOX_FALLBACK_HOSTS` | Kommagetrennte Fallback-Hosts bei Ausfall | `192.168.1.101,192.168.1.102` |
| `PROXMOX_PORT` | API-Port | `8006` |
| `PROXMOX_VERIFY_SSL` | TLS-Zertifikat prüfen | `false` |
| `PROXMOX_TOKEN_ID` | API-Token-ID | `root@pam!deploy` |
| `PROXMOX_TOKEN_SECRET` | API-Token-Secret | `xxxxxxxx-xxxx-...` |
| `APP_SECRET` | Zufälliger String für CSRF-Tokens | `change-me` |
| `SSH_PORT` | SSH-Port der Nodes | `22` |
| `SSH_USER` | SSH-Benutzer | `root` |
| `SSH_KEY_PATH` | Pfad zum privaten SSH-Schlüssel | `/root/.ssh/id_rsa` |
| `SSH_PASSWORD` | SSH-Passwort (alternativ zum Key) | |
| `SSH_HOST_{NODE}` | Node-spezifische SSH-IP-Überschreibung | `SSH_HOST_PMX1=10.0.0.5` |
| `ENTRAID_TENANT_ID` | Azure AD Tenant-ID (optional) | |
| `ENTRAID_CLIENT_ID` | Azure AD App-Client-ID (optional) | |
| `ENTRAID_CLIENT_SECRET` | Azure AD App-Secret (optional) | |
| `ENTRAID_REDIRECT_URI` | OAuth2-Callback-URL (optional) | |

> Die Konfiguration kann auch über eine `.env`-Datei im Projekt-Root gesetzt werden (gleiche Schlüssel, Format: `KEY=value`). `.env` überschreibt `config.php`.

### SSH-Host-Auflösung

Wenn ein Node-Name (z. B. `pmx1`) nicht per DNS auflösbar ist, wird die IP automatisch über den Proxmox Cluster Status ermittelt. Als Fallback kann `SSH_HOST_PMX1=<ip>` in der Konfiguration gesetzt werden.

---

## Datenbank und Migrations-System

Die App nutzt eine SQLite-Datenbank (Pfad: `storage/app.db`, wird beim ersten Start automatisch angelegt).

### Migrator

`src/Migrator.php` verwaltet inkrementelle Schema-Migrationen. Beim App-Start (`Bootstrap::init()`) wird `Migrator::run()` aufgerufen. Bereits angewendete Versionen werden in der Tabelle `migrations` gespeichert.

**Neue Migration hinzufügen:**

1. In `getMigrations()` die nächste Versionsnummer eintragen.
2. Neue private Methode `migrationXXX()` anlegen, die einen SQL-String zurückgibt.
3. Mehrere SQL-Statements werden durch `;` getrennt – `splitStatements()` teilt sie auf und führt sie einzeln aus.

```php
// Beispiel: Migration 013
private static function migration013(): string
{
    return "
        ALTER TABLE users ADD COLUMN notes TEXT DEFAULT NULL;

        INSERT OR IGNORE INTO permissions (key, description) VALUES
            ('feature.x', 'Neue Berechtigung');
    ";
}
```

> Wichtig: PDO SQLite führt bei `exec()` nur das erste Statement eines Multi-Statement-Strings aus. `splitStatements()` löst dieses Problem – immer nutzen.

### DB-Schema-Übersicht

| Tabelle | Beschreibung |
|---------|-------------|
| `users` | Benutzerkonten (lokal + EntraID) |
| `roles` | Rollen (admin, operator, viewer) |
| `permissions` | Berechtigungsschlüssel |
| `role_permissions` | Zuordnung Rolle → Berechtigungen |
| `user_roles` | Zuordnung Benutzer → Rollen |
| `user_permission_overrides` | Individuelle Overrides pro Benutzer |
| `user_sessions` | Aktive Sessions (Cookie-basiert) |
| `maintenance_nodes` | Nodes im Wartungsmodus + Migrationsstatus |
| `loadbalancer_settings` | Loadbalancer-Konfiguration (Single-Row, id=1) |
| `loadbalancer_runs` | Historie der Loadbalancer-Auswertungsläufe |
| `loadbalancer_recommendations` | Migrations-Empfehlungen pro Lauf |
| `rolling_update_sessions` | Laufende/abgeschlossene Rolling-Update-Sessions |
| `migrations` | Angewendete Migrationsversionen |

---

## Backend-Klassen

### `Config`

Lädt Konfiguration aus `config/config.php` und optional aus `.env`. Lazy-Loading beim ersten `Config::get()`.

```php
Config::get('PROXMOX_HOST');           // Wert abrufen
Config::get('SSH_PORT', 22);           // Mit Default-Wert
```

### `Database`

PDO-Singleton für SQLite. Aktiviert `PRAGMA foreign_keys=ON` und `PRAGMA journal_mode=WAL`.

```php
$db = Database::connection(); // PDO-Instanz
```

### `Auth`

Session-basierte Authentifizierung.

```php
Auth::login($username, $password);     // Lokaler Login
Auth::loginEntraID($tokenData);        // SSO-Login
Auth::check();                         // Aktuellen User aus Cookie laden
Auth::requireAuth();                   // Wirft 401 wenn nicht eingeloggt
Auth::requirePermission('vm.start');   // Wirft 403 wenn Berechtigung fehlt
Auth::logout();                        // Session löschen
```

Sessions laufen nach 24 Stunden ab. Der Session-Cookie ist `HttpOnly`, `SameSite=Lax`, und `Secure` wenn HTTPS erkannt wird.

### `Request`

```php
Request::requireMethod('POST');        // Methode prüfen, sonst 405
Request::validateCsrf();              // CSRF-Token prüfen, sonst 403
Request::jsonBody();                  // JSON-Body parsen
```

### `Response`

```php
Response::success($data);             // {"success":true,"data":...}
Response::error('Nachricht', 400);    // {"error":true,"message":...} + HTTP-Status
```

### `ProxmoxAPI`

HTTP-Client für die Proxmox VE REST API. Nutzt Token-Authentifizierung.

```php
$api = Helpers::createAPI();
$api->getNodes();
$api->getGuests($node, $type);        // $type: 'qemu' | 'lxc'
$api->power($node, $type, $vmid, $action);
$api->migrateGuest($node, $type, $vmid, $targetNode);
$api->cloneTemplate($node, $type, $vmid, $params);
$api->getClusterStatus();
```

Bei einem Verbindungsfehler zum primären Host wird automatisch auf `PROXMOX_FALLBACK_HOSTS` ausgewichen.

### `SSH`

phpseclib-basierter SSH-Client. Versucht zuerst Key-Authentifizierung, dann Passwort.

```php
SSH::exec($host, $command);              // Befehl ausführen, Exception bei Fehler
SSH::execInstall($host, $cmd, $timeout); // Langläufige Befehle, gibt Array zurück
SSH::openInteractiveSession($host);      // PTY-Session für Terminal
SSH::enableNodeMaintenance($nodeName);   // ha-manager Maintenance aktivieren
SSH::disableNodeMaintenance($nodeName);  // ha-manager Maintenance deaktivieren
```

### `Loadbalancer`

Loadbalancer-Engine. Berechnet gewichtete CPU/RAM-Scores pro Node und generiert Migrations-Empfehlungen.

```php
Loadbalancer::getSettings();
Loadbalancer::updateSettings($data);
Loadbalancer::evaluate($api, 'manual');           // Auswertung durchführen
Loadbalancer::applyRecommendation($api, $id);     // Einzelne Empfehlung anwenden
Loadbalancer::applyAllRecommendations($api, $runId);
Loadbalancer::getClusterBalance($api);            // Live-Snapshot
Loadbalancer::getLatestRun();
```

**Score-Berechnung:**
```
score = (cpu_weight × cpu_pct + ram_weight × ram_pct) / (cpu_weight + ram_weight)
```

**Threshold-Mapping:** Stufen 1–5 entsprechen 10–30 % Abweichung vom Cluster-Durchschnitt.

---

## API-Endpoints

Alle Endpoints liegen unter `public/api/`. Sie antworten immer mit JSON.

### Authentifizierung

| Endpoint | Methode | Beschreibung |
|----------|---------|-------------|
| `api/login.php` | POST | Lokaler Login |
| `api/logout.php` | POST | Logout |
| `api/auth.php` | GET | Proxmox-Token-Verbindung prüfen |
| `api/auth-entraid.php` | GET | EntraID OAuth2 starten |
| `api/auth-callback.php` | GET | OAuth2-Callback |
| `api/me.php` | POST | Theme-Einstellung speichern |

### Cluster

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/nodes.php` | GET | (auth) | Node-Liste |
| `api/guests.php` | GET | (auth) | VM/CT-Liste |
| `api/guest-config.php` | GET | (auth) | VM/CT-Konfiguration |
| `api/guest-ips.php` | GET | (auth) | IPs via Guest Agent |
| `api/cluster-health.php` | GET | `cluster.health.view` | Nodes + Storage-Status |
| `api/tasks.php` | GET | (auth) | Task-Liste |
| `api/task-log.php` | GET | (auth) | Task-Log |
| `api/ha.php` | GET/POST | `cluster.ha` | HA-Ressourcen |

### VM-Aktionen

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/power.php` | POST | `vm.start/stop/…` | Start/Stop/Reboot/Reset |
| `api/migrate.php` | POST | `vm.migrate` | Live-Migration |
| `api/delete-guest.php` | DELETE | `vm.delete` | VM/CT löschen |
| `api/clone.php` | POST | `template.deploy` | Template klonen |
| `api/templates.php` | GET | `template.deploy` | Template-Liste |
| `api/next-vmid.php` | GET | `template.deploy` | Nächste freie VMID |
| `api/storages.php` | GET | `template.deploy` | Storage-Liste |
| `api/networks.php` | GET | `template.deploy` | Netzwerk-Liste |
| `api/tags.php` | GET | (auth) | Tag-Liste |

### Community Scripts & Terminal

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/community-install.php` | POST | `template.deploy` | Community-Skript via SSH ausführen |
| `api/terminal-start.php` | POST | `template.deploy` | Terminal-Session starten (Token) |
| `api/terminal-output.php` | GET | (Token) | SSE-Stream der Terminal-Ausgabe |
| `api/terminal-input.php` | POST | (Token) | Tastatureingaben an Terminal senden |

### Wartung & Updates

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/maintenance.php` | GET/POST | `cluster.maintenance` | Wartungsmodus verwalten |
| `api/maintenance-status.php` | GET | (auth) | Wartungsstatus aller Nodes |
| `api/node-update.php` | POST | `cluster.update` | apt upgrade auf einem Node |
| `api/rolling-update.php` | GET/POST | `cluster.update` | Rolling-Update-Session |
| `api/node-info.php` | GET | `cluster.update` | Node-Paketinfos |

### Load Balancer

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/loadbalancer.php` | GET | `loadbalancer.view` | Settings + letzter Run + Balance |
| `api/loadbalancer.php?action=settings` | POST | `loadbalancer.manage` | Einstellungen speichern |
| `api/loadbalancer.php?action=run` | POST | `loadbalancer.manage` | Auswertung manuell starten |
| `api/loadbalancer.php?action=apply` | POST | `loadbalancer.manage` | Einzelne Empfehlung anwenden |
| `api/loadbalancer.php?action=apply-all` | POST | `loadbalancer.manage` | Alle Empfehlungen anwenden |
| `api/loadbalancer-history.php` | GET | `loadbalancer.view` | Lauf-Historie + Empfehlungen |

### Benutzerverwaltung

| Endpoint | Methode | Berechtigung | Beschreibung |
|----------|---------|-------------|-------------|
| `api/users.php` | GET/POST/PUT/DELETE | `users.manage` | CRUD für Benutzer, Rollen, Overrides |

---

## Authentifizierung und Berechtigungen

### Session-Flow

1. Login → `Auth::login()` erzeugt `user_sessions`-Eintrag mit 24h-Ablauf.
2. Cookie `app_session` (64-Zeichen-Hex, HttpOnly) wird gesetzt.
3. Jeder API-Request prüft Cookie via `Auth::check()`.
4. CSRF-Token wird beim Seitenaufbau in einem `<meta>`-Tag gerendert und vom JS-API-Client bei allen POST/DELETE-Requests als `X-CSRF-Token`-Header mitgeschickt.

### Berechtigungsprüfung im Backend

```php
$user = Auth::requirePermission('vm.start');
// Wirft 403 und beendet das Script wenn Berechtigung fehlt
```

### Berechtigungsprüfung im Frontend

```javascript
if (Permissions.has('vm.start')) {
    // Button rendern
}
```

`Permissions` wird in `index.php` als inline JS mit den Berechtigungen des aktuellen Benutzers initialisiert.

### Berechtigungs-Overrides

Benutzer können individuelle Overrides erhalten, die ihre Rollen-Rechte ergänzen oder einschränken:
- `granted = 1`: Berechtigung wird hinzugefügt (unabhängig von Rolle)
- `granted = 0`: Berechtigung wird entzogen (auch wenn Rolle sie hätte)

---

## SSH-Integration

Die SSH-Klasse nutzt **phpseclib 3** für alle SSH-Verbindungen. Auth-Reihenfolge:
1. Private Key (`SSH_KEY_PATH`), optional mit Passphrase (`SSH_PASSWORD`)
2. Passwort-Auth (`SSH_PASSWORD`) als Fallback

**Host-Auflösung für Nodes:**
Node-Namen sind oft nicht per DNS auflösbar. Alle SSH-Aufrufe verwenden daher folgendes Muster:
```php
$envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
$sshHost = Config::get($envKey, '');
if (!$sshHost) {
    $sshHost = $nodeName; // Fallback
    // IP aus Proxmox Cluster Status laden
    foreach ($clusterStatus['data'] as $entry) {
        if ($entry['type'] === 'node' && strtolower($entry['name']) === strtolower($nodeName)) {
            $sshHost = $entry['ip'];
        }
    }
}
```

---

## Frontend-Architektur

Das Frontend ist eine **Single Page Application (SPA)** ohne Framework – nur Vanilla JS und Bootstrap 5.

### Router

`App.setupRouter()` in `app.js` hört auf `hashchange`-Events. Die Route ist der URL-Hash (z. B. `#dashboard`, `#loadbalancing`). Jede Seite ist ein JS-Objekt mit `init()` und optional `destroy()`.

### Seiten-Objekte

Jede Seite (z. B. `Dashboard`, `Loadbalancer`) ist ein Plain-Object mit:

```javascript
const MyPage = {
    _interval: null,

    init() {
        // DOM aufbauen, Daten laden, Timer starten
        this._interval = setInterval(() => this.refresh(), 15000);
    },

    destroy() {
        // Timer stoppen, Event-Listener entfernen
        clearInterval(this._interval);
    },

    async refresh() { /* Daten neu laden */ }
};
```

### API-Client (`api.js`)

Zentraler Fetch-Wrapper mit automatischer CSRF-Token-Einbindung und Toast-Fehleranzeige.

```javascript
API.get('api/nodes.php');
API.post('api/power.php', { node, type, vmid, action });
API.getSilent('api/cluster-health.php'); // Kein Toast bei Fehler
```

### Berechtigungen (`permissions.js`)

Vom Server gerendert:
```javascript
const Permissions = { _perms: ['vm.start', 'vm.stop', ...] };
Permissions.has('vm.start'); // → true/false
```

### Toasts (`toast.js`)

```javascript
Toast.success('VM gestartet');
Toast.error('Verbindungsfehler');
Toast.info('Migration läuft...');
```

### Controls (`controls.js`)

`Controls.renderButtons(guest)` gibt HTML für die Aktionsbuttons einer VM zurück (Start, Reboot-Dropdown, Power-Off-Dropdown, Migrate, Delete). Buttons werden je nach Status und Berechtigungen aktiviert/deaktiviert.

---

## Neue Features hinzufügen

### Neue Seite hinzufügen

1. `public/assets/js/components/myfeature.js` erstellen:
```javascript
const MyFeature = {
    init() { /* ... */ },
    destroy() { /* ... */ }
};
```

2. In `public/index.php` Script-Tag und Sidebar-Link hinzufügen (ggf. mit Berechtigungsprüfung).

3. In `public/assets/js/app.js` in `pages` registrieren:
```javascript
pages: {
    ...
    myfeature: typeof MyFeature !== 'undefined' ? MyFeature : null,
}
```

### Neue Berechtigung hinzufügen

1. Neue Migration in `src/Migrator.php`:
```php
INSERT OR IGNORE INTO permissions (key, description) VALUES ('feature.x', 'Beschreibung');
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r, permissions p
    WHERE r.name = 'admin' AND p.key = 'feature.x';
```

2. Im Backend: `Auth::requirePermission('feature.x')`

3. Im Frontend: `Permissions.has('feature.x')`

### Neuer API-Endpoint

1. `public/api/myendpoint.php` erstellen:
```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use App\Bootstrap; use App\Auth; use App\Response; use App\Request;

Bootstrap::init();
Request::requireMethod('GET');
$user = Auth::requirePermission('feature.x');

try {
    Response::success(['data' => 'example']);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
```

2. In `api.js` eine Methode hinzufügen:
```javascript
getMyData() {
    return this.get('api/myendpoint.php');
},
```

---

## Sicherheitshinweise

- **CSRF**: Alle POST/DELETE-Requests müssen `X-CSRF-Token` mitschicken. Der Token wird pro Session in `$_SESSION` gespeichert und bei Seitenaufruf in einem `<meta>`-Tag gerendert.
- **SQL-Injection**: Alle Datenbankzugriffe nutzen PDO Prepared Statements.
- **XSS**: Alle Benutzerdaten werden im Frontend mit `Utils.escapeHtml()` escaped, bevor sie in innerHTML eingefügt werden.
- **Command-Injection**: SSH-Befehle nutzen `escapeshellarg()` für alle nutzerbereitgestellten Werte.
- **Eingabevalidierung**: Node-Namen werden mit `Helpers::validateNodeName()` validiert. Script-Pfade für Community-Scripts werden gegen ein Regex geprüft (`#^(ct|vm|misc|addon|turnkey|pve)/[a-zA-Z0-9_\-]+\.sh$#`).
- **Session-Sicherheit**: Session-Cookies sind `HttpOnly`, `SameSite=Lax` und `Secure` (bei HTTPS). Sessions laufen nach 24h ab und werden beim Logout sofort gelöscht.
- **Berechtigungen**: Jeder API-Endpoint prüft Authentifizierung und Berechtigung explizit – kein globales Middleware-Konzept, jede Datei ist eigenständig verantwortlich.
