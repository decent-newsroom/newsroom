# User-Scoped Direct Relay AUTH

Direct relay requests no longer generate ephemeral NIP-42 credentials.

When a relay sends an `AUTH` challenge, the request retains the initiating
user's hexadecimal pubkey and asks SigningBundle's `RelayAuthSignerInterface`
for a kind `22242` signature. SigningBundle uses the user's paired NIP-46
remote signer session and validates the returned event before it is sent.

The signature attempt is bounded by the direct request timeout. A request is
dropped for that relay when it has no initiating user, that user has no relay
AUTH signing capability, or the signature cannot be obtained before the
timeout. Long-lived anonymous worker subscriptions therefore remain
unauthenticated and are dropped if a relay requires NIP-42 AUTH.

Browser-extension (NIP-07) fallback remains a relay-gateway responsibility.
It requires a browser/Mercure round trip and is not safe to block a direct
server-side WebSocket request on.
