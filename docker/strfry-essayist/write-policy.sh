#!/bin/sh
# =============================================================================
# Essayist relay write policy — kind-only filter
# =============================================================================
#
# Membership enforcement happens at the connection layer in essayist-gateway
# (NIP-42 AUTH + ROLE_ESSAYIST_MEMBER), so this script no longer calls the
# PHP policy API. It exists only as a defence-in-depth layer that rejects
# event kinds the relay is not meant to store.
#
# Allowed kinds:
#   30023 - longform article (NIP-23)
#
# All other kinds are rejected. Identity events (0, 3, 10002) and drafts
# (30024) should be fetched from the author's own relays.
# =============================================================================

while IFS= read -r line; do
    KIND=$(printf '%s' "$line" | jq -r '.event.kind // empty')

    if [ -z "$KIND" ]; then
        printf '{"action":"reject","msg":"malformed event"}\n'
        continue
    fi

    if [ "$KIND" = "30023" ]; then
        printf '{"action":"accept"}\n'
    else
        printf '{"action":"reject","msg":"only published longform articles accepted on this relay (kind 30023)"}\n'
    fi
done




