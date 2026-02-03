.PHONY: up down restart logs logs-wp logs-php logs-db logs-mail shell db-shell wp clean build help

# Default target
help:
	@echo "WP OAuth Login - Development Environment"
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "Targets:"
	@echo "  up          Start all containers"
	@echo "  down        Stop all containers"
	@echo "  restart     Restart all containers"
	@echo "  build       Rebuild containers"
	@echo "  logs        Show all logs"
	@echo "  logs-wp     Show WordPress/Apache logs"
	@echo "  logs-php    Show PHP error log (live)"
	@echo "  logs-db     Show MariaDB logs"
	@echo "  logs-mail   Show Mailhog logs"
	@echo "  shell       Open shell in WordPress container"
	@echo "  db-shell    Open MySQL shell"
	@echo "  wp          Run WP-CLI command (usage: make wp CMD='plugin list')"
	@echo "  clean       Remove all containers and volumes"
	@echo "  help        Show this help message"

# Start containers
up:
	docker compose up -d
	@echo ""
	@echo "✅ Containers started!"
	@echo ""
	@echo "🌐 WordPress: http://localhost:8080"
	@echo "📧 Mailhog:   http://localhost:8025"
	@echo ""

# Stop containers
down:
	docker compose down

# Restart containers
restart:
	docker compose restart

# Build containers
build:
	docker compose build --no-cache

# Show all logs
logs:
	docker compose logs -f

# Show WordPress logs
logs-wp:
	docker compose logs -f wordpress

# Show PHP error log
logs-php:
	docker exec -it wp-oauth-login-wordpress tail -f /var/log/php_errors.log

# Show WordPress debug log
logs-debug:
	docker exec -it wp-oauth-login-wordpress tail -f /var/www/html/wp-content/debug.log 2>/dev/null || echo "Debug log does not exist yet"

# Show MariaDB logs
logs-db:
	docker compose logs -f mariadb

# Show Mailhog logs
logs-mail:
	docker compose logs -f mailhog

# Open shell in WordPress container
shell:
	docker exec -it wp-oauth-login-wordpress bash

# Open MySQL shell
db-shell:
	docker exec -it wp-oauth-login-mariadb mysql -u wordpress -pwordpress wordpress

# Run WP-CLI command
# Usage: make wp CMD="plugin list"
wp:
ifdef CMD
	docker compose run --rm wpcli $(CMD)
else
	@echo "Usage: make wp CMD='<wp-cli command>'"
	@echo "Example: make wp CMD='plugin list'"
endif

# Clean everything
clean:
	docker compose down -v --remove-orphans
	@echo "✅ All containers and volumes removed"

# Initialize WordPress (first time setup helper)
init:
	@echo "Waiting for WordPress to be ready..."
	@sleep 10
	docker compose run --rm wpcli core install \
		--url=http://localhost:8080 \
		--title="WP OAuth Login Dev" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
	docker compose run --rm wpcli plugin activate wp-oauth-login
	@echo ""
	@echo "✅ WordPress initialized!"
	@echo ""
	@echo "🔑 Admin Login:"
	@echo "   URL:      http://localhost:8080/wp-admin"
	@echo "   Username: admin"
	@echo "   Password: admin"
	@echo ""
