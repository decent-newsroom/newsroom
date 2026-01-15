#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# ========================================
# ARTICLE POST-PROCESSING CRON
# ========================================
# Runs every few minutes to process articles that need QA and indexing
# Replaces the old articles:get + qa + index + indexed sequence
# Now only runs post-processing since hydration worker handles ingestion
# ========================================

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting article post-processing..."

# Run post-processing commands (QA, index, mark as indexed)
/usr/local/bin/php /var/www/html/bin/console articles:post-process

echo "$(date '+%Y-%m-%d %H:%M:%S') - Article post-processing completed"

