#!/bin/sh
# =============================================================================
# Essayist relay write policy — member-gated passthrough
# =============================================================================
#
# Membership enforcement happens at the connection layer in essayist-gateway
# (NIP-42 AUTH + ROLE_ESSAYIST_MEMBER), so this script acts as a protocol-safe
# passthrough only: echo event id and accept the event.
#
# Implementation notes:
#  - dockurr/strfry does not ship jq, so id extraction uses POSIX shell pattern
#    matching / parameter expansion only.
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

    printf '{"id":"%s","action":"accept"}\n' "$EVENT_ID"
done




