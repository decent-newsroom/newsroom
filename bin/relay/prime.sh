#!/usr/bin/env bash
set -euo pipefail

echo "[$(date)] Starting relay prime (one-time backfill)..."

# Larger time windows for initial backfill
export DAYS_ARTICLES=${DAYS_ARTICLES:-90}
export DAYS_THREADS=${DAYS_THREADS:-30}

# Use the same ingest logic but with extended time windows
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "${SCRIPT_DIR}/ingest.sh"

