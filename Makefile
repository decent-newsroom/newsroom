.PHONY: help relay-build relay-up relay-down relay-chat-up relay-chat-down relay-gateway-up relay-gateway-down relay-shell relay-logs relay-stats relay-export relay-import

help: ## Show this help message
	@echo "Available targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

relay-build: ## Build relay containers (first time only, ~10 min)
	docker compose build strfry strfry-chat
	@echo "Relay containers built successfully."

relay-up: ## Start local relay stack (strfry + worker-relay)
	docker compose up -d strfry worker-relay
	@echo "Relay stack started. Check status with: docker compose ps"

relay-down: ## Stop local relay stack (strfry + worker-relay)
	docker compose stop worker-relay strfry
	@echo "Relay stack stopped."

relay-chat-up: ## Start private chat relay service
	docker compose up -d strfry-chat
	@echo "Chat relay started."

relay-chat-down: ## Stop private chat relay service
	docker compose stop strfry-chat
	@echo "Chat relay stopped."

relay-gateway-up: ## Start relay gateway service (gateway profile)
	docker compose --profile gateway up -d relay-gateway
	@echo "Relay gateway started."

relay-gateway-down: ## Stop relay gateway service (gateway profile)
	docker compose --profile gateway stop relay-gateway
	@echo "Relay gateway stopped."

relay-shell: ## Open shell in strfry container
	docker compose exec strfry sh

relay-logs: ## Show relay logs
	docker compose logs -f strfry worker-relay

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

