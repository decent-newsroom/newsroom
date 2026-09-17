# Bookshelf publication routing

Kind `30040` publication indexes are classified before the `/mag/{mag}` page is
rendered:

- An index with no `a` or `e` relationships is a library card. Its metadata
  tags can describe a work that is not published on Nostr, including ISBN and
  DOI identifiers.
- An index with an `a` tag referencing kind `30041` is a book with ordered
  chapters.
- An index referencing kind `30040` sections remains a magazine.

Library cards and books redirect from `/mag/{mag}` to
`/bookshelf/book/{book}`. The Bookshelf view shows the title, summary, cover,
metadata tags, and chapter references while omitting structural relationship
tags from the metadata list. Newsstand, magazine administration, and the global
magazine manifest list only section-based magazines. Existing magazine routes
remain unchanged. The `/bookshelf` landing page also lists the locally cached
bookshelf publications, including relationship-free library cards. Entries are
deduplicated by `kind:npub:slug`, with the newest version retained.
Publications authored by users with `ROLE_MUTED` are excluded from this
all-publications list.
