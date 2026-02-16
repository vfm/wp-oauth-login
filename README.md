# WP OAuth Login

WordPress Plugin für Single Sign-On (SSO) mit OAuth 2.0 / OpenID Connect (OIDC). Ermöglicht die Anmeldung über externe Identity Provider wie Zitadel, Keycloak, Auth0, Azure AD und andere.

## Features

- **OAuth 2.0 / OIDC Integration** - Vollständige Unterstützung für Authorization Code Grant
- **OIDC Discovery** - Automatische Endpoint-Konfiguration via Discovery URL
- **Benutzer-Synchronisation** - Automatische Erstellung und Aktualisierung von WordPress-Benutzern
- **Attribut-Mapping** - Flexible Zuordnung von OAuth Claims zu WordPress-Benutzerdaten
- **Custom Attribute Mapping** - Beliebige Claims zu User Meta Feldern mappen (inkl. Zitadel Metadata Support)
- **Role Mapping** - Automatische Rollenzuweisung basierend auf OAuth Claims
- **Single Logout** - Abmeldung bei WordPress meldet auch beim OIDC Provider ab
- **Auto-Redirect** - Optionale automatische Weiterleitung zum SSO (WordPress Login überspringen)
- **Login-Seiten-Integration** - Optionaler SSO-Button auf der WordPress Login-Seite
- **Claims-Test** - Integrierter Test-Modus zum Anzeigen aller verfügbaren Claims
- **Zitadel-Unterstützung** - Spezielle Unterstützung für Zitadel Metadata (Base64-kodiert)

## Voraussetzungen

- WordPress 6.0+
- PHP 8.3+
- Docker & Docker Compose (für Entwicklung)

## Installation

### Als Plugin

1. Plugin-Ordner nach `wp-content/plugins/wp-oauth-login` kopieren
2. Plugin im WordPress Admin aktivieren
3. Unter **Einstellungen → SSO Konfiguration** einrichten

### Entwicklungsumgebung

```bash
# 1. Container starten
make up

# 2. WordPress automatisch einrichten (optional)
make init

# 3. Oder manuell unter http://localhost:8080 einrichten
```

## Konfiguration

### OAuth Provider einrichten

1. Beim OAuth Provider eine neue Anwendung erstellen
2. Folgende URLs beim Provider eintragen (werden auf der Einstellungsseite angezeigt):
   - **Redirect URI**: `https://example.com/wp-json/wp-oauth-login/v1/callback`
   - **Post Logout Redirect URI**: `https://example.com/` (für Single Logout)
3. Client ID und Client Secret in den Plugin-Einstellungen eintragen

### OIDC Discovery (empfohlen)

1. Discovery URL eintragen (z.B. `https://issuer.zitadel.cloud/.well-known/openid-configuration`)
2. Auf "Endpoints laden" klicken
3. Endpoints werden automatisch ausgefüllt

### Attribut-Mapping

Standardmäßig werden folgende Claims gemappt:
- `sub` → Username
- `email` → E-Mail
- `given_name` → Vorname
- `family_name` → Nachname

### Custom Attribute Mapping

Zusätzliche Claims können zu WordPress User Meta Feldern gemappt werden:
- Claims werden zuerst im Root-Objekt gesucht
- Falls nicht gefunden, wird in Zitadel Metadata gesucht (Base64-dekodiert)

Beispiele:
| WordPress Meta Key | OAuth Claim |
|-------------------|-------------|
| billing_phone | telefon |
| billing_company | company |

### Role Mapping

- Rollen können basierend auf Claim-Werten zugewiesen werden
- Option: Login verweigern wenn keine Rolle gemappt werden kann
- Unterstützt Zitadel Projekt-Rollen (`urn:zitadel:iam:org:project:roles`)

### Auto-Redirect zu SSO

Mit der Option "Automatisch zu SSO weiterleiten" wird die WordPress Login-Seite übersprungen und direkt zum OIDC Provider weitergeleitet.

**Bypass-Parameter:** Um im Fehlerfall trotzdem das normale WordPress Login-Formular anzuzeigen:

```
https://example.com/wp-login.php?wp_login=1
```

Der Parameter `?wp_login=1` unterdrückt die automatische Weiterleitung.

## URLs (Entwicklung)

| Service | URL |
|---------|-----|
| WordPress | http://localhost:8080 |
| WordPress Admin | http://localhost:8080/wp-admin |
| Mailhog | http://localhost:8025 |

## Entwicklung

### Container Befehle

```bash
make up        # Container starten
make down      # Container stoppen
make restart   # Container neu starten
make logs      # Logs anzeigen
make shell     # Shell im WordPress Container
```

### WP-CLI

```bash
make wp CMD="plugin list"
make wp CMD="plugin activate wp-oauth-login"
make wp CMD="user list"
```

### Logs

```bash
make logs-php    # PHP Error Log
make logs-wp     # WordPress/Apache Logs
make logs-debug  # WordPress Debug Log
```

## Plugin Struktur

```
plugin/
├── wp-oauth-login.php          # Haupt-Plugin-Datei
├── includes/
│   ├── Plugin.php              # Plugin-Orchestrator (Singleton)
│   ├── Options.php             # Options-Management
│   ├── OAuthClient.php         # OAuth Flow & Token-Handling
│   ├── UserHandler.php         # Benutzer-Erstellung & Mapping
│   ├── RestApi.php             # REST API Callback-Endpoint
│   ├── Admin/
│   │   ├── SettingsPage.php    # Einstellungsseite
│   │   ├── DashboardWidget.php # Dashboard Claims-Widget
│   │   ├── Assets.php          # CSS/JS Enqueue
│   │   └── UserProfile.php     # Profilseite Custom Attributes
│   └── Frontend/
│       └── LoginButton.php     # Login-Seiten Button
├── assets/
│   ├── css/admin.css           # Admin Styles
│   └── js/admin.js             # Admin JavaScript
└── languages/                  # Übersetzungen
```

## Datenbank

- **Host**: localhost
- **Port**: 3306
- **Database**: wordpress
- **User**: wordpress
- **Password**: wordpress

## E-Mail Testing

Alle E-Mails werden von Mailhog abgefangen und können unter http://localhost:8025 eingesehen werden.

## Lizenz

GPL v2 or later
