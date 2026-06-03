#!/usr/bin/env bash
#
# Safe deployment script for the Decent Newsroom.
#
# This script rebuilds and redeploys only the PHP-related services, leaving
# infrastructure services (strfry, database, Redis) untouched. This minimizes
# downtime and avoids unnecessary service restarts.
#
# Usage:
#   ./scripts/deploy-php.sh
#
# Environment:
#   Requires .env.prod.local to be set up with production configuration.
#   Docker, docker-compose, and appropriate credentials must be available.
#

set -euo pipefail

# Color output for readability
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Starting PHP deployment...${NC}"
echo ""

# Configuration
COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local"
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-newsroom}"

# Validate that .env.prod.local exists
if [[ ! -f ".env.prod.local" ]]; then
    echo -e "${RED}Error: .env.prod.local not found${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Configuration file found${NC}"
echo ""

# Step 1: Validate compose configuration
echo -e "${YELLOW}Step 1: Validating Docker Compose configuration...${NC}"
if ! $COMPOSE config > /dev/null 2>&1; then
    echo -e "${RED}✗ Docker Compose validation failed${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Configuration is valid${NC}"
echo ""

# Step 2: Build PHP image
echo -e "${YELLOW}Step 2: Building PHP image...${NC}"
if ! $COMPOSE build php; then
    echo -e "${RED}✗ PHP image build failed${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHP image built successfully${NC}"
echo ""

# Step 3: Stop PHP-related services gracefully
echo -e "${YELLOW}Step 3: Stopping PHP service and workers...${NC}"
$COMPOSE stop php worker worker-relay worker-profiles relay-gateway cron || true
echo -e "${GREEN}✓ Services stopped${NC}"
echo ""

# Step 4: Redeploy PHP and worker services
echo -e "${YELLOW}Step 4: Redeploying PHP and worker services...${NC}"
if ! $COMPOSE up -d --no-deps --force-recreate \
    php \
    worker \
    worker-relay \
    worker-profiles \
    relay-gateway \
    cron; then
    echo -e "${RED}✗ Service deployment failed${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Services deployed${NC}"
echo ""

# Step 5: Wait for PHP to become healthy
echo -e "${YELLOW}Step 5: Waiting for PHP service to become healthy...${NC}"
ATTEMPTS=0
MAX_ATTEMPTS=30
while [[ $ATTEMPTS -lt $MAX_ATTEMPTS ]]; do
    if $COMPOSE ps php | grep -q "healthy"; then
        echo -e "${GREEN}✓ PHP service is healthy${NC}"
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ $ATTEMPTS -eq $MAX_ATTEMPTS ]]; then
        echo -e "${RED}✗ PHP service failed to become healthy (timeout)${NC}"
        echo -e "${YELLOW}Check logs with: $COMPOSE logs php${NC}"
        exit 1
    fi
    echo "  Waiting... ($ATTEMPTS/$MAX_ATTEMPTS)"
    sleep 2
done
echo ""

# Step 6: Verify all services are running
echo -e "${YELLOW}Step 6: Verifying services...${NC}"
echo ""
$COMPOSE ps
echo ""

# Step 7: Show summary
echo -e "${GREEN}===== DEPLOYMENT COMPLETE =====${NC}"
echo ""
echo "Services deployed:"
echo "  • php (FrankenPHP + Caddy)"
echo "  • worker (Messenger async)"
echo "  • worker-relay (Local relay subscriptions)"
echo "  • worker-profiles (Profile refresh + async_profiles)"
echo "  • relay-gateway (NIP-42 AUTH gateway, if enabled)"
echo "  • cron (Scheduled tasks)"
echo ""
echo "Services NOT restarted (stable infrastructure):"
echo "  • database (PostgreSQL)"
echo "  • redis"
echo "  • strfry (local relay)"
echo "  • strfry-chat"
echo "  • strfry-essayist (if enabled)"
echo "  • essayist-gateway (if enabled)"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Check application logs: $COMPOSE logs --tail=50 php"
echo "2. Verify worker health: $COMPOSE ps"
echo "3. Monitor metrics: curl http://localhost:2019/metrics (if exposed)"
echo ""

