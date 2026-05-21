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
# Implementation notes:
#  - dockurr/strfry does not ship jq, so the kind check and id extraction
#    use POSIX shell pattern matching / parameter expansion only.
#  - strfry's plugin protocol REQUIRES every response to echo the event's
#    `id`, otherwise strfry rejects the response with
#    `Plugin error: JSON object key "id" not found` and refuses to ingest
#    the event with a generic `error: internal error` to the client.
#    See https://github.com/hoytech/strfry/blob/master/docs/plugins.md
#  - Input shape from strfry is minified JSON of the form:
#      {"type":"new","event":{"id":"<64hex>","pubkey":"…","kind":<int>,…},…}
#    so `"id":"<64hex>"` is the first such substring in the line.
# =============================================================================

while IFS= read -r line; do
    # Extract the event id with POSIX parameter expansion. `#*"id":"` strips
    # everything up to and including the first `"id":"` token; `%%"*` keeps
    # only the bytes up to the next `"` — i.e. the id value.
    case "$line" in
        *'"id":"'*)
            rest=${line#*'"id":"'}
            EVENT_ID=${rest%%'"'*}
            ;;
        *)
            EVENT_ID=""
            ;;
    esac

    if [ -z "$EVENT_ID" ]; then
        # No id to echo; emit an empty id so strfry can still parse the
        # response and surface our rejection message to the client.
        printf '{"id":"","action":"reject","msg":"malformed event"}\n'
        continue
    fi

    case "$line" in
        *'"kind":30023'*|*'"kind": 30023'*)
            printf '{"id":"%s","action":"accept"}\n' "$EVENT_ID"
            ;;
        *)
            printf '{"id":"%s","action":"reject","msg":"only published longform articles accepted on this relay (kind 30023)"}\n' "$EVENT_ID"
            ;;
    esac
done




