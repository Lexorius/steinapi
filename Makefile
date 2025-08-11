.PHONY: help build up down restart logs shell clean

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker containers
	docker compose build

up: ## Start containers
	docker compose up -d

down: ## Stop containers
	docker compose down

restart: ## Restart containers
	docker compose restart

logs: ## View container logs
	docker compose logs -f

shell: ## Access web container shell
	docker compose exec web bash

db-shell: ## Access MariaDB shell
	docker compose exec db mariadb -u root -prootpassword divera_stein_sync

clean: ## Clean up containers and volumes
	docker compose down -v

install: ## Initial installation
	@echo "Creating .env file..."
	@cp .env.example .env
	@echo "Building containers..."
	@make build
	@echo "Starting services..."
	@make up
	@echo ""
	@echo "Installation complete!"
	@echo "Dashboard: http://localhost:8080"
	@echo "phpMyAdmin: http://localhost:8081"
	@echo ""
	@echo "Please edit .env file with your API keys"

backup: ## Backup database
	@mkdir -p backups
	docker compose exec db mariadb-dump -u root -prootpassword divera_stein_sync > backups/backup_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "Backup created in backups/ directory"

restore: ## Restore database from latest backup
	@latest_backup=$(ls -t backups/*.sql | head -1); \
	if [ -z "$latest_backup" ]; then \
		echo "No backup found in backups/ directory"; \
	else \
		echo "Restoring from $latest_backup..."; \
		docker compose exec -T db mariadb -u root -prootpassword divera_stein_sync < $latest_backup; \
		echo "Restore complete"; \
	fi
