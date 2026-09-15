# Runtime Resource Configuration

Current resource settings live in `compose.yaml`, `compose.prod.yaml`, `frankenphp/Caddyfile`, and `frankenphp/conf.d/20-app.prod.ini`. Review those together with deployment overrides before tuning a running instance.

The production PHP configuration uses function-level JIT (`1255`) with a 64 MB JIT buffer and a 512 MB PHP memory limit. Timestamp validation is disabled, so production code changes require the appropriate image rebuild and container restart. A PHP memory limit is a per-process ceiling, not a reservation of that amount at startup.

FrankenPHP worker counts, compression, asset cache headers, and Mercure heartbeat/cleanup behavior are configured separately from Messenger consumers. SSE responses must remain streamable through the proxy; check Mercure reconnects when changing compression or timeout settings. Fingerprinted AssetMapper assets can use long-lived immutable caching.

For background load, the application uses isolated queue consumers and selects stale profiles for refresh. Compose overrides profile refresh to four hours with batches of 50. Login relay sync is throttled and incremental sync messages use a 24-hour window (full sync uses all-time); these controls are implemented in `UserMetadataSyncListener`, `UpdateRelayListHandler`, and the profile refresh worker. See [Workers and Messenger Queues](../Processes/workers.md) and [Cron Processing](../Cron/cron-processing.md).

Measure process CPU, memory, queue depth, request latency, and Mercure reconnects before changing limits. Historical before/after estimates are not deployment benchmarks.
