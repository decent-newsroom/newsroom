# Mercury Bookshelf

The Mercury Bookshelf is a public, read-only book catalogue at `/bookshelf`. It searches the remote Mercury REST API for NKBIP-01 publication indexes (kind `30040`) and fetches their ordered publication content events (kind `30041`) on demand. It does not discover books through the application's relay pool or import them into the local database.

## Overview

The feature gives readers a predictable place to find and read books that Mercury has already indexed:

- Search publication metadata by title, author, source, or `d` tag.
- Exclude kind `30040` results that do not directly reference kind `30041` chapters.
- Deduplicate replaceable index revisions by `30040:<pubkey>:<d>`.
- Fetch chapter events from Mercury when the reader opens a book.
- Preserve the chapter order declared by the index event's `a` tags.
- Show a plain unavailable state when Mercury cannot provide the book.

The Bookshelf is intentionally isolated from the local Newsstand and magazine projections. Local relay availability, PostgreSQL publication rows, and the global relay registry do not affect its results.

## Architecture

### Data model

No entities or database tables are added. Mercury events are mapped to request-scoped arrays and rendered directly.

### Flow

1. A reader submits a query to `GET /bookshelf?q=...`.
2. `MercuryApiClient` calls `POST /api/publications/search`.
3. `MercuryBookService` accepts kind `30040` events with ordered kind `30041` references, deduplicates replaceable revisions, and prepares book summaries.
4. Opening `GET /bookshelf/{eventId}` fetches the selected index from `GET /api/events/{eventId}`.
5. Chapter event IDs from the index's NKBIP-01 `a` tags are fetched in batches through `POST /api/events/filter`.
6. Results are reordered to match the index. References without a usable event ID fall back to an author-and-kind filter and coordinate matching.
7. Available chapter content is converted with the existing AsciiDoc parser and rendered as one continuous book.

Mercury wraps successful payloads in a top-level `data` property. The client validates and unwraps that envelope for both individual events and event lists.

### Key files

| File | Role |
|---|---|
| `src/BookshelfBundle/Controller/BookshelfController.php` | Search and full-book reader routes |
| `src/Service/Mercury/MercuryApiClient.php` | Typed boundary for Mercury HTTP requests |
| `src/Service/Mercury/MercuryBookService.php` | NKBIP-01 parsing, revision deduplication, and chapter ordering |
| `templates/pages/bookshelf.html.twig` | Search and result inventory |
| `templates/bookshelf/read.html.twig` | Continuous book reader |
| `templates/bookshelf-layout.html.twig` | Bookshelf application shell |
| `assets/styles/04-pages/bookshelf.css` | Flat Bookshelf and reader presentation |

## Configuration

Bookshelf owns its service configuration in `src/BookshelfBundle`:

| File | Responsibility |
|---|---|
| `src/BookshelfBundle/DependencyInjection/Configuration.php` | Defines the `bookshelf.mercury_api_base_url` option |
| `src/BookshelfBundle/DependencyInjection/BookshelfExtension.php` | Applies the default and exposes the processed URL as a container parameter |
| `src/BookshelfBundle/Resources/config/services.yaml` | Binds `$mercuryApiBaseUrl` and registers the Bookshelf/Mercury services |

| Parameter | Default | Description |
|---|---|---|
| `MERCURY_API_BASE_URL` | `https://mercury-relay.imwald.eu` | Mercury REST API origin |

`MERCURY_API_BASE_URL` is an optional host-provided environment override. The
default endpoint and the scalar argument binding no longer live in the
application-wide `config/services.yaml`.

The Mercury relay WebSocket endpoints are not used by this feature. All discovery and content reads go through REST.

## Bundle boundary and remaining host dependencies

The bundle now owns the Bookshelf-specific DI configuration, route resource, and
the migrated catalogue controller. The following dependencies intentionally
remain in the host application and are integration points rather than hidden
bundle configuration:

| Dependency | Current location | Why it remains host-owned |
|---|---|---|
| Mercury and directory service implementations | `src/Service/Mercury/`, `src/Service/Bookshelf/` | They still use the host `Event` entity/repository and are registered from the bundle service resource as a transitional boundary |
| Directory persistence | `App\Entity\Event`, `App\Repository\EventRepository`, `App\Enum\KindsEnum` | Local Nostr event storage is application-specific |
| Reader conversion | `decent-newsroom/asciidoc-html` (`AsciiDocConverter`) | Shared Composer library registered by the bundle |
| User/navigation context | `App\Helper\NavigationBuilderTrait`, `App\Util\NostrKeyUtil` | Controllers currently use the host's authentication and navigation conventions |
| My Books publishing | `App\Service\GenericEventProjector`, `App\Service\Nostr\NostrClient`, `App\Service\Nostr\UserRelayListService` | Persistence, relay selection, and publishing are host infrastructure |
| Templates and UI resources | `templates/`, `translations/`, `assets/` | They extend the host shell, use host Twig components and translation catalogs, and are managed by the host AssetMapper |
| Route and bundle bootstrap | `config/routes.yaml`, `config/bundles.php` | Symfony application bootstrap must import the bundle routes and register the bundle |
| Runtime endpoint override | `MERCURY_API_BASE_URL` | Environment values are supplied by each host; the bundle provides the default and binding |

The application currently requires `decent-newsroom/asciidoc-html` directly.
When `BookshelfBundle` is extracted into its own Composer package, that package
must declare the same library in its own `require` section.

The bundle therefore still depends on the application's `App\` namespace for
service implementations, persistence, Nostr infrastructure, rendering,
authentication/navigation, and UI integration. Removing those dependencies
requires contracts and host adapters; this migration deliberately records them
without changing behavior.

## Limits and failure behaviour

- Search requires at least two characters because Mercury rejects empty searches.
- Search results are capped at 40 books after filtering and deduplication.
- A reader request renders at most 500 chapter references.
- Event-ID filters are split into batches of 100.
- Missing chapters remain visible in the table of contents and reader as unavailable sections.
- Mercury network, HTTP, or payload errors produce a temporary unavailable state rather than falling back to local Nostr discovery.

## Related NIPs / NKBIPs

- [NKBIP-01](../NKBIP/01.md) — publication indexes, ordered `a` tags, and publication content events.
