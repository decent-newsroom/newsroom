#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# Source environment variables (includes APP_ENV, DATABASE_URL, etc.)
[ -f /etc/environment ] && . /etc/environment

# ========================================
# MAGAZINE PROJECTION CRON
# ========================================
# Runs every 10 minutes to project magazine indices from Nostr events
# to Magazine entities for efficient querying and rendering
# ========================================

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting magazine projection..."

# Run the projection command which dispatches messages for all magazine slugs
/usr/local/bin/php /app/bin/console app:project-magazines

echo "$(date '+%Y-%m-%d %H:%M:%S') - Magazine projection dispatched"
