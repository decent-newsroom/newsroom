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

`DecentNewsroom\BookshelfBundle` is a standalone Composer package consumed via
a local path repository at `packages/bookshelf-bundle` (see
`composer.json`'s `repositories` and `require` entries).

| File | Role |
|---|---|
| `packages/bookshelf-bundle/src/Controller/BookshelfController.php` | Search and full-book reader routes |
| `packages/bookshelf-bundle/src/Service/Mercury/MercuryApiClient.php` | Typed boundary for Mercury HTTP requests |
| `packages/bookshelf-bundle/src/Service/Mercury/MercuryBookService.php` | NKBIP-01 parsing, revision deduplication, and chapter ordering |
| `packages/bookshelf-bundle/src/Resources/views/pages/bookshelf.html.twig` | Search and result inventory |
| `packages/bookshelf-bundle/src/Resources/views/bookshelf/read.html.twig` | Continuous book reader |
| `packages/bookshelf-bundle/src/Resources/views/bookshelf-layout.html.twig` | Bookshelf application shell |
| `packages/bookshelf-bundle/src/Resources/translations/messages.{locale}.yaml` | Bookshelf translations for supported locales |
| `assets/styles/04-pages/bookshelf.css` | Flat Bookshelf and reader presentation |

## Configuration

Bookshelf owns its service configuration in `packages/bookshelf-bundle`:

| File | Responsibility |
|---|---|
| `packages/bookshelf-bundle/src/DependencyInjection/Configuration.php` | Defines the `bookshelf.mercury_api_base_url` option |
| `packages/bookshelf-bundle/src/DependencyInjection/BookshelfExtension.php` | Applies the default and exposes the processed URL as a container parameter |
| `packages/bookshelf-bundle/src/Resources/config/services.yaml` | Binds `$mercuryApiBaseUrl` and registers the Bookshelf/Mercury services |

| Parameter | Default | Description |
|---|---|---|
| `MERCURY_API_BASE_URL` | `https://mercury-relay.imwald.eu` | Mercury REST API origin |

`MERCURY_API_BASE_URL` is an optional host-provided environment override. The
default endpoint and the scalar argument binding no longer live in the
application-wide `config/services.yaml`.

The Mercury relay WebSocket endpoints are not used by this feature. All discovery and content reads go through REST.

## Bundle boundary and host integration

`decent-newsroom/bookshelf-bundle` is a self-contained Composer package. It
owns its DI configuration, route resource, Mercury services, catalogue
controller, templates, and translations, and depends only on Symfony
components, `innis/nostr-core`, `swentel/nostr-php`, and
`decent-newsroom/asciidoc-html` — never on the host application's `App\`
namespace directly. Host-specific behaviour is provided through contracts in
`DecentNewsroom\BookshelfBundle\Contract`:

| Contract | Responsibility | Host adapter |
|---|---|---|
| `DirectoryEventStoreInterface` | Look up a user's own stored directory (kind `30045`) events by pubkey and kind | `App\Bookshelf\BookshelfEventStore` (wraps `App\Repository\EventRepository`) |
| `DirectoryEventPublisherInterface` | Persist a signed directory event locally and publish it to the user's write relays | `App\Bookshelf\BookshelfEventPublisher` (wraps `App\Service\GenericEventProjector`, `App\Service\Nostr\NostrClient`, `App\Service\Nostr\UserRelayListService`) |

Both adapters are aliased in the host's `config/services.yaml` under a
"BookshelfBundle host integration" section. `App\Entity\Event` implements the
bundle's `DirectoryEventInterface` (`getDTag()`, `getSlug()`, `getTags()`) so
it can be returned directly from `BookshelfEventStore`.

The bundle's own local navigation (`bookshelf`, `bookshelf_my_books` routes)
is built by the bundle-owned `Navigation\BookshelfNavigationTrait`, used by
its controllers instead of the host's `NavigationBuilderTrait`.

Remaining host-owned integration points:

| Dependency | Current location | Why it remains host-owned |
|---|---|---|
| User identity resolution | `Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey` + Symfony Security | Controllers resolve the authenticated npub via the host's security context |
| UI integration | `assets/styles/04-pages/bookshelf.css`, host Twig shell/components | The stylesheet remains host-managed by AssetMapper; bundle templates extend `app-shell.html.twig` and use host Twig components (`SidebarNav`, `Atoms:PageHeading`, `components/UserMenu.html.twig`) |
| Route and bundle bootstrap | `config/routes/bookshelf.yaml`, `config/bundles.php`, root `composer.json` path repository | Symfony application bootstrap must import the bundle routes, register the bundle, and require the package |
| Runtime endpoint override | `MERCURY_API_BASE_URL` | Environment values are supplied by each host; the bundle provides the default and binding |

The package has its own `composer.json`, `phpunit.xml.dist`, and `tests/`
directory (`packages/bookshelf-bundle/tests`) and can install and run its test
suite independently of the host application.

## Limits and failure behaviour

- Search requires at least two characters because Mercury rejects empty searches.
- Search results are capped at 40 books after filtering and deduplication.
- A reader request renders at most 500 chapter references.
- Event-ID filters are split into batches of 100.
- Missing chapters remain visible in the table of contents and reader as unavailable sections.
- Mercury network, HTTP, or payload errors produce a temporary unavailable state rather than falling back to local Nostr discovery.

## Related NIPs / NKBIPs

- [NKBIP-01](../../../documentation/NKBIP/01.md) — publication indexes, ordered `a` tags, and publication content events.
