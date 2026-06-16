#!/bin/sh
#
# Safe deployment script for the Decent Newsroom.
#
# This script rebuilds and redeploys only the PHP-related services, leaving
# infrastructure services (strfry, database, Redis) untouched. This minimizes
# downtime and avoids unnecessary service restarts.
#
# Usage:
#   sh scripts/deploy-php.sh
#   OR
#   ./scripts/deploy-php.sh (requires execute permission: chmod +x scripts/deploy-php.sh)
#
# Environment:
#   Requires .env.prod.local to be set up with production configuration.
#   Docker and appropriate credentials must be available.
#

set -eu

# Color output for readability
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

printf "${YELLOW}Starting PHP deployment...${NC}\n"
printf '\n'

# Validate that .env.prod.local exists
if [ ! -f ".env.prod.local" ]; then
    printf "${RED}Error: .env.prod.local not found${NC}\n"
    exit 1
fi

printf "${GREEN}✓ Configuration file found${NC}\n"
printf '\n'

# Function to run docker compose commands
run_compose() {
    docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local "$@"
}

# Step 1: Validate compose configuration
printf "${YELLOW}Step 1: Validating Docker Compose configuration...${NC}\n"
if ! run_compose config > /dev/null 2>&1; then
    printf "${RED}✗ Docker Compose validation failed${NC}\n"
    exit 1
fi
printf "${GREEN}✓ Configuration is valid${NC}\n"
printf '\n'

# Step 2: Build PHP image
printf "${YELLOW}Step 2: Building PHP image...${NC}\n"
if ! run_compose build php; then
    printf "${RED}✗ PHP image build failed${NC}\n"
    exit 1
fi
printf "${GREEN}✓ PHP image built successfully${NC}\n"
printf '\n'

# Step 3: Stop PHP-related services gracefully
printf "${YELLOW}Step 3: Stopping PHP service and workers...${NC}\n"
run_compose stop php worker worker-relay worker-profiles relay-gateway cron || true
printf "${GREEN}✓ Services stopped${NC}\n"
printf '\n'

# Step 4: Redeploy PHP and worker services
printf "${YELLOW}Step 4: Redeploying PHP and worker services...${NC}\n"
if ! run_compose up -d --no-deps --force-recreate \
    php \
    worker \
    worker-relay \
    worker-profiles \
    relay-gateway \
    cron; then
    printf "${RED}✗ Service deployment failed${NC}\n"
    exit 1
fi
printf "${GREEN}✓ Services deployed${NC}\n"
printf '\n'

# Step 5: Wait for PHP to become healthy
printf "${YELLOW}Step 5: Waiting for PHP service to become healthy...${NC}\n"
ATTEMPTS=0
MAX_ATTEMPTS=30
while [ $ATTEMPTS -lt $MAX_ATTEMPTS ]; do
    if run_compose ps php | grep -q "healthy"; then
        printf "${GREEN}✓ PHP service is healthy${NC}\n"
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ $ATTEMPTS -eq $MAX_ATTEMPTS ]; then
        printf "${RED}✗ PHP service failed to become healthy (timeout)${NC}\n"
        printf "${YELLOW}Check logs with:${NC}\n"
        printf "  docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs php\n"
        exit 1
    fi
    printf "  Waiting... (%s/%s)\n" "$ATTEMPTS" "$MAX_ATTEMPTS"
    sleep 2
done
printf '\n'

# Step 6: Verify all services are running
printf "${YELLOW}Step 6: Verifying services...${NC}\n"
printf '\n'
run_compose ps
printf '\n'

# Step 7: Show summary
printf "${GREEN}===== DEPLOYMENT COMPLETE =====${NC}\n"
printf '\n'
printf "Services deployed:\n"
printf "  • php (FrankenPHP + Caddy)\n"
printf "  • worker (Messenger async)\n"
printf "  • worker-relay (Local relay subscriptions)\n"
printf "  • worker-profiles (Profile refresh + async_profiles)\n"
printf "  • relay-gateway (NIP-42 AUTH gateway, if enabled)\n"
printf "  • cron (Scheduled tasks)\n"
printf '\n'
printf "Services NOT restarted (stable infrastructure):\n"
printf "  • database (PostgreSQL)\n"
printf "  • redis (external)\n"
printf "  • strfry (local relay)\n"
printf "  • strfry-essayist (if enabled)\n"
printf "  • essayist-gateway (if enabled)\n"
printf '\n'
printf "${YELLOW}Next steps:${NC}\n"
printf "1. Check application logs:\n"
printf "   docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --tail=50 php\n"
printf "2. Verify worker health:\n"
printf "   docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps\n"
printf "3. Monitor metrics:\n"
printf "   curl http://localhost:2019/metrics (if exposed)\n"
printf '\n'
