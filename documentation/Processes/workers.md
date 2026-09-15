# Workers and Messenger Queues

Background work is split across three Docker services. Each supervisor restarts its subprocesses when they exit; long-lived relay and profile work have separate services so they cannot block user-facing fetches.

| Service | Supervisor | Work |
|---|---|---|
| `worker` | `app:run-workers` | Messenger consumers for content, background tasks, expressions, and relay feeds |
| `worker-relay` | `app:run-relay-workers` | Local relay subscriptions for articles, media, magazines, user context, spells, and the configured Essayist relay |
| `worker-profiles` | `app:run-profile-workers` | Profile refresh daemon and `async_profiles` consumer |

The optional `relay-gateway` service owns persistent external relay connections. See [Relay Gateway Service](../Nostr/relay-gateway-service.md).

## Queue routing

`config/packages/messenger.yaml` defines transport streams and message routing. There is no separate `priority` transport: comments and interactive content fetches use `async`.

| Transport | Consumer service | Examples |
|---|---|---|
| `async` | `worker` | Comments, author articles/content, individual event fetches |
| `async_low_priority` | `worker` | Notification fan-out, media discovery, magazine projection, embed prefetch, highlights, gateway persistence, login sync, relay lists |
| `async_expressions` | `worker` | Expression and spell evaluation |
| `async_relay_feeds` | `worker` (three consumers) | Long-running relay feed subscriptions |
| `async_profiles` | `worker-profiles` | Individual/batch profile projection and cache revalidation |

Consumers recycle after one hour and restart under their supervisor. Memory limits in `RunWorkersCommand` are 256 MB for `async`, 192 MB for background and expression consumers, and 128 MB per relay feed consumer. The profile consumer has a 128 MB limit. Redis stream names are environment-prefixed; consumer names include the container hostname to distinguish replicas. Consult the transport configuration before changing stream limits, retry policy, or concurrency.

## Operation

```bash
docker compose up -d worker worker-relay worker-profiles
docker compose logs --tail=100 worker worker-relay worker-profiles
docker compose exec php bin/console messenger:stats
docker compose restart worker worker-relay worker-profiles
```

For a deliberate manual debugging session, stop the corresponding managed service before starting its supervisor in the `php` container. Avoid accidentally running duplicate subscription workers.

`app:run-relay-workers` accepts `--without-articles`, `--without-media`, `--without-magazines`, `--without-user-context`, `--without-spells`, and `--without-essayist`. Its actual commands are `articles:subscribe-local-relay`, `media:subscribe-local-relay`, `magazines:subscribe-local-relay`, `user-context:subscribe-local-relay`, `spells:subscribe-local-relay`, and `essayist:subscribe-relay`. The Essayist subprocess requires `ESSAYIST_RELAY_INTERNAL_URL`.

`app:run-profile-workers` accepts `--profile-interval` and `--profile-batch-size`. Command defaults are 600 seconds and 100 profiles; `compose.yaml` overrides these to 14,400 seconds and 50 profiles. Profile refresh selects stale profiles rather than loading every user. See [Profile Projection](profile-projection.md).

`app:run-workers` no longer accepts profile or subscription options. Configure those on their respective supervisors.

## Scheduled processing

Article QA/indexing also runs through `articles:post-process`; magazine projection consumes `ProjectMagazineMessage`. Cron provides periodic processing and cache maintenance. See [Cron Processing](../Cron/cron-processing.md) for the schedule.

## Troubleshooting

- Check queue routing and the matching service when work stalls; adding an `async` consumer cannot drain `async_profiles` or `async_relay_feeds`.
- Watch logs for repeated DB connection errors and closed EntityManager failures in long-running processes.
- Keep local relay subscription IDs distinct to avoid subscriptions interfering with each other.
- Inspect `src/Command/RunWorkersCommand.php`, `RunRelayWorkersCommand.php`, and `RunProfileWorkersCommand.php` alongside Compose overrides when changing resource limits.
