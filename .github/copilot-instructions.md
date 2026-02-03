# WP OAuth Login - Entwicklungsumgebung

## Repository Struktur

```
wp-oauth-login/
├── .github/
│   └── copilot-instructions.md    # Diese Datei - Entwicklungsdokumentation
├── plugin/                         # WordPress Plugin Quellcode
│   ├── wp-oauth-login.php         # Haupt-Plugin-Datei
│   ├── includes/                   # PHP Klassen und Funktionen
│   ├── admin/                      # Admin-Bereich Dateien
│   ├── public/                     # Frontend Dateien
│   ├── assets/                     # CSS, JS, Images
│   └── languages/                  # Übersetzungsdateien
├── docker-compose.yml              # Docker Compose Konfiguration
├── Dockerfile                      # WordPress/PHP Container Build
├── Makefile                        # Hilfreiche Entwicklungs-Kommandos
└── README.md                       # Projekt-Dokumentation
```

## Docker Container

Die Entwicklungsumgebung besteht aus folgenden Containern:

| Container | Name | Port | Beschreibung |
|-----------|------|------|--------------|
| WordPress | wp-oauth-login-wordpress | 8080 | WordPress mit PHP und Apache |
| MariaDB | wp-oauth-login-mariadb | 3306 | Datenbank |
| Mailhog | wp-oauth-login-mailhog | 8025 (Web), 1025 (SMTP) | E-Mail Testing |
| WP-CLI | wp-oauth-login-wpcli | - | WordPress CLI Tool |

## Wichtige URLs

- **WordPress Frontend**: http://localhost:8080
- **WordPress Admin**: http://localhost:8080/wp-admin
- **Mailhog Web UI**: http://localhost:8025
- **Datenbank**: localhost:3306

## PHP Error Log

### Error Log im Container anzeigen

```bash
# Live Error Log verfolgen (tail -f)
docker exec -it wp-oauth-login-wordpress tail -f /var/log/php_errors.log

# Letzte 100 Zeilen anzeigen
docker exec -it wp-oauth-login-wordpress tail -n 100 /var/log/php_errors.log

# Gesamtes Log anzeigen
docker exec -it wp-oauth-login-wordpress cat /var/log/php_errors.log

# Error Log leeren
docker exec -it wp-oauth-login-wordpress truncate -s 0 /var/log/php_errors.log
```

### WordPress Debug Log

WordPress schreibt auch in sein eigenes Debug Log:

```bash
# WordPress Debug Log anzeigen
docker exec -it wp-oauth-login-wordpress cat /var/www/html/wp-content/debug.log

# Live verfolgen
docker exec -it wp-oauth-login-wordpress tail -f /var/www/html/wp-content/debug.log
```

### Apache Error Log

```bash
# Apache Error Log
docker exec -it wp-oauth-login-wordpress tail -f /var/log/apache2/error.log
```

## WP-CLI Befehle

WP-CLI ist sowohl im WordPress-Container als auch als separater Service verfügbar.

### Über den WP-CLI Service (empfohlen)

```bash
# Allgemeine Syntax
docker compose run --rm wpcli <command>

# Beispiele:
docker compose run --rm wpcli plugin list
docker compose run --rm wpcli plugin activate wp-oauth-login
docker compose run --rm wpcli plugin deactivate wp-oauth-login
docker compose run --rm wpcli user list
docker compose run --rm wpcli option get siteurl
docker compose run --rm wpcli cache flush
docker compose run --rm wpcli db check
docker compose run --rm wpcli core version
```

### Direkt im WordPress Container

```bash
# In den Container einsteigen und WP-CLI nutzen
docker exec -it wp-oauth-login-wordpress bash
wp --allow-root plugin list

# Oder direkt von außen
docker exec -it wp-oauth-login-wordpress wp --allow-root plugin list
```

### Häufig verwendete WP-CLI Befehle

```bash
# Plugin Management
docker compose run --rm wpcli plugin list
docker compose run --rm wpcli plugin activate wp-oauth-login
docker compose run --rm wpcli plugin deactivate wp-oauth-login
docker compose run --rm wpcli plugin install <plugin-name> --activate

# User Management
docker compose run --rm wpcli user list
docker compose run --rm wpcli user create testuser test@example.com --role=subscriber

# Database
docker compose run --rm wpcli db export backup.sql
docker compose run --rm wpcli db import backup.sql
docker compose run --rm wpcli db query "SELECT * FROM wp_options LIMIT 10"

# Options
docker compose run --rm wpcli option list
docker compose run --rm wpcli option get wp_oauth_login_options --format=json

# Cache
docker compose run --rm wpcli cache flush
docker compose run --rm wpcli transient delete --all

# Rewrite Rules
docker compose run --rm wpcli rewrite flush

# Cron
docker compose run --rm wpcli cron event list
docker compose run --rm wpcli cron event run --all

# Debugging
docker compose run --rm wpcli eval "var_dump(get_option('wp_oauth_login_options'));"
```

