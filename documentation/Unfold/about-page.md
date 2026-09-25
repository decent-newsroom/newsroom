# Unfold About Page

Every hosted Unfold magazine has an `/about` page with an introduction and two
people sections. Readers can learn who is associated with the publication, while
owners can choose an article for a longer introduction from either publication
administration mount.

## Overview

The introduction uses the owner's selected published kind `30023` article when
it resolves. Without a selection, one direct kind `30023` article on the root
magazine index is treated as the conventional About article, provided it is the
only direct root article. Otherwise the page uses the root index description, or
a short translated sentence naming the magazine when that description is empty.
An unavailable selected article falls back to that default text. The people
sections appear regardless of which introduction is shown.

"Editorial team" contains the pubkeys that signed the root magazine index and
each category index, in root and category order. "Featured writers" contains
authors of published articles referenced by the categories. Each section
deduplicates normalized pubkeys independently; someone can appear in both.
Profile names and images are used when available, with a pubkey fallback when
metadata is missing.

## Administration

The owner enters a kind `30023` coordinate or naddr in the About article field
at `/admin/settings` on the hosted subdomain or `/mag/{mag}/admin/settings` on
the main domain. The article may be outside the magazine. Saving validates that
the resolved event matches the submitted article identity, stores a normalized
coordinate and any naddr relay hints in the coordinate-keyed local publication
settings, and invalidates the site configuration cache. Clearing the field
removes the selection. The existing owner authorization and coordinate-scoped
CSRF protection apply to both mounts.

## Architecture

The root kind `30040` index supplies publication metadata and references.
Its kind `30040` references are categories; direct kind `30023` references are
article candidates, never categories. Category references supply the article
authors for Featured writers. The selected About reference is stored alongside
the theme and footer links in local publication settings, separately from
Nostr-published index events and hosting mappings.

The bundle renders linked article content with the host Markdown converter.
Rendered content caching varies by event ID or content hash so a revised
replaceable article is displayed after its event changes. The default theme
shows the magazine title below the About heading and includes the footer zap
assets. It links to `/about` in navigation and the publication footer, and
the hosted sitemap includes the page. Theme CSS lives in the bundle's
theme `assets`
directory.

## Limits

"Featured writers" here means authors of category articles. It is independent
of the platform-wide featured-writer role. The two sections are deduplicated
separately. If several articles are linked directly by the root index and the
owner has not selected one, none is inferred as About.
