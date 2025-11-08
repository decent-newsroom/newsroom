.PHONY: help relay-build relay-up relay-down relay-prime relay-ingest-now relay-shell relay-test relay-logs

help: ## Show this help message
	@echo "Available targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

relay-build: ## Build relay containers (first time only, ~10 min)
	docker compose build strfry ingest
	@echo "Relay containers built successfully."

relay-up: ## Start the relay and ingest services
	docker compose up -d strfry ingest
	@echo "Relay services started. Check status with: docker compose ps"

relay-down: ## Stop the relay and ingest services
	docker compose stop strfry ingest
	@echo "Relay services stopped."

relay-prime: ## Run initial backfill (one-time, broader time window)
	bash bin/relay/prime.sh
	@echo "Relay prime/backfill completed."

relay-ingest-now: ## Run ingest manually (useful for testing)
	bash bin/relay/ingest.sh
	@echo "Manual ingest completed."

relay-shell: ## Open shell in strfry container
	docker compose exec strfry sh

relay-test: ## Run PHP smoke test against the relay
	php bin/relay/test-smoke.php

relay-logs: ## Show relay logs
	docker compose logs -f strfry ingest

relay-stats: ## Show relay statistics
	docker compose exec strfry strfry db-stats

relay-export: ## Export relay database (backup)
	@echo "Exporting relay database..."
	docker compose exec strfry strfry export > relay-backup-$(shell date +%Y%m%d-%H%M%S).jsonl
	@echo "Export completed."

relay-import: ## Import events from file (usage: make relay-import FILE=backup.jsonl)
	@if [ -z "$(FILE)" ]; then echo "Error: FILE parameter required. Usage: make relay-import FILE=backup.jsonl"; exit 1; fi
	cat $(FILE) | docker compose exec -T strfry strfry import
	@echo "Import completed."

