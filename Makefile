.PHONY: help relay-build relay-up relay-down relay-prime relay-ingest-now relay-shell relay-test relay-logs

# Strfry is managed via its own compose file (compose.strfry.yaml)
STRFRY_COMPOSE = docker compose -f compose.strfry.yaml

help: ## Show this help message
	@echo "Available targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

relay-build: ## Build/pull relay containers
	$(STRFRY_COMPOSE) pull
	@echo "Relay containers pulled successfully."

relay-up: ## Start the relay service
	$(STRFRY_COMPOSE) up -d
	@echo "Relay services started. Check status with: $(STRFRY_COMPOSE) ps"

relay-down: ## Stop the relay service
	$(STRFRY_COMPOSE) stop strfry
	@echo "Relay services stopped."

relay-prime: ## Run initial backfill (one-time, broader time window)
	bash bin/relay/prime.sh
	@echo "Relay prime/backfill completed."

relay-ingest-now: ## Run ingest manually (useful for testing)
	bash bin/relay/ingest.sh
	@echo "Manual ingest completed."

relay-shell: ## Open shell in strfry container
	$(STRFRY_COMPOSE) exec strfry sh

relay-test: ## Run PHP smoke test against the relay
	php bin/relay/test-smoke.php

relay-logs: ## Show relay logs
	$(STRFRY_COMPOSE) logs -f strfry

relay-stats: ## Show relay statistics
	$(STRFRY_COMPOSE) exec strfry strfry db-stats

relay-export: ## Export relay database (backup)
	@echo "Exporting relay database..."
	$(STRFRY_COMPOSE) exec strfry strfry export > relay-backup-$(shell date +%Y%m%d-%H%M%S).jsonl
	@echo "Export completed."

relay-import: ## Import events from file (usage: make relay-import FILE=backup.jsonl)
	@if [ -z "$(FILE)" ]; then echo "Error: FILE parameter required. Usage: make relay-import FILE=backup.jsonl"; exit 1; fi
	cat $(FILE) | $(STRFRY_COMPOSE) exec -T strfry strfry import
	@echo "Import completed."