## Makefile Befehle

```bash
make up              # Container starten
make down            # Container stoppen
make restart         # Container neu starten
make logs            # Alle Logs anzeigen
make logs-wp         # WordPress Logs anzeigen
make logs-php        # PHP Error Log anzeigen
make shell           # Shell im WordPress Container öffnen
make wp              # WP-CLI interaktiv nutzen
make db-shell        # MySQL Shell öffnen
make clean           # Alles löschen (Volumes, Container)
```

## E-Mail Testing mit Mailhog

Alle E-Mails, die WordPress versendet, werden von Mailhog abgefangen und können unter http://localhost:8025 eingesehen werden.

### Konfiguration

- SMTP Host: mailhog
- SMTP Port: 1025
- Keine Authentifizierung erforderlich

### Test-E-Mail senden

```bash
# Über WP-CLI
docker compose run --rm wpcli eval "wp_mail('test@example.com', 'Test Subject', 'Test Body');"
```

## Datenbank Zugriff

### Über WP-CLI

```bash
docker compose run --rm wpcli db cli
```

### Direkt mit MySQL Client

```bash
docker exec -it wp-oauth-login-mariadb mysql -u wordpress -pwordpress wordpress
```

### Verbindungsdaten

- **Host**: localhost (oder mariadb innerhalb Docker)
- **Port**: 3306
- **Datenbank**: wordpress
- **Benutzer**: wordpress
- **Passwort**: wordpress
- **Root Passwort**: rootpassword

## Entwicklungs-Workflow

### Erste Einrichtung

1. Container starten: `make up` oder `docker compose up -d`
2. WordPress Installation abschließen: http://localhost:8080
3. Plugin aktivieren: `docker compose run --rm wpcli plugin activate wp-oauth-login`

### Tägliche Entwicklung

1. Container starten (falls nicht laufend): `make up`
2. Code im `plugin/` Verzeichnis bearbeiten
3. Änderungen werden automatisch im Container reflektiert (Volume Mount)
4. Logs bei Bedarf prüfen: `make logs-php`
5. E-Mails in Mailhog prüfen: http://localhost:8025

### Debugging

```php
// Im Plugin-Code für Debug-Ausgaben nutzen:
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Debug message: ' . print_r($variable, true));
}
```

## Plugin Entwicklung Hinweise

### Coding Standards

- WordPress Coding Standards befolgen
- PHP 7.4+ kompatiblen Code schreiben
- Alle Strings mit Text Domain `wp-oauth-login` übersetzen
- Escaping und Sanitization beachten

### Wichtige WordPress Hooks

```php
// Plugin Aktivierung
register_activation_hook(__FILE__, 'activation_function');

// Plugin Deaktivierung
register_deactivation_hook(__FILE__, 'deactivation_function');

// Admin Menü
add_action('admin_menu', 'add_menu_function');

// AJAX Handler
add_action('wp_ajax_my_action', 'ajax_handler');
add_action('wp_ajax_nopriv_my_action', 'ajax_handler'); // Für nicht eingeloggte User

// REST API
add_action('rest_api_init', 'register_routes');

// Login/Logout
add_action('wp_login', 'on_login', 10, 2);
add_action('wp_logout', 'on_logout');
```

### Security Best Practices

```php
// Nonce Verifizierung
wp_nonce_field('wp_oauth_login_action', 'wp_oauth_login_nonce');
check_admin_referer('wp_oauth_login_action', 'wp_oauth_login_nonce');

// Capability Check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

// Sanitization
$clean_input = sanitize_text_field($_POST['input']);

// Escaping
echo esc_html($output);
echo esc_attr($attribute);
echo esc_url($url);
```

## Troubleshooting

### Container starten nicht

```bash
# Logs prüfen
docker compose logs

# Container komplett neu bauen
docker compose down -v
docker compose build --no-cache
docker compose up -d
```

### Plugin wird nicht angezeigt

```bash
# Prüfen ob Verzeichnis korrekt gemountet ist
docker exec -it wp-oauth-login-wordpress ls -la /var/www/html/wp-content/plugins/wp-oauth-login/

# Berechtigungen prüfen
docker exec -it wp-oauth-login-wordpress ls -la /var/www/html/wp-content/plugins/
```

### Datenbank-Verbindungsfehler

```bash
# MariaDB Container Status prüfen
docker compose ps mariadb

# MariaDB Logs prüfen
docker compose logs mariadb
```

### E-Mails kommen nicht an

1. Prüfen ob Mailhog läuft: `docker compose ps mailhog`
2. Mailhog Web UI öffnen: http://localhost:8025
3. SMTP Verbindung testen:
   ```bash
   docker exec -it wp-oauth-login-wordpress bash -c "echo 'Test' | msmtp test@example.com"
   ```
