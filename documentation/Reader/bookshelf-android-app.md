# Bookshelf Android App Links

The Bookshelf landing page advertises the companion Android app with a Zapstore badge and a link to
the GitHub releases page.

## Where it lives

The Bookshelf page itself is owned by the vendor package `decent-newsroom/bookshelf-bundle`. Rather
than forking that template, the host application appends the section through a Symfony bundle
template override:

| File | Role |
|---|---|
| `templates/bundles/BookshelfBundle/pages/bookshelf.html.twig` | Override that extends `@!Bookshelf/pages/bookshelf.html.twig` and appends to the `body` block |
| `templates/partial/_bookshelf_app.html.twig` | Markup for the download section |
| `assets/images/get-it-on-zapstore.svg` | Self-hosted Zapstore badge (no third-party image request) |
| `assets/styles/04-pages/bookshelf.css` | `.bookshelf-app*` styles |

The `@!Bookshelf` namespace is registered by TwigBundle and always resolves to the bundle's own
template directory, so `{{ parent() }}` renders the untouched vendor page and the override only adds
the extra section underneath. Vendor updates to the Bookshelf page flow through without conflicts.

## Links

- Zapstore: `https://zapstore.dev/apps/eu.decentnewsroom.bookshelf`
- GitHub releases: `https://github.com/decent-newsroom/bookshelf-app/releases`

## Translations

Keys live under `bookshelf.app.*` in `translations/messages.{en,de,es,fr,it,sl}.yaml`. They are host
keys and are merged with the bundle's own `bookshelf.*` catalogue at runtime, so they do not need to
be added to the package.

## Placement rationale

The section is rendered at the bottom of the page body rather than in the right-hand `aside`, because
`.app-shell__context` is hidden below 1200px viewport width — which is exactly where Android users
are.
