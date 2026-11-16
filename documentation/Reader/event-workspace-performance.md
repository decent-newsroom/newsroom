# Event workspace performance instrumentation

This opt-in Docker fixture measures a bounded Reading Nook publication TOC
fragment. It is an initial, synthetic baseline, not a production latency budget
or evidence of improvement. Separate runtime instrumentation is disabled by
default and collects only aggregate counters.

## Optional runtime aggregates

Set `EVENT_WORKSPACE_METRICS_ENABLED=true` in the PHP server container's
environment and restart the PHP service using the normal deployment procedure.
Unset it or set it to `false` to disable collection. Enabling this does not
change the workspace expansion flag or response/cache headers.

The application writes one cumulative JSON object to the local file
`var/event-workspace-metrics.json`, outside the public web root. It is created
only after an eligible request finishes. Inspect it locally, for example:

```shell
docker compose exec -T php cat var/event-workspace-metrics.json
```

Restrict access to the application `var` directory to the operator. Counters
are shared only by processes using that same file; separate containers or
replicas with separate filesystems have separate aggregates. No data is sent to
a new service, external sink, or browser. The fixed schema contains only
aggregate numbers: request and response-class counts; attempted DBAL statement
executions; successfully sent `FetchEventFromRelaysMessage` counts; exception
counts; duration, query-count, and fetch-count histograms; and observed
resolution-status counts. There are no route labels, paths, query strings,
identities, payloads, SQL, parameters, relay URLs, timestamps, or per-user
records in the file. The in-memory per-request counters are discarded after
merging. Do not correlate the file with individual request logs.

Eligible main requests are Reading Nook bookmark, follow-pack, TOC,
chapter-preview, discovery, and drive fragments (including exact event opens);
My Content preview; Bookshelf
directory and index TOC fragments; and the shared Nostr preview endpoint.
Counts include DBAL queries and sent event-fetch messages during nested
subrequests. Timing starts after routing and ends at the response event, before
the aggregate write; it excludes network transfer and termination work.
Uncaught requests with a finish event are counted as `no_response`; responses
are grouped by HTTP status class and an exception is counted at most once per
request. Middleware counts attempted query/statement execution, including a
failed execution, but not transaction boundaries. A fetch is counted once
only after Messenger reports a successful transport send, not a failed send or
synchronous handling.

Resolution observations come from the shared target resolver, publication TOC
page, and TOC discovery service. Their fixed labels include available, missing,
unavailable, restricted, invalid, unresolved, loading, limited, failed, and
other. These are **observations**, not distinct targets: a discovery request
can inspect the same occurrence more than once, and TOC pages can also observe
unresolved siblings. Some fragments do not use these resolvers and therefore
produce no resolution observation. HTTP status classes do not infer why a
target was missing or restricted.

All duration histogram buckets are exclusive (the first matching `le_*`
bucket); query and fetch buckets are exclusive as well. They permit approximate
percentiles, not exact request latencies. Capture aggregate snapshots and
compare their differences for an observation period; there are intentionally no
per-request or time-window records. Each request's counters and cumulative
counters are bounded. Writes use a nonblocking file lock and are best-effort:
contention, invalid existing data, or I/O errors can drop samples without
affecting the response. A fixed `Workspace metrics aggregation failed; some
samples may be lost.` warning is sent directly to local standard error, without
exception details or request context. Warnings are limited to one every 60
seconds per long-lived subscriber instance; separate short-lived processes can
each emit a warning. If the logging handler fails, the same fixed warning is
attempted via PHP's error log. Check file permissions, free space and validity
of the aggregate when warnings appear. A partial write can leave an invalid file, in which
case collection stops until an operator handles that file. Disable collection
before rotating or removing this metrics file; never remove application cache
or data as part of a metrics reset. This is diagnostic sampling, not an audit
log or a production performance budget.

## Reproduce the synthetic baseline

Start the existing PHP and PostgreSQL Docker services using the normal local
development setup. The configured `DATABASE_URL` must point to PostgreSQL and
the database role must be allowed to create temporary tables. Run from the
repository root:

```shell
docker compose exec -T -e WORKSPACE_BASELINE=1 -e SYMFONY_DEPRECATIONS_HELPER=weak php bin/phpunit tests/Performance/EventWorkspaceBaselineTest.php
```

If another process is rebuilding the normal test cache, the same fixture can
use the repository's isolated test kernel instead:

```shell
docker compose exec -T -e WORKSPACE_BASELINE=1 -e SYMFONY_DEPRECATIONS_HELPER=weak -e 'KERNEL_CLASS=App\Tests\Fixtures\WorkspaceMetricsKernel' php bin/phpunit tests/Performance/EventWorkspaceBaselineTest.php
```

