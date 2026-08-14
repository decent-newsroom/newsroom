# BookshelfBundle

`decent-newsroom/bookshelf-bundle` is a reusable Symfony bundle providing a
Nostr-based bookshelf and e-reader backed by the [Mercury](https://mercury-relay.imwald.eu)
REST API.

It parses NKBIP-01 publication indexes (kind `30040`) and their ordered
publication content events (kind `30041`), exposes a public search and reader
UI, and lets authenticated users maintain a personal NKBIP-04 "My Books"
directory (kind `30045`).

## Features

- Public `/bookshelf` search over the Mercury catalogue.
- `/bookshelf/{id}` continuous book reader with AsciiDoc chapter rendering.
- Authenticated `/bookshelf/my-books` personal directory and
  `POST /api/bookshelf/directory` publish endpoint.
- Keeps local persistence and relay publishing behind contracts so each host
  can provide its own implementation.

## Documentation

- [Mercury Bookshelf](docs/mercury-bookshelf.md)
- [My Books Directory](docs/bookshelf-my-books-directory.md)

## Requirements

- PHP 8.3 or newer.
- Symfony 7.4 or newer.
- `innis/nostr-core` `^0.3.17`.
- `swentel/nostr-php` `^1.9` (event signature verification).
- `decent-newsroom/asciidoc-html` `^0.1.0` (chapter rendering).

The package uses the `DecentNewsroom\BookshelfBundle` namespace. Its Composer
mapping is package-local and does not require the consuming application's
`App\` classes.

## Installation

Install the package from its Composer repository:

```bash
composer require decent-newsroom/bookshelf-bundle
```

During local development, a Symfony host can consume the package through a
path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/bookshelf-bundle",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "decent-newsroom/bookshelf-bundle": "@dev"
    }
}
```

Register the bundle if Symfony Flex has not done so:

```php
use DecentNewsroom\BookshelfBundle\BookshelfBundle;

return [
    BookshelfBundle::class => ['all' => true],
];
```

Import its routes:

```yaml
# config/routes/bookshelf.yaml
bookshelf_bundle:
    resource: '@BookshelfBundle/Resources/config/routes.yaml'
```

## Configuration

```yaml
# config/packages/bookshelf.yaml (optional)
bookshelf:
    mercury_api_base_url: 'https://mercury-relay.imwald.eu'
```

`MERCURY_API_BASE_URL` may also be set as an environment variable; both are
optional and default to the public Mercury relay.

## Host integration

The bundle is deliberately storage-agnostic. A consuming application must
alias two contracts:

| Contract | Responsibility |
| --- | --- |
| `Contract\DirectoryEventStoreInterface` | Look up a user's own stored directory events (kind `30045`) by pubkey and kind. |
| `Contract\DirectoryEventPublisherInterface` | Persist a signature-verified directory event locally and publish it to the user's write relays. |

```yaml
services:
    DecentNewsroom\BookshelfBundle\Contract\DirectoryEventStoreInterface:
        alias: App\Bookshelf\BookshelfEventStore

    DecentNewsroom\BookshelfBundle\Contract\DirectoryEventPublisherInterface:
        alias: App\Bookshelf\BookshelfEventPublisher
```

Events returned by `DirectoryEventStoreInterface` must implement
`Contract\DirectoryEventInterface` (`getDTag()`, `getSlug()`, `getTags()`).

## Testing

Install the package dependencies and run its package-owned test suite:

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

The package tests exercise `MercuryApiClient` and `MercuryBookService`
directly against a mocked HTTP client, so they do not require a Doctrine
entity or a newsroom database.

## Scope and limitations

This package does not provide:

- a database implementation;
- relay selection, publishing, or NIP-42 authentication;
- application authentication or user management;
- the host application shell (`app-shell.html.twig`) or its sidebar/menu
  components, which templates extend and reference by convention.

Those concerns belong to the consuming Symfony host and are connected through
the package contracts and service configuration.
