#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# Source environment variables
[ -f /etc/environment ] && . /etc/environment

# ========================================
# ACTIVE INDEXING SERVICE CRON
# ========================================
# This cron job handles:
# 1. Checking for subscription payments (zap receipts)
# 2. Fetching content for active subscribers
# 3. Managing subscription expirations
# ========================================

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting active indexing service tasks..."

# 1. Check for pending subscription payments
echo "$(date '+%Y-%m-%d %H:%M:%S') - Checking for subscription payments..."
/usr/local/bin/php /app/bin/console active-indexing:check-receipts --since-minutes=30

# 2. Manage subscription expirations (grace periods, role removal)
echo "$(date '+%Y-%m-%d %H:%M:%S') - Managing subscription lifecycle..."
/usr/local/bin/php /app/bin/console active-indexing:manage-subscriptions

echo "$(date '+%Y-%m-%d %H:%M:%S') - Active indexing service tasks completed"
