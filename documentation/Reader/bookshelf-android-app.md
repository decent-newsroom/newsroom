# Bookshelf Android Product Page

`/bookshelf` introduces the Bookshelf Android ereader, offers downloads from Zapstore and GitHub,
and keeps web reading available below a small directory-curated selection. It no longer lists
every book and library card cached by the newsroom.

## Page layout

1. Android app introduction and download links.
2. Reading benefits: offline saved books and resume, reading appearance, highlights and text
   search, and local progress without an account with optional Nostr connection/sharing.
3. Up to six book-index reference occurrences from an operator-configured directory, in signed
   tag order, plus a link to the full paginated selection.
4. Existing web catalogue search, including signed-in add/remove-from-My-Books controls.

Downloads are in the main content on all screen sizes. The page uses the existing Bookshelf
navigation without the catalogue API context aside. No app version, store rating, or screenshots
are hard-coded.

## Curated selection configuration

Set `BOOKSHELF_FEATURED_DIRECTORY_NADDR` to a kind-30045 directory naddr in the deployment
environment. It defaults to an empty string: the entire curated section is omitted until set,
without making curated API calls. The product page and web search do not need a configured selection.

Ingest the signed directory event into the local Event store before configuring it. The source is
looked up by exact kind, author pubkey and case-sensitive signed `d` identifier, selecting the newest
revision (lowest event ID for equal timestamps). This feature does not fetch directories from relay
hints or edit the directory. Referenced books need not be stored locally: their kind-30040 metadata
is requested from the existing Mercury book-index API.

The preview takes at most six occurrences **before** requesting metadata. The complete selection is
available at `/bookshelf/featured`, with 50 occurrences per page. Pagination and the landing page's
full-selection link carry the exact directory event ID. If that revision is no longer locally
available, the page reports unavailability instead of mixing revisions.

### Identity and access

- Read signed directory `a` references to kind 30040 and exact `e` references; ignore valid
  coordinate references to other kinds.
- Preserve order and duplicate occurrences. Exact event IDs and pinned address references never
  fall back to a newer coordinate revision. Match both author and identifier for address pins.
- Use only publication-index metadata endpoints, not chapter bodies or catalogue search. Requests
  are bounded to the selected page and repeated lookup identities share requests. Relay hints
  remain reference data and are not network destinations for this page.
- Unpinned addresses with empty/whitespace-only identifiers remain unavailable: the current API
  helper cannot express their exact `d` filter. Do not replace them with author-wide searches.
  Exact IDs and valid version pins can still resolve those editions.
- Only public, empty-content, non-sensitive directories are eligible. Apply author suppression,
  hidden coordinates, deletion tombstones and the current reader's strict mute list to the source
  and independently to book-index API results. Scoped/sensitive target metadata is withheld.
- Responses use `private, no-store` and disable Turbo snapshots because their visibility depends
  on the viewer.

### Unavailable states

A configured but invalid, missing, or inaccessible directory produces a neutral unavailable notice,
never a fallback to the global books list. Metadata API failures are logged and shown as an unavailable
selection; the promo and web search remain independent. Missing, invalid and restricted individual
book references remain explicit rows without leaking restricted titles or covers. An authorized empty
directory has its own empty state.

## Where it lives

Newsroom owns the landing composition through a host controller and Symfony bundle template override.
The installed `decent-newsroom/bookshelf-bundle` package still supplies navigation, layout, catalogue
search and reader services; its files are not edited.

| File | Role |
|---|---|
| `src/Controller/Bookshelf/LandingController.php` | Higher-priority landing route, existing search context and public curated-directory page |
| `src/Bookshelf/FeaturedBookshelf.php` | Configured local directory, ordered/pinned API metadata resolution and access checks |
| `templates/bundles/BookshelfBundle/pages/bookshelf.html.twig` | Host product page extending the bundle layout |
| `templates/partial/_bookshelf_app.html.twig` | Product introduction, benefits and downloads |
| `templates/bookshelf/_landing_search.html.twig` | Web search and existing My Books controls |
| `templates/bookshelf/_featured_entries.html.twig` | Shared compact selection and explicit reference states |
| `templates/bookshelf/featured.html.twig` | Full paginated selection |
| `assets/images/get-it-on-zapstore.svg` | Self-hosted Zapstore badge (no third-party image request) |
| `assets/styles/04-pages/bookshelf.css` | Responsive promo and selection styles alongside existing reader styles |

`bookshelf_landing` matches GET `/bookshelf` ahead of the vendor action. The existing `bookshelf`
route name still generates `/bookshelf`, so navigation and old links reach the product page.
Other routes and consumers of `Organisms:BookList` are unchanged. The host now owns the search markup;
bundle search changes should be checked for parity when updating the dependency.

## Links

- Zapstore: `https://zapstore.dev/apps/eu.decentnewsroom.bookshelf`
- GitHub releases: `https://github.com/decent-newsroom/bookshelf-app/releases`

## Translations

Keys live under `bookshelf.app.*` and `bookshelf.promo.*` in `translations/messages.{en,de,es,fr,it,sl}.yaml`. They are host
keys and are merged with the bundle's own `bookshelf.*` catalogue at runtime, so they do not need to
be added to the package.

## Related protocol

- [NKBIP-04 directories](../NKBIP/04.md) supply the signed ordered selection.
- [NKBIP-01 publication indexes](../NKBIP/01.md) supply book identities and metadata.
