# ProxmoxVE Datacenter Manager – Benutzerhandbuch

## Inhaltsverzeichnis

1. [Überblick](#überblick)
2. [Anmeldung](#anmeldung)
3. [Dashboard](#dashboard)
4. [Vorlagen deployen](#vorlagen-deployen)
5. [Community Scripts](#community-scripts)
6. [Tasks](#tasks)
7. [Cluster Health](#cluster-health)
8. [Wartungsmodus](#wartungsmodus)
9. [Load Balancer](#load-balancer)
10. [Rolling Updates](#rolling-updates)
11. [Benutzerverwaltung](#benutzerverwaltung)
12. [Rollen und Berechtigungen](#rollen-und-berechtigungen)
13. [Design / Theme](#design--theme)

---

## Überblick

ProxmoxVE Datacenter Manager ist ein Web-Frontend für Proxmox VE-Cluster. Es ermöglicht das Verwalten von VMs und Containern, das Deployen von Templates, automatisches Load-Balancing sowie Clusterwartung – alles in einer Oberfläche, ohne direkten Zugriff auf die Proxmox-Weboberfläche.

---

## Anmeldung

### Lokale Anmeldung

Benutzername und Passwort eingeben. Beim ersten Start legt der erste angemeldete Benutzer automatisch ein Admin-Konto an.

### Azure AD / Entra ID (SSO)

Falls konfiguriert, erscheint auf der Login-Seite die Schaltfläche **"Login with Microsoft"**. Nach der Weiterleitung zu Microsoft und erfolgreicher Anmeldung wird der Benutzer automatisch in der App angelegt. Der erste Benutzer erhält die Admin-Rolle.

---

## Dashboard

Das Dashboard zeigt alle VMs und Container des Clusters in einer Tabelle.

### Filteroptionen (Toolbar oben)

| Element | Funktion |
|---------|----------|
| Node-Dropdown | Nur Guests eines bestimmten Nodes anzeigen |
| OS-Dropdown | Nach Betriebssystem filtern |
| Gruppierung | Guests nach Tags oder Betriebssystem gruppiert anzeigen |
| Suchfeld | Live-Suche nach Name oder VMID |

### Spalten der Tabelle

- **VMID** – Eindeutige ID des Guests
- **Name** – Name der VM / des Containers
- **Node** – Aktueller Host-Node
- **Status** – running / stopped
- **CPU** – Aktuelle CPU-Auslastung (nur bei laufenden Guests)
- **RAM** – RAM-Auslastung (nur bei laufenden Guests)
- **IP** – IP-Adresse (wird über den QEMU Guest Agent abgerufen)
- **Aktionen** – Steuerungsbuttons

### VM-Detailansicht

Klick auf eine Zeile öffnet ein Modal mit der vollständigen Konfiguration: Kerne, RAM, Netzwerk, Tags, Boot-Disk und Beschreibung.

### Steuerungsbuttons

| Button | Funktion | Sichtbar wenn |
|--------|----------|---------------|
| Start (grün) | VM/CT starten | Berechtigung `vm.start` |
| Reboot (gelb, Split-Dropdown) | Graceful Reboot / Hard Reboot (Reset) | Berechtigung `vm.reboot` |
| Power Off (gelb, Split-Dropdown) | Graceful Shutdown / Hard Poweroff | Berechtigung `vm.shutdown` oder `vm.stop` |
| Migrate (blau) | VM auf anderen Node migrieren | Berechtigung `vm.migrate` |
| Delete (rot) | VM/CT löschen (nur wenn gestoppt) | Berechtigung `vm.delete` |

> Buttons sind disabled wenn die Aktion im aktuellen Status nicht sinnvoll ist (z. B. Start wenn bereits laufend). Der Tooltip erklärt warum.

---

## Vorlagen deployen

Menüpunkt **Deploy** – erfordert Berechtigung `template.deploy`.

1. **Template auswählen** – Alle verfügbaren Templates (VMs und CTs) aus dem Cluster werden aufgelistet.
2. **Ziel-Node** – Node auswählen, auf dem die neue VM erstellt werden soll.
3. **Name** – Namen für die neue VM eingeben.
4. **VMID** – Wird automatisch vorgeschlagen, kann überschrieben werden.
5. **Storage** – Ziel-Storage für die Disk auswählen.
6. **Netzwerk** – Netzwerk-Bridge auswählen.
7. **Deploy** klicken – Der Clone-Vorgang startet, der Fortschritt wird im Terminal-Output angezeigt.

---

## Community Scripts

Über den Deploy-Dialog können auch **Proxmox Community Scripts** direkt auf einem Node ausgeführt werden. Diese stammen aus dem Repository [community-scripts/ProxmoxVE](https://github.com/community-scripts/ProxmoxVE).

1. Im Deploy-Dialog auf **"Community Script"** wechseln.
2. Skript-Kategorie und Skript auswählen.
3. Ziel-Node wählen.
4. **Installieren** klicken – das Skript wird via SSH auf dem Node ausgeführt, die Ausgabe erscheint live im Terminal.

> Erfordert Berechtigung `template.deploy` sowie eine funktionierende SSH-Verbindung zur Node.

---

## Tasks

Menüpunkt **Tasks** – zeigt alle laufenden und abgeschlossenen Proxmox-Tasks des Clusters.

- **UPID** – Eindeutige Task-ID
- **Node / VMID** – Zugehörige Ressource
- **Typ** – z. B. `qmclone`, `qmmigrate`, `aptupgrade`
- **Status** – running / OK / Fehler
- **Zeitstempel** – Start- und Endzeit

Klick auf eine Task-Zeile öffnet den vollständigen Task-Log.

---

## Cluster Health

Das Warnungs-Icon oben in der Navigationsleiste zeigt aktive Cluster-Probleme an:

| Farbe | Bedeutung |
|-------|-----------|
| Rot | Kritisch: Node offline, Storage > 95 % voll |
| Gelb | Warnung: Storage > 85 % voll |
| Blau | Info: Node im Wartungsmodus |

Klick auf das Icon öffnet ein Modal mit allen aktiven Meldungen.

Der Menüpunkt **Health** zeigt eine detaillierte Übersicht aller Nodes und Storages – erfordert Berechtigung `cluster.health.view`.

---

## Wartungsmodus

Menüpunkt **Maintenance** – erfordert Berechtigung `cluster.maintenance`.

Mit dem Wartungsmodus kann ein Node sicher aus dem Cluster genommen werden:

1. **Wartung aktivieren** – Alle VMs/CTs auf dem Node werden auf andere Nodes migriert. Der Node erscheint in der Health-Übersicht als "in maintenance".
2. **Wartung deaktivieren** – Node kehrt in den normalen Betrieb zurück.

> Während des Wartungsmodus werden keine Loadbalancer-Empfehlungen für diesen Node erzeugt.

---

## Load Balancer

Menüpunkt **Loadbalancing** – Ansicht erfordert `loadbalancer.view`, Konfiguration erfordert `loadbalancer.manage`.

Der Loadbalancer analysiert die CPU- und RAM-Auslastung aller Nodes und empfiehlt Live-Migrationen zum Ausgleich der Last.

### Cluster-Balance-Übersicht

Zeigt den aktuellen Cluster-Zustand:
- Durchschnittlicher Auslastungs-Score
- Standardabweichung (niedrig = gut ausbalanciert)
- Anzahl Online-Nodes
- Letzter Loadbalancer-Lauf

### Node-Auslastung

Balkenanzeige pro Node mit CPU- und RAM-Auslastung sowie dem gewichteten Score.

### Einstellungen

| Einstellung | Beschreibung |
|------------|--------------|
| Aktiviert | Loadbalancer ein- oder ausschalten |
| Automations-Level | Manual / Teilautomatisch / Vollautomatisch |
| CPU-Gewichtung | Anteil CPU am Score (0–100) |
| RAM-Gewichtung | Anteil RAM am Score (0–100) |
| Schwellwert (1–5) | Ab welcher Abweichung vom Durchschnitt migriert wird (1=aggressiv, 5=konservativ) |
| Intervall | Wie oft der Cron-Job automatisch auswertet (Minuten) |
| Max. gleichzeitige Migrationen | Wie viele Migrationen pro Lauf maximal gestartet werden |

### Automations-Level

| Level | Verhalten |
|-------|-----------|
| Manual | Empfehlungen werden angezeigt, müssen manuell bestätigt werden |
| Teilautomatisch | "Migrieren"-Button pro Empfehlung + "Alle anwenden" |
| Vollautomatisch | Empfehlungen werden sofort automatisch ausgeführt |

### Empfehlungen

Tabelle zeigt:
- VM/CT, Typ (VM/CT), Von-Node → Nach-Node
- Grund, Impact-Score (Verbesserung der Cluster-Standardabweichung)
- Status: pending / applied / skipped / error

### Verlauf

Die letzten Loadbalancer-Läufe mit Zeitstempel, Trigger (cron/manual), Anzahl Empfehlungen und ausgeführten Migrationen.

### Cron-Job einrichten

Um den Loadbalancer automatisch auszuführen, folgenden Eintrag in die Crontab des Servers eintragen:
```
*/5 * * * * php /pfad/zu/cli/loadbalancer-run.php >> /var/log/proxmox-loadbalancer.log 2>&1
```

---

## Rolling Updates

Menüpunkt **Updater** – erfordert Berechtigung `cluster.update`.

Führt `apt upgrade` nacheinander auf allen (oder ausgewählten) Nodes durch, um den laufenden Betrieb nicht zu unterbrechen:

1. Nodes auswählen (oder alle).
2. **Update starten** – Nodes werden nacheinander aktualisiert.
3. Fortschritt wird pro Node angezeigt (running / done / error).
4. Terminal-Ausgabe von `apt upgrade` wird live gestreamt.

---

## Benutzerverwaltung

Menüpunkt **Users** – erfordert Berechtigung `users.manage`.

### Benutzer anlegen

1. **"New User"** klicken.
2. Benutzername, Anzeigename und Passwort eingeben.
3. Rolle zuweisen.
4. Speichern.

### Benutzer bearbeiten

Klick auf einen Benutzer ermöglicht:
- Passwort ändern
- Rolle wechseln
- Benutzer aktivieren / deaktivieren
- Individuelle Berechtigungs-Overrides setzen (einzelne Rechte hinzufügen oder entfernen)

### EntraID-Benutzer

Benutzer die sich per Microsoft-SSO anmelden, werden automatisch angelegt. Sie haben kein lokales Passwort. Rollen und Overrides können trotzdem manuell gesetzt werden.

---

## Rollen und Berechtigungen

### Standardrollen

| Rolle | Beschreibung |
|-------|-------------|
| admin | Vollzugriff |
| operator | VM-Verwaltung, Deployment, Loadbalancer-Ansicht |
| viewer | Nur Cluster-Health-Ansicht |

### Berechtigungen

| Berechtigung | Beschreibung | Admin | Operator | Viewer |
|--------------|-------------|-------|----------|--------|
| `vm.start` | VMs/CTs starten | ja | ja | nein |
| `vm.stop` | Hard Poweroff | ja | ja | nein |
| `vm.reboot` | VMs/CTs neu starten | ja | ja | nein |
| `vm.shutdown` | Graceful Shutdown | ja | ja | nein |
| `vm.migrate` | Migration zwischen Nodes | ja | ja | nein |
| `vm.delete` | VMs/CTs löschen | ja | nein | nein |
| `template.deploy` | Templates deployen, Community Scripts | ja | ja | nein |
| `cluster.health.view` | Cluster-Health einsehen | ja | ja | ja |
| `cluster.maintenance` | Wartungsmodus verwalten | ja | nein | nein |
| `cluster.update` | Rolling Updates durchführen | ja | nein | nein |
| `cluster.ha` | HA-Ressourcen verwalten | ja | ja | nein |
| `loadbalancer.view` | Loadbalancer-Empfehlungen einsehen | ja | ja | nein |
| `loadbalancer.manage` | Loadbalancer konfigurieren | ja | nein | nein |
| `users.manage` | Benutzerverwaltung | ja | nein | nein |

Individuelle Benutzer können per **Override** einzelne Berechtigungen über ihre Rolle hinaus erhalten oder verlieren.

---

## Design / Theme

Oben rechts im Profil-Dropdown kann zwischen drei Themes gewechselt werden:

| Theme | Verhalten |
|-------|-----------|
| Auto | Folgt der Systemeinstellung (hell/dunkel) |
| Light | Helles Design |
| Dark | Dunkles Design |

Die Einstellung wird serverseitig gespeichert und gilt geräteübergreifend.
