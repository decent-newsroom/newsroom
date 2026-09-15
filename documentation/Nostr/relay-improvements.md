# Relay Infrastructure Proposals

These are retained design ideas, not implemented behavior or an operational checklist. Current behavior is documented in [Relay Pool Management](../Strfry/relay-pool.md), [Relay Gateway Service](relay-gateway-service.md), and [Relay Discovery](relay-discovery.md).

## Cold relay-list lookups

`UserRelayListService::fromNetwork()` still performs a synchronous profile-relay lookup on a cold miss. Explore health-aware circuit breaking or always dispatching a refresh while returning cache/DB/fallback results. Interactive event lookup already has a separate cache/DB-only API; account for that before adding another layer.

## Gateway structure

The gateway is supplied by the Composer package `decent-newsroom/relay-gateway-bundle`. Further separation of connection lifecycle, query/publish handling, authentication, and maintenance remains a possible refactor. Evaluate the package's current structure rather than using the old host command's line count or extraction plan.

## Relay protocol opportunities

- **NIP-11 completeness:** Better limits, retention and supported-NIP metadata can improve preflight decisions. The host already fetches relay information.
- **NIP-66 coverage:** Expand trusted monitor coverage and kind-aware relay selection. Discovery and health ranking already exist; they do not replace the configured registry or operator trust decisions.
- **NIP-50 search:** Explore relay-backed article search when the target relay advertises support. The host currently uses PostgreSQL or Elasticsearch through its search service.
- **NIP-77 reconciliation:** Investigate event-set reconciliation for media and upstream sync to reduce repeated fetching. Validate support at both endpoints before replacing scheduled sweeps.
- **NIP-86 management:** Explore a standard relay management adapter for operator bans. Application ingestion suppression, relay admission policy, and deletion of stored data remain separate responsibilities.
- **Request-to-vanish support:** Consider pubkey-wide deletion semantics as a distinct future feature. Review the protocol and deletion guarantees before extending NIP-09 handling.

Other earlier relay-list ideas included caching summaries on the User entity and more frequent background refresh. Reassess these against the durable `UserRelayList` projection, publish-time seeding, existing login warming, and health ranking before introducing duplicate caches.
