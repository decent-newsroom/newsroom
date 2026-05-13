#!/bin/bash
# =============================================================================
# Essayist relay write policy
# =============================================================================
#
# Accepts EVENT writes only from approved Essayist writers.
# Approval is checked against the Newsroom app API.
#
# Allowed kinds:
#   30023 - longform article (NIP-23)
#
# All other kinds are rejected. Identity events (0, 3, 10002) and drafts
# (30024) should be fetched from the author's own relays.
#
# Environment variables:
#   APP_INTERNAL_URL      - Base URL of the Symfony app inside Docker (default: http://php)
#   ESSAYIST_POLICY_TOKEN - Shared secret for the policy API endpoint
# =============================================================================

APP_URL="${APP_INTERNAL_URL:-http://php}"
POLICY_TOKEN="${ESSAYIST_POLICY_TOKEN:-}"

# In-process approval cache: pubkey -> 1 (approved) or 0 (rejected)
# Bash associative array, valid for the lifetime of this script process.
declare -A APPROVAL_CACHE

check_approved() {
    local pubkey="$1"

    # Return cached result if available
    if [[ -v APPROVAL_CACHE["$pubkey"] ]]; then
        return "${APPROVAL_CACHE[$pubkey]}"
    fi

    local response
    response=$(curl -sf \
        --max-time 3 \
        -H "Authorization: Bearer ${POLICY_TOKEN}" \
        "${APP_URL}/api/internal/essayist/writer/${pubkey}" 2>/dev/null)

    if echo "$response" | jq -e '.approved == true' > /dev/null 2>&1; then
        APPROVAL_CACHE["$pubkey"]=0   # bash: 0 = success/true
        return 0
    else
        APPROVAL_CACHE["$pubkey"]=1   # bash: non-zero = failure/false
        return 1
    fi
}

while IFS= read -r line; do
    PUBKEY=$(printf '%s' "$line" | jq -r '.event.pubkey // empty')
    KIND=$(printf '%s' "$line"   | jq -r '.event.kind  // empty')

    # Malformed input — reject
    if [[ -z "$PUBKEY" || -z "$KIND" ]]; then
        printf '{"action":"reject","msg":"malformed event"}\n'
        continue
    fi

    # Kind filter — longform articles only
    case "$KIND" in
        30023)
            : # allowed — continue to pubkey check
            ;;
        *)
            printf '{"action":"reject","msg":"only published longform articles accepted on this relay (kind 30023)"}\n'
            continue
            ;;
    esac

    # Pubkey check — must be an approved Essayist writer
    if check_approved "$PUBKEY"; then
        printf '{"action":"accept"}\n'
    else
        printf '{"action":"reject","msg":"writer not approved on Essayist — apply at decentnewsroom.com/essayist"}\n'
    fi
done


