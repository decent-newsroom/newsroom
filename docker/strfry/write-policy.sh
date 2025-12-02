#!/usr/bin/env bash
# Deny ALL client EVENT writes (reads unaffected; upstream ingest still works)
# Contract: read from stdin (JSON) and output action JSON
cat >/dev/null
printf '%s\n' '{"action":"reject","msg":"read-only relay"}'

