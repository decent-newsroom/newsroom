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
#
# Implementation note: dockurr/strfry does not ship jq, so this script uses
# POSIX shell pattern matching against strfry's minified plugin JSON. The
# input shape from strfry is:
#   {"event":{"id":"…","pubkey":"…","kind":<int>, …}, "type":"new", …}
# `"kind":<int>` is numeric (no quotes around the value), so a literal
# `"kind":30023` substring match is unambiguous.
# =============================================================================

while IFS= read -r line; do
    case "$line" in
        *'"kind":30023'*|*'"kind": 30023'*)
            printf '{"action":"accept"}\n'
            ;;
        *'"kind":'*)
            printf '{"action":"reject","msg":"only published longform articles accepted on this relay (kind 30023)"}\n'
            ;;
        *)
            printf '{"action":"reject","msg":"malformed event"}\n'
            ;;
    esac
done