The weak deprecation setting prevents existing indirect Symfony dependency-injection
XML deprecations during cache warmup from failing this measurement; review
those notices separately when running the normal test suite. The benchmark test
is skipped unless explicitly enabled. It creates a separate connection
with connection-local PostgreSQL temporary tables and synthetic events. The
tables shadow the corresponding application tables; no application rows are
seeded, changed, or deleted. The connection is closed after the run. There is no
real relay request, Redis write, or Messenger transport dispatch.

The fixture has one owned kind-30040 root and 50 ordered occurrences: 20
available nested indexes, 20 missing nested indexes, five available pinned
chapters, and five invalid references. All suppression and deletion tables are
empty; author-suppression, hidden-coordinate, and tombstone queries use real
repositories and the temporary tables. Seven extra synthetic drive/directory
revisions exercise PostgreSQL winner selection and exact pins outside the timed
samples. The strict mute-list service is stubbed
as empty. Each sample clears the Doctrine identity map, calls the real
authorized controller method directly and renders the real Twig fragment.
Five warmups precede 30 samples. This does **not** go through the HTTP server,
router, firewall, browser, or actual Redis, and should not be read as
end-to-end network latency or complete access-policy coverage.

DBAL's test-only logging middleware increments a counter for each executed
statement and immediately discards the SQL and context. Counts exclude fixture
setup, warmups, and the separate resolution/discovery checks. Timing uses
`hrtime` around the controller call and Twig rendering; median is the mean of
the two middle observations and p95 uses nearest rank. One separate page read
counts visible target-resolution outcomes per fragment. A separate discovery
simulation calls the real discovery service twice using predetermined
new-reservation and duplicate-reservation Redis responses and a counting
in-memory Messenger bus. Its dispatch and loading counts are **not** measured
transport or worker throughput, and are not included in the fragment timings.

The `WORKSPACE_BASELINE` output contains only fixed labels, aggregate counts and
timings. No event identities, query text or parameters, rendered fragments, or
per-user data are retained by the instrumentation. Fixture errors are reported
as failures. PHPUnit assertions and unexpected errors propagate normally.
Known DBAL/PDO, Twig, and Symfony configuration, dependency-injection or
filesystem errors report the exception class and a fixed fixture phase, with
setup guidance but without the underlying message or chained exception; those
details can contain SQL, credentials, or rendered data. Unexpected errors may
still contain sensitive diagnostic details: inspect locally and do not share raw
failure output. The fixture itself has no production telemetry or external sink.

## Isolated acceptance fixtures

On Windows, run `tests\Browser\run-workspace-acceptance.ps1` from PowerShell to
orchestrate the Docker browser fixture. It locates the Composer mount on a
running Compose PHP container; alternatively, pass the name of an existing
dependency volume with `-VendorVolume <volume-name>`. It mounts dependencies
read-only and creates its own temporary network, PostgreSQL, Redis, application
cache, and asset volumes. Browser tooling is downloaded in a container without
a repository mount. Synthetic authentication and relay responses do not verify
real Nostr login or relay behavior.

`tests/Service/EventWorkspaceRedisWorkerTest.php` is an opt-in Redis/Messenger
integration fixture. Set `WORKSPACE_REDIS_INTEGRATION=1` and point the PHP test
container at an isolated Redis instance using `REDIS_HOST`, `REDIS_PORT`, and
`REDIS_PASSWORD`. It uses a unique stream and key namespace, with synthetic
relay responses and projection; it is not a live-relay test.

## Recorded local run

On 2026-11-15, the isolated-kernel command above ran with PHP 8.5.6 CLI,
Xdebug 3.5.3 loaded, PostgreSQL 17.10 and PHPUnit 9.6.38 in the existing Docker
services:

| Metric | Result |
|---|---:|
| Warmup / measured samples | 5 / 30 |
| Queries per fragment, min–max | 15–15 |
| Fragment median | 16.485 ms |
| Fragment p95 | 19.018 ms |
| Resolutions per fragment: available / unavailable / invalid / other | 25 / 20 / 5 / 0 |
| Discovery simulation: queued fetches / loading responses | 1 / 2 |

These are warm, direct-controller observations on one local environment and a
tiny unindexed temporary dataset. They are sensitive to load, container and PHP
configuration, and cache state. They do not predict production performance,
profile other workspace surfaces, measure cold or restricted paths, or
establish a performance budget. Repeat the same fixture and document changes to
the environment or workload when comparing runs; use separate, privacy-reviewed
end-to-end measurements before setting production budgets.
