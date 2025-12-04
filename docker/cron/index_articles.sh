#!/bin/bash
set -e
export PATH="/usr/local/bin:/usr/bin:/bin"

# ========================================
# BACKFILL ONLY - FOR HISTORICAL ARTICLES
# ========================================
# This cron ONLY fetches historical articles for backfill purposes.
# Run once daily or as needed for historical data.
#
# NEW: Post-processing (QA, indexing) now runs via separate cron
# See: docker/cron/post_process_articles.sh (runs every few minutes)
# ========================================

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting article backfill..."

# Backfill: Fetch articles from last week (only needed for historical data)
php /var/www/html/bin/console articles:get -- '-1 week' 'now'

echo "$(date '+%Y-%m-%d %H:%M:%S') - Article backfill completed"

# Note: QA and indexing are handled by post_process_articles.sh (separate cron)
